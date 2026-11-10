<?php

namespace App\Services;

use App\Jobs\ProcessIssuerBatchItem;
use App\Models\IssuerImportBatch;
use App\Models\IssuerImportItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IssuerBatchIngestionService
{
    public function __construct(private readonly IssuerImportMetadataService $metadata) {}

    /** @param array<int, UploadedFile> $files */
    public function create(int $userId, UploadedFile $manifestFile, array $files): IssuerImportBatch
    {
        if (count($files) < 1 || count($files) > 20) {
            throw new \InvalidArgumentException('Batch wajib memuat 1–20 berkas sumber.');
        }
        $raw = file_get_contents($manifestFile->getRealPath());
        if ($raw === false || strlen($raw) > 1024 * 1024) {
            throw new \InvalidArgumentException('Manifest tidak dapat dibaca atau melebihi 1MB.');
        }
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Manifest JSON tidak valid.');
        }
        if (($manifest['schema'] ?? null) !== 'ii.issuer-batch.v2' || ! is_array($manifest['items'] ?? null)
            || count($manifest['items']) !== count($files)) {
            throw new \InvalidArgumentException('Manifest wajib memakai schema ii.issuer-batch.v2 dan jumlah item harus sama dengan berkas.');
        }
        $uploads = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new \InvalidArgumentException('Salah satu berkas batch tidak valid.');
            }
            $name = basename($file->getClientOriginalName());
            if ($name !== $file->getClientOriginalName() || isset($uploads[$name])) {
                throw new \InvalidArgumentException('Nama berkas batch harus unik dan tidak boleh memuat path.');
            }
            $uploads[$name] = $file;
        }

        $prepared = [];
        foreach ($manifest['items'] as $item) {
            if (! is_array($item) || ! is_string($item['file'] ?? null) || basename($item['file']) !== $item['file']
                || ! isset($uploads[$item['file']]) || ! is_array($item['metadata'] ?? null)) {
                throw new \InvalidArgumentException('Setiap item manifest wajib menunjuk nama berkas upload yang unik dan memiliki metadata.');
            }
            if (isset($prepared[$item['file']])) {
                throw new \InvalidArgumentException('Manifest memuat nama berkas duplikat.');
            }
            $extension = strtolower($uploads[$item['file']]->getClientOriginalExtension());
            if (! in_array($extension, ['csv', 'xbrl', 'xml', 'pdf'], true)) {
                throw new \InvalidArgumentException('Format batch yang didukung: CSV, XBRL/XML, atau PDF.');
            }
            $prepared[$item['file']] = $this->metadata->validate($item['metadata']);
        }

        $batchId = (string) Str::uuid();
        $stagingDirectory = 'ii_issuer_batches/u'.$userId.'/'.$batchId;
        try {
            $batch = DB::transaction(function () use ($userId, $manifestFile, $raw, $prepared, $uploads, $batchId, $stagingDirectory): IssuerImportBatch {
                $batch = IssuerImportBatch::create([
                    'id' => $batchId, 'user_id' => $userId,
                    'original_filename' => basename($manifestFile->getClientOriginalName()),
                    'manifest_sha256' => hash('sha256', $raw), 'status' => 'PENDING', 'total_items' => count($prepared),
                ]);
                foreach ($prepared as $name => $metadata) {
                    $extension = strtolower($uploads[$name]->getClientOriginalExtension());
                    $path = $stagingDirectory.'/'.Str::uuid().'.'.$extension;
                    $stream = fopen($uploads[$name]->getRealPath(), 'rb');
                    if ($stream === false || ! Storage::disk('local')->put($path, $stream)) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        throw new \RuntimeException('Berkas batch tidak dapat disimpan.');
                    }
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    IssuerImportItem::create([
                        'batch_id' => $batch->id, 'original_filename' => $name, 'ticker' => $metadata['ticker'],
                        'staging_path' => $path, 'sha256' => hash_file('sha256', $uploads[$name]->getRealPath()),
                        'status' => 'PENDING', 'metadata' => $metadata,
                    ]);
                }

                return $batch;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->deleteDirectory($stagingDirectory);
            throw $exception;
        }
        $batch->items()->each(fn (IssuerImportItem $item) => ProcessIssuerBatchItem::dispatch($item->id));

        return $batch->fresh();
    }

    public function retryFailed(IssuerImportBatch $batch): int
    {
        $items = $batch->items()->where('status', 'FAILED')->get();
        foreach ($items as $item) {
            $item->update(['status' => 'PENDING', 'error_message' => null, 'processed_at' => null]);
            ProcessIssuerBatchItem::dispatch($item->id);
        }

        return $items->count();
    }

    public function refresh(IssuerImportBatch $batch): void
    {
        $total = $batch->items()->count();
        $succeeded = $batch->items()->where('status', 'IMPORTED')->count();
        $failed = $batch->items()->where('status', 'FAILED')->count();
        $pending = $batch->items()->whereIn('status', ['PENDING', 'PROCESSING'])->count();
        $status = $pending > 0 ? 'PROCESSING' : ($failed === 0 ? 'COMPLETED' : ($succeeded === 0 ? 'FAILED' : 'PARTIAL_FAILED'));
        $batch->update([
            'status' => $status, 'total_items' => $total, 'succeeded_items' => $succeeded, 'failed_items' => $failed,
            'started_at' => $batch->started_at ?? now(), 'completed_at' => $pending === 0 ? now() : null,
            'error_summary' => $failed > 0 ? $batch->items()->where('status', 'FAILED')->pluck('error_message', 'original_filename')->all() : null,
        ]);
    }
}
