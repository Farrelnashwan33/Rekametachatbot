<?php

namespace App\Services\Documents;

use DOMDocument;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

class DocumentParserService
{
    /**
     * Extract text and structural metadata from a local file path.
     *
     * @return array{text: string, page_count: int|null, word_count: int, metadata: array<string, mixed>, structured_summary: array<string, mixed>}
     */
    public function parseFile(string $absolutePath, string $extension): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('File dokumen tidak dapat dibaca oleh server.');
        }

        $ext = strtolower(trim($extension));

        [$rawText, $pageCount, $meta] = match ($ext) {
            'pdf' => $this->parsePdf($absolutePath),
            'docx' => $this->parseDocx($absolutePath),
            'csv' => $this->parseCsv($absolutePath),
            'txt' => $this->parseTxt($absolutePath),
            default => throw new RuntimeException("Format dokumen .{$ext} tidak didukung."),
        };

        $cleanText = $this->normalizeText($rawText);
        $wordCount = $this->countWords($cleanText);
        $structured = $this->buildDeterministicOverview($cleanText, $ext, $pageCount, $wordCount, $meta);

        return [
            'text' => $cleanText,
            'page_count' => $pageCount,
            'word_count' => $wordCount,
            'metadata' => $meta,
            'structured_summary' => $structured,
        ];
    }

    /**
     * @return array{0: string, 1: int|null, 2: array<string, mixed>}
     */
    private function parsePdf(string $path): array
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($path);
            $pages = $pdf->getPages();
            $pageTexts = [];
            foreach ($pages as $idx => $page) {
                $t = trim($page->getText());
                if ($t !== '') {
                    $pageTexts[] = "[Halaman ".($idx + 1)."]\n".$t;
                }
            }

            $text = ! empty($pageTexts) ? implode("\n\n", $pageTexts) : $pdf->getText();
            $details = $pdf->getDetails();

            return [
                $text,
                count($pages) ?: null,
                [
                    'pdf_title' => isset($details['Title']) && is_scalar($details['Title']) ? (string) $details['Title'] : null,
                    'pdf_author' => isset($details['Author']) && is_scalar($details['Author']) ? (string) $details['Author'] : null,
                ],
            ];
        } catch (Throwable $e) {
            throw new RuntimeException('Gagal mengekstrak isi PDF: pastikan file PDF tidak terenkripsi password dan berisi teks yang valid.');
        }
    }

    /**
     * @return array{0: string, 1: int|null, 2: array<string, mixed>}
     */
    private function parseDocx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File DOCX rusak atau bukan arsip OpenXML yang valid.');
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false || trim($xmlContent) === '') {
            throw new RuntimeException('File DOCX tidak memiliki konten dokumen utama (word/document.xml).');
        }

        // Replace paragraph, line-break, and table cell boundaries before stripping XML
        $prepared = str_replace(
            ['</w:p>', '<w:br/>', '<w:br />', '<w:cr/>', '</w:tr>', '</w:tc>', '<w:tab/>'],
            ["\n", "\n", "\n", "\n", "\n", "\t", "\t"],
            $xmlContent
        );

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($prepared, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $text = $loaded ? ($dom->textContent ?: '') : strip_tags($prepared);
        $approxPages = max(1, (int) ceil($this->countWords($text) / 350));

        return [$text, $approxPages, ['format' => 'Word OpenXML']];
    }

    /**
     * @return array{0: string, 1: int|null, 2: array<string, mixed>}
     */
    private function parseTxt(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Gagal membaca file teks.');
        }

        $encoding = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true) ?: 'UTF-8';
        $utf8 = $encoding === 'UTF-8' ? $raw : mb_convert_encoding($raw, 'UTF-8', $encoding);

        return [$utf8, null, ['encoding' => $encoding]];
    }

    /**
     * @return array{0: string, 1: int|null, 2: array<string, mixed>}
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Gagal membuka file CSV.');
        }

        $rows = [];
        $headers = [];
        $rowCount = 0;

        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($data === [null] || empty(array_filter($data, fn ($v) => $v !== null && trim((string) $v) !== ''))) {
                continue;
            }
            $cleanRow = array_map(fn ($v) => trim((string) $v), $data);
            if ($rowCount === 0) {
                $headers = $cleanRow;
            } else {
                $rows[] = $cleanRow;
            }
            $rowCount++;
            if ($rowCount > 2500) {
                break;
            }
        }
        fclose($handle);

        $lines = [];
        if (! empty($headers)) {
            $lines[] = 'Kolom CSV: '.implode(' | ', $headers);
            $lines[] = str_repeat('-', min(80, strlen($lines[0])));
            foreach ($rows as $idx => $row) {
                $pairs = [];
                foreach ($headers as $colIdx => $colName) {
                    $val = $row[$colIdx] ?? '';
                    if ($val !== '') {
                        $pairs[] = "{$colName}: {$val}";
                    }
                }
                $lines[] = 'Baris '.($idx + 1).' -> '.implode('; ', $pairs);
            }
        }

        return [
            implode("\n", $lines),
            null,
            [
                'columns' => $headers,
                'data_rows' => count($rows),
            ],
        ];
    }

    private function normalizeText(string $text): string
    {
        $utf8 = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $utf8 = str_replace(["\r\n", "\r"], "\n", $utf8);
        $utf8 = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $utf8) ?? $utf8;
        $utf8 = preg_replace("/[ \t]+/", ' ', $utf8) ?? $utf8;
        $utf8 = preg_replace("/\n{3,}/", "\n\n", $utf8) ?? $utf8;

        return trim($utf8);
    }

    private function countWords(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function buildDeterministicOverview(string $text, string $ext, ?int $pageCount, int $wordCount, array $meta): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        $headings = [];
        foreach ($lines as $line) {
            if (mb_strlen($line) >= 3 && mb_strlen($line) <= 75 && ! str_ends_with($line, '.')) {
                if (preg_match('/^(\[Halaman\s+\d+\]|(?:\d+\.|[A-Z][A-Z\s\/&-]{3,}|#{1,3}\s+.+))$/u', $line)) {
                    $headings[] = $line;
                }
            }
            if (count($headings) >= 10) {
                break;
            }
        }

        $previewExcerpt = mb_substr($text, 0, 500);

        return [
            'format' => strtoupper($ext),
            'page_count' => $pageCount,
            'word_count' => $wordCount,
            'detected_sections' => array_values(array_unique($headings)),
            'excerpt' => $previewExcerpt,
            'csv_columns' => $meta['columns'] ?? null,
            'csv_rows' => $meta['data_rows'] ?? null,
            'ai_summary' => null,
        ];
    }
}

