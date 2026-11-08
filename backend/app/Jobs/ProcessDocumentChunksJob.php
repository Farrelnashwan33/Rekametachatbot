<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Documents\DocumentChunkerService;
use App\Services\Documents\DocumentParserService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDocumentChunksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $documentId) {}

    public function handle(DocumentParserService $parser, DocumentChunkerService $chunker): void
    {
        $document = Document::find($this->documentId);
        if (! $document) {
            return;
        }

        $tempFile = null;
        try {
            $disk = Storage::disk($document->disk);
            if ($document->disk === 'local') {
                $localPath = $disk->path($document->storage_path);
            } else {
                $stream = $disk->readStream($document->storage_path);
                $tempFile = tempnam(sys_get_temp_dir(), 'reka_doc_');
                if ($tempFile === false || $stream === null) {
                    throw new \RuntimeException('Gagal mengunduh file sementara dari object storage.');
                }
                file_put_contents($tempFile, stream_get_contents($stream));
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $localPath = $tempFile;
            }

            $parsed = $parser->parseFile($localPath, $document->extension);
            $document->update([
                'extracted_text' => $parsed['text'],
                'page_count' => $parsed['page_count'],
                'word_count' => $parsed['word_count'],
                'metadata' => array_merge($document->metadata ?? [], $parsed['metadata']),
                'structured_summary' => $parsed['structured_summary'],
                'status' => 'ready',
                'error_message' => null,
            ]);

            $chunker->chunkAndStore($document, $parsed['text']);
        } catch (Throwable $e) {
            $document->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
        } finally {
            if ($tempFile && is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}

