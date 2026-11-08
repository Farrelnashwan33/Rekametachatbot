<?php

namespace Tests\Feature;

use App\Models\AiProviderSetting;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RekametachatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
    }

    public function test_auth_lifecycle_register_login_reset_and_logout(): void
    {
        $reg = $this->postJson('/api/auth/register', [
            'name' => 'Rina Pratama',
            'email' => 'rina@example.com',
            'password' => 'SecurePass123!',
        ]);
        $reg->assertStatus(201)->assertJsonStructure(['token', 'user', 'ai_status']);

        $token = $reg->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'rina@example.com');

        // Forgot & Reset password flow
        $forgot = $this->postJson('/api/auth/forgot-password', ['email' => 'rina@example.com']);
        $forgot->assertOk();
        $resetToken = $forgot->json('local_dev_reset_token');
        $this->assertNotEmpty($resetToken);

        $this->postJson('/api/auth/reset-password', [
            'email' => 'rina@example.com',
            'token' => $resetToken,
            'password' => 'UpdatedPass456!',
        ])->assertOk();

        // Login with new password
        $login = $this->postJson('/api/auth/login', [
            'email' => 'rina@example.com',
            'password' => 'UpdatedPass456!',
        ]);
        $login->assertOk();
        $newToken = $login->json('token');

        $this->withHeader('Authorization', "Bearer {$newToken}")
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_unconfigured_ai_provider_returns_honest_503_and_never_fakes_answer(): void
    {
        $user = User::factory()->create();
        $conv = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversations', ['title' => 'Tes kejujuran AI'])
            ->assertStatus(201)
            ->json('conversation');

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/conversations/{$conv['uuid']}/messages", [
                'content' => 'Halo, apakah AI sudah aktif?',
                'stream' => false,
            ]);

        $res->assertStatus(503)
            ->assertJsonPath('error_code', 'AI_PROVIDER_NOT_CONFIGURED');
    }

    public function test_admin_provider_key_encryption_chat_memory_edit_and_regenerate(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $regularUser = User::factory()->create();

        // Regular user cannot access admin routes
        $this->actingAs($regularUser, 'sanctum')
            ->getJson('/api/admin/overview')
            ->assertStatus(403);

        // Admin configures OpenAI API key encrypted at rest
        $openai = AiProviderSetting::where('provider_key', 'openai')->firstOrFail();
        $updateRes = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/providers/{$openai->id}", [
                'api_key' => 'sk-test-secret-key-987654321',
                'is_enabled' => true,
                'is_default' => true,
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('provider.has_api_key', true)
            ->assertJsonPath('provider.masked_api_key', 'sk-t...4321');

        $this->assertNotEquals('sk-test-secret-key-987654321', $openai->fresh()->getRawOriginal('api_key_encrypted'));

        // Mock upstream OpenAI completion
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-1',
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Salam kenal Budi! Saya siap membantu materi Informatika secara ringkas.']]],
                    'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 22, 'total_tokens' => 82],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-2',
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Berikut penjelasan ulang algoritma graf dengan contoh kode Python.']]],
                    'usage' => ['prompt_tokens' => 70, 'completion_tokens' => 28, 'total_tokens' => 98],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-3',
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban setelah pesan diedit oleh pengguna.']]],
                    'usage' => ['prompt_tokens' => 65, 'completion_tokens' => 18, 'total_tokens' => 83],
                ], 200),
        ]);

        $conv = $this->actingAs($regularUser, 'sanctum')
            ->postJson('/api/conversations', ['assistant_mode' => 'academic'])
            ->assertStatus(201)
            ->json('conversation');

        $chatRes = $this->actingAs($regularUser, 'sanctum')
            ->postJson("/api/conversations/{$conv['uuid']}/messages", [
                'content' => 'Halo, nama saya Budi dan saya mahasiswa Teknik Informatika. Saya lebih suka penjelasan ringkas.',
                'stream' => false,
            ]);

        $chatRes->assertOk()
            ->assertJsonPath('assistant_message.role', 'assistant');

        // Consent-based memory candidates created as unconfirmed by default
        $suggestions = $chatRes->json('memory_suggestions');
        $this->assertNotEmpty($suggestions);
        $firstMemId = $suggestions[0]['id'];
        $this->assertFalse($suggestions[0]['is_confirmed']);

        // User approves memory
        $this->actingAs($regularUser, 'sanctum')
            ->patchJson("/api/memories/{$firstMemId}", ['is_confirmed' => true])
            ->assertOk()
            ->assertJsonPath('memory.is_confirmed', true);

        // Regenerate assistant message
        $assistantUuid = $chatRes->json('assistant_message.uuid');
        $regenRes = $this->actingAs($regularUser, 'sanctum')
            ->postJson("/api/messages/{$assistantUuid}/regenerate", ['stream' => false]);
        $regenRes->assertOk()
            ->assertJsonPath('assistant_message.content', 'Berikut penjelasan ulang algoritma graf dengan contoh kode Python.');

        // Edit user message
        $userMsgUuid = $chatRes->json('user_message.uuid');
        $editRes = $this->actingAs($regularUser, 'sanctum')
            ->putJson("/api/messages/{$userMsgUuid}", [
                'content' => 'Jelaskan kompleksitas waktu algoritma Dijkstra.',
                'stream' => false,
            ]);
        $editRes->assertOk()
            ->assertJsonPath('user_message.is_edited', true)
            ->assertJsonPath('assistant_message.content', 'Jawaban setelah pesan diedit oleh pengguna.');

        // Admin overview shows counts & tokens, NEVER private message content
        $overview = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/overview')
            ->assertOk();
        $this->assertGreaterThan(0, $overview->json('metrics.tokens_today'));
        $this->assertStringNotContainsString('Dijkstra', json_encode($overview->json()));
    }

    public function test_document_upload_parsing_authorization_and_cv_pdf_export(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        // Upload CSV document
        $csvContent = "Bulan,Pendapatan,Biaya\nJanuari,15000000,8000000\nFebruari,18500000,8200000\n";
        $csvFile = UploadedFile::fake()->createWithContent('laporan-keuangan.csv', $csvContent);

        $uploadRes = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/documents', ['file' => $csvFile]);

        $uploadRes->assertStatus(201)
            ->assertJsonPath('document.status', 'ready')
            ->assertJsonPath('document.extension', 'csv');

        $docUuid = $uploadRes->json('document.uuid');

        // Stranger cannot view or delete owner's document
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/documents/{$docUuid}")
            ->assertStatus(404);

        // Generate a real PDF CV and import it into CV Intelligence
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<html><body><h1>Andi Wijaya</h1><p>andi.wijaya@example.com | +62 812 3456 7890</p><h2>Summary</h2><p>Backend Engineer berpengalaman 4 tahun membangun layanan API skalabel.</p><h2>Experience</h2><p>Senior Backend Engineer</p><p>PT Nusantara Digital</p><p>- Meningkatkan throughput API sebesar 45% untuk 120+ klien korporasi</p><h2>Education</h2><p>S1 Ilmu Komputer Universitas Indonesia</p><h2>Skills</h2><p>PHP, Laravel, PostgreSQL, TypeScript, SvelteKit, Docker, Redis</p></body></html>');
        $dompdf->render();
        $pdfBinary = $dompdf->output();

        $cvPdfFile = UploadedFile::fake()->createWithContent('cv-andi-wijaya.pdf', $pdfBinary);

        $importRes = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/cvs/import', ['file' => $cvPdfFile]);

        $importRes->assertStatus(201)
            ->assertJsonPath('cv.email', 'andi.wijaya@example.com');

        $cvUuid = $importRes->json('cv.uuid');
        $this->assertGreaterThan(50, $importRes->json('cv.ats_score'));

        // Export CV back to PDF across templates
        $exportRes = $this->actingAs($owner, 'sanctum')
            ->get("/api/cvs/{$cvUuid}/export-pdf?template=modern_minimal");

        $exportRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $exportRes->getContent());
    }
}

