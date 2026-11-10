<?php

namespace App\Jobs;

use App\Models\IssuerImportItem;
use App\Services\IssuerBatchIngestionService;
use App\Services\IssuerFundamentalImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProcessIssuerBatchItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $itemId) {}

    public function handle(IssuerFundamentalImportService $importer, IssuerBatchIngestionService $batches): void
    {
        $item = IssuerImportItem::with('batch')->findOrFail($this->itemId);
        $metadata = $item->metadata;
        $path = $item->staging_path;
        $original = $item->original_filename;
        $item->update(['status' => 'PROCESSING', 'attempts' => $item->attempts + 1, 'error_message' => null]);
        try {
            if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                throw new \RuntimeException('Berkas staging batch tidak tersedia.');
            }
            $file = new UploadedFile(Storage::disk('local')->path($path), $original, null, null, true);
            $document = $importer->import((int) $item->batch->user_id, $metadata, $file);
            $item->update(['status' => 'IMPORTED', 'document_id' => $document->id, 'processed_at' => now()]);
            Storage::disk('local')->delete($path);
        } catch (\Throwable $exception) {
            report($exception);
            $item->update(['status' => 'FAILED', 'error_message' => mb_substr($exception->getMessage(), 0, 1000), 'processed_at' => now()]);
        } finally {
            $batches->refresh($item->batch->fresh());
        }
    }
}
