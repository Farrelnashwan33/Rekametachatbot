<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Support\Collection;

class MemoryExtractorService
{
    /**
     * Extract explicit personal facts/preferences shared by the user.
     * Creates confirmed memories if memory_auto_save is enabled, otherwise creates pending consent items.
     *
     * @return Collection<int, UserMemory>
     */
    public function extractCandidatesFromMessage(User $user, Conversation $conversation, string $userText): Collection
    {
        $pref = $user->preference;
        if ($pref && ! $pref->memory_enabled) {
            return collect();
        }

        $autoConfirm = (bool) ($pref?->memory_auto_save ?? false);
        $candidates = [];

        $patterns = [
            [
                'regex' => '/\b(?:nama saya|panggil saya|my name is|call me)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s.\'-]{1,45})/iu',
                'category' => 'profile',
                'key' => 'nama_panggilan',
                'importance' => 5,
                'format' => fn (string $m) => 'Nama pengguna: '.trim($m, " .,\n\r\t"),
            ],
            [
                'regex' => '/\b(?:saya bekerja sebagai|profesi saya|pekerjaan saya|i work as(?: an?)?|i am an?)\s+([^.,;\n]{3,70})/iu',
                'category' => 'profile',
                'key' => 'pekerjaan_peran',
                'importance' => 4,
                'format' => fn (string $m) => 'Peran/pekerjaan: '.trim($m),
            ],
            [
                'regex' => '/\b(?:saya kuliah jurusan|saya mahasiswa|jurusan saya|i study|majoring in)\s+([^.,;\n]{3,70})/iu',
                'category' => 'academic',
                'key' => 'latar_akademik',
                'importance' => 4,
                'format' => fn (string $m) => 'Latar akademik: '.trim($m),
            ],
            [
                'regex' => '/\b(?:saya lebih suka|tolong selalu jawab|preferensi saya|i prefer|please always answer in)\s+([^.\n]{4,100})/iu',
                'category' => 'preference',
                'key' => 'gaya_jawaban',
                'importance' => 5,
                'format' => fn (string $m) => 'Preferensi komunikasi: '.trim($m),
            ],
            [
                'regex' => '/\b(?:stack utama saya|bahasa pemrograman favorit saya|my tech stack is|i mainly code in)\s+([^.\n]{3,80})/iu',
                'category' => 'skill',
                'key' => 'tech_stack',
                'importance' => 4,
                'format' => fn (string $m) => 'Fokus teknologi: '.trim($m),
            ],
            [
                'regex' => '/\b(?:target saya saat ini|tujuan saya adalah|my current goal is)\s+([^.\n]{4,100})/iu',
                'category' => 'goal',
                'key' => 'tujuan_utama',
                'importance' => 4,
                'format' => fn (string $m) => 'Tujuan pengguna: '.trim($m),
            ],
        ];

        foreach ($patterns as $rule) {
            if (preg_match($rule['regex'], $userText, $matches)) {
                $val = $rule['format']($matches[1]);
                $candidates[] = [
                    'category' => $rule['category'],
                    'memory_key' => $rule['key'],
                    'memory_value' => mb_substr($val, 0, 300),
                    'importance' => $rule['importance'],
                ];
            }
        }

        $saved = collect();
        foreach ($candidates as $item) {
            $existing = UserMemory::where('user_id', $user->id)
                ->where('memory_key', $item['memory_key'])
                ->first();

            if ($existing) {
                if ($existing->memory_value !== $item['memory_value']) {
                    $existing->update([
                        'memory_value' => $item['memory_value'],
                        'source_conversation_id' => $conversation->id,
                        'is_confirmed' => $autoConfirm ? true : $existing->is_confirmed,
                    ]);
                    $saved->push($existing->fresh());
                }
                continue;
            }

            $created = UserMemory::create([
                'user_id' => $user->id,
                'source_conversation_id' => $conversation->id,
                'category' => $item['category'],
                'memory_key' => $item['memory_key'],
                'memory_value' => $item['memory_value'],
                'importance' => $item['importance'],
                'is_confirmed' => $autoConfirm,
                'is_active' => true,
            ]);
            $saved->push($created);
        }

        return $saved;
    }

    /**
     * Retrieve confirmed, active memories relevant to the current prompt.
     *
     * @return Collection<int, UserMemory>
     */
    public function retrieveRelevantMemories(User $user, string $query, int $limit = 12): Collection
    {
        $pref = $user->preference;
        if ($pref && ! $pref->memory_enabled) {
            return collect();
        }

        $memories = UserMemory::where('user_id', $user->id)
            ->where('is_confirmed', true)
            ->where('is_active', true)
            ->orderByDesc('importance')
            ->orderByDesc('updated_at')
            ->get();

        if ($memories->isEmpty()) {
            return collect();
        }

        $queryLower = mb_strtolower($query);
        $terms = array_filter(preg_split('/[^\p{L}\p{N}_]+/u', $queryLower) ?: [], fn ($w) => mb_strlen($w) >= 3);

        $ranked = $memories->map(function (UserMemory $mem) use ($terms) {
            $score = (float) $mem->importance;
            $haystack = mb_strtolower($mem->memory_key.' '.$mem->memory_value.' '.$mem->category);
            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 2.5;
                }
            }
            if (in_array($mem->category, ['profile', 'preference'], true)) {
                $score += 1.5;
            }

            return ['memory' => $mem, 'score' => $score];
        })
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('memory')
            ->values();

        if ($ranked->isNotEmpty()) {
            UserMemory::whereIn('id', $ranked->pluck('id')->all())->update(['last_used_at' => now()]);
        }

        return $ranked;
    }
}

