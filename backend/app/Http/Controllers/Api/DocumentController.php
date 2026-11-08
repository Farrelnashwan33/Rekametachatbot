<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AIProviderException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentChunksJob;
use App\Models\Document;
use App\Models\User;
use App\Services\AI\AIProviderManager;
use App\Services\Documents\DocumentChunkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documents = Document::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'documents' => $documents,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $maxKb = (int) config('rekametachat.max_document_upload_kb', 10240);

        $validated = $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}", 'mimes:pdf,docx,txt,csv'],
            'title' => ['nullable', 'string', 'max:180'],
        ]);

        $uploaded = $request->file('file');
        $extension = strtolower($uploaded->getClientOriginalExtension() ?: '');
        if (! in_array($extension, ['pdf', 'docx', 'txt', 'csv'], true)) {
            return response()->json([
                'message' => 'Format file harus berupa PDF, DOCX, TXT, atau CSV.',
            ], 422);
        }

        $diskName = (string) config('filesystems.document_disk', 'local');
        if ($diskName === 'r2' && empty(config('filesystems.disks.r2.key'))) {
            $diskName = 'local';
        }

        $uuid = (string) Str::uuid();
        $safeFilename = $uuid.'.'.$extension;
        $storagePath = "documents/{$user->id}/{$safeFilename}";

        $rawBytes = file_get_contents($uploaded->getRealPath());
        if ($rawBytes === false) {
            return response()->json(['message' => 'Gagal membaca file unggahan.'], 422);
        }

        Storage::disk($diskName)->put($storagePath, $rawBytes);

        $originalName = $uploaded->getClientOriginalName();
        $defaultTitle = pathinfo($originalName, PATHINFO_FILENAME) ?: 'Dokumen';

        $document = Document::create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'title' => ! empty($validated['title']) ? trim($validated['title']) : mb_substr($defaultTitle, 0, 180),
            'original_filename' => mb_substr($originalName, 0, 250),
            'mime_type' => $uploaded->getMimeType() ?: 'application/octet-stream',
            'extension' => $extension,
            'disk' => $diskName,
            'storage_path' => $storagePath,
            'file_size' => $uploaded->getSize() ?: strlen($rawBytes),
            'file_hash' => hash('sha256', $rawBytes),
            'status' => 'processing',
        ]);

        if ($document->file_size > 3 * 1024 * 1024 && config('queue.default') !== 'sync' && config('app.env') !== 'local') {
            ProcessDocumentChunksJob::dispatch($document->id);
        } else {
            ProcessDocumentChunksJob::dispatchSync($document->id);
        }

        return response()->json([
            'message' => 'Dokumen berhasil diunggah dan diindeks.',
            'document' => $document->fresh(),
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $document = Document::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        return response()->json([
            'document' => $document,
            'chunks' => $document->chunks()->limit(30)->get(['id', 'chunk_index', 'heading', 'content', 'token_estimate']),
        ]);
    }

    public function summarize(Request $request, string $uuid, AIProviderManager $aiManager, DocumentChunkerService $chunker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $document = Document::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($document->status !== 'ready') {
            return response()->json([
                'message' => 'Dokumen belum selesai diproses atau mengalami kendala ekstraksi.',
            ], 422);
        }

        $focus = trim((string) $request->input('focus', 'Buat ringkasan eksekutif terstruktur, poin utama, data penting, dan kesimpulan.'));
        $chunks = $chunker->retrieveRelevantChunks($user->id, [$document->id], $focus, 8);
        $contextText = $chunks->map(fn ($c) => "[Bagian #".($c->chunk_index + 1)."]\n".$c->content)->implode("\n\n");

        try {
            $result = $aiManager->completeForUser(
                user: $user,
                messages: [
                    [
                        'role' => 'system',
                        'content' => 'Anda adalah analis dokumen Rekametachat. Buat rangkuman terstruktur dalam Markdown berdasarkan teks di dalam <untrusted_reference_context>. Jangan mengarang informasi di luar isi dokumen. Hindari karakter em dash.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Instruksi fokus: {$focus}\n\nJudul Dokumen: {$document->title}\n<untrusted_reference_context>\n{$contextText}\n</untrusted_reference_context>",
                    ],
                ],
                feature: 'document_summary',
                options: ['temperature' => 0.3]
            );
        } catch (AIProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'structured_summary' => $document->structured_summary,
            ], $e->httpStatus);
        }

        $summaryData = $document->structured_summary ?? [];
        $summaryData['ai_summary'] = $result['content'];
        $summaryData['summarized_at'] = now()->toIso8601String();
        $document->update(['structured_summary' => $summaryData]);

        return response()->json([
            'message' => 'Rangkuman AI berhasil dibuat.',
            'ai_summary' => $result['content'],
            'document' => $document->fresh(),
        ]);
    }

    public function compare(Request $request, AIProviderManager $aiManager, DocumentChunkerService $chunker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'document_uuids' => ['required', 'array', 'min:2', 'max:4'],
            'document_uuids.*' => ['string', 'uuid'],
            'prompt' => ['nullable', 'string', 'max:1500'],
        ]);

        $documents = Document::where('user_id', $user->id)
            ->whereIn('uuid', $validated['document_uuids'])
            ->where('status', 'ready')
            ->get();

        if ($documents->count() < 2) {
            return response()->json([
                'message' => 'Pilih minimal 2 dokumen aktif milik Anda untuk dibandingkan.',
            ], 422);
        }

        $focusPrompt = trim((string) ($validated['prompt'] ?? 'Bandingkan persamaan utama, perbedaan kunci, angka/data penting, dan kesimpulan antar dokumen ini.'));

        $blocks = [];
        foreach ($documents as $doc) {
            $docChunks = $chunker->retrieveRelevantChunks($user->id, [$doc->id], $focusPrompt, 4);
            $excerpt = $docChunks->map(fn ($c) => $c->content)->implode("\n\n");
            $blocks[] = "=== DOKUMEN: {$doc->title} ({$doc->original_filename}) ===\n{$excerpt}";
        }

        try {
            $result = $aiManager->completeForUser(
                user: $user,
                messages: [
                    [
                        'role' => 'system',
                        'content' => 'Anda membandingkan beberapa dokumen milik pengguna secara objektif. Sajikan tabel perbandingan Markdown, persamaan, perbedaan, dan temuan spesifik berdasarkan <untrusted_reference_context>. Jangan mengarang data. Hindari karakter em dash.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Fokus Perbandingan: {$focusPrompt}\n\n<untrusted_reference_context>\n".implode("\n\n", $blocks)."\n</untrusted_reference_context>",
                    ],
                ],
                feature: 'document_compare',
                options: ['temperature' => 0.3]
            );
        } catch (AIProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->httpStatus);
        }

        return response()->json([
            'comparison' => $result['content'],
            'documents' => $documents->map->only(['id', 'uuid', 'title', 'extension', 'word_count']),
            'model' => $result['model'],
            'provider_key' => $result['provider_key'],
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $document = Document::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            Storage::disk($document->disk)->delete($document->storage_path);
        } catch (\Throwable) {
            // Proceed even if storage file was already cleaned
        }

        $document->chunks()->delete();
        $document->delete();

        return response()->json([
            'message' => 'Dokumen berhasil dihapus.',
        ]);
    }
}

