<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

class DocumentChunkerService
{
    private const STOPWORDS = [
        'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'pada', 'dengan', 'adalah',
        'atau', 'dalam', 'akan', 'oleh', 'tidak', 'bisa', 'dapat', 'sebagai', 'juga', 'ada',
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'are', 'was', 'were', 'have',
        'has', 'had', 'not', 'but', 'what', 'when', 'where', 'which', 'who', 'how', 'can',
    ];

    public function chunkAndStore(Document $document, string $fullText): int
    {
        $chunkSize = (int) config('rekametachat.context.chunk_size_chars', 1200);
        $overlap = (int) config('rekametachat.context.chunk_overlap_chars', 200);

        $document->chunks()->delete();

        $trimmed = trim($fullText);
        if ($trimmed === '') {
            $document->update(['chunk_count' => 0]);

            return 0;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed) ?: [$trimmed];
        $chunks = [];
        $current = '';
        $currentHeading = null;

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            if (mb_strlen($para) <= 80 && ! str_ends_with($para, '.')) {
                $currentHeading = $para;
            }

            if (mb_strlen($current) + mb_strlen($para) + 2 <= $chunkSize) {
                $current = $current === '' ? $para : $current."\n\n".$para;
                continue;
            }

            if ($current !== '') {
                $chunks[] = ['heading' => $currentHeading, 'content' => $current];
                $tail = mb_substr($current, max(0, mb_strlen($current) - $overlap));
                $current = trim($tail)."\n\n".$para;
            } else {
                $offset = 0;
                $len = mb_strlen($para);
                while ($offset < $len) {
                    $slice = mb_substr($para, $offset, $chunkSize);
                    $chunks[] = ['heading' => $currentHeading, 'content' => $slice];
                    if ($offset + $chunkSize >= $len) {
                        break;
                    }
                    $offset += max(1, $chunkSize - $overlap);
                }
                $current = '';
            }
        }

        if (trim($current) !== '') {
            $chunks[] = ['heading' => $currentHeading, 'content' => trim($current)];
        }

        $now = now();
        $rows = [];
        foreach ($chunks as $index => $chunkData) {
            $content = $chunkData['content'];
            $rows[] = [
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'chunk_index' => $index,
                'heading' => $chunkData['heading'] ? mb_substr($chunkData['heading'], 0, 250) : null,
                'content' => $content,
                'token_estimate' => max(1, (int) ceil(mb_strlen($content) / 4)),
                'keywords' => json_encode($this->extractKeywords($content)),
                'metadata' => json_encode(['char_length' => mb_strlen($content)]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $batch) {
            DocumentChunk::insert($batch);
        }

        $count = count($rows);
        $document->update(['chunk_count' => $count]);

        return $count;
    }

    /**
     * Retrieve top relevant chunks strictly owned by $userId across $documentIds.
     *
     * @param  array<int, int>  $documentIds
     * @return Collection<int, DocumentChunk>
     */
    public function retrieveRelevantChunks(int $userId, array $documentIds, string $query, int $limit = 6): Collection
    {
        if (empty($documentIds)) {
            return collect();
        }

        $allChunks = DocumentChunk::with('document:id,uuid,title,original_filename,extension')
            ->where('user_id', $userId)
            ->whereIn('document_id', $documentIds)
            ->orderBy('document_id')
            ->orderBy('chunk_index')
            ->get();

        if ($allChunks->isEmpty()) {
            return collect();
        }

        $queryTerms = $this->extractKeywords($query, 20);
        if (empty($queryTerms)) {
            return $allChunks->take($limit)->values();
        }

        $scored = $allChunks->map(function (DocumentChunk $chunk) use ($queryTerms) {
            $haystack = mb_strtolower($chunk->content.' '.($chunk->heading ?? ''));
            $chunkKeywords = is_array($chunk->keywords) ? $chunk->keywords : [];
            $score = 0.0;

            foreach ($queryTerms as $term) {
                if (in_array($term, $chunkKeywords, true)) {
                    $score += 3.0;
                }
                $occurrences = substr_count($haystack, $term);
                if ($occurrences > 0) {
                    $score += min(4.0, 1.0 + log($occurrences + 1));
                }
            }

            if ($chunk->chunk_index === 0) {
                $score += 0.4; // slight boost to intro/header metadata
            }

            return ['chunk' => $chunk, 'score' => $score];
        });

        $positive = $scored->filter(fn (array $item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('chunk')
            ->values();

        if ($positive->isEmpty()) {
            return $allChunks->take($limit)->values();
        }

        return $positive;
    }

    /**
     * @return array<int, string>
     */
    public function extractKeywords(string $text, int $maxKeywords = 25): array
    {
        $normalized = mb_strtolower($text);
        $words = preg_split('/[^\p{L}\p{N}_]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $freq = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }

        arsort($freq);

        return array_slice(array_keys($freq), 0, $maxKeywords);
    }
}

