<?php

namespace App\Console\Commands;

use App\Services\IdxEdgeFinancialEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only pilot for up to three IDX tickers, one API request per ticker.
 * Artifacts are private evidence, never imported as verified issuer metrics.
 */
class IIIdxEdgePilot extends Command
{
    protected $signature = 'ii:idxedge:pilot {--ticker= : One issuer, otherwise configured pilot watchlist}';
    protected $description = 'Fetch and privately archive IDX Edge PRO financial responses for review';

    public function handle(IdxEdgeFinancialEvidenceService $connector): int
    {
        if (trim((string) config('ii.idxedge.key', '')) === '') {
            $this->error('II_IDXEDGE_KEY_CONFIGURED=NO');

            return self::FAILURE;
        }
        $one = strtoupper(trim((string) $this->option('ticker')));
        $codes = $one === '' ? explode(',', (string) config('ii.idxedge.tickers', '')) : [$one];
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($value) => strtoupper(trim((string) $value)), $codes
        ))));
        $max = min(3, max(1, (int) config('ii.idxedge.max_tickers_per_run', 3)));
        if ($codes === [] || count($codes) > $max) {
            $this->error('II_IDXEDGE_INVALID_WATCHLIST=YES;MAX='.$max);

            return self::FAILURE;
        }
        foreach ($codes as $code) {
            if (! preg_match('/^[A-Z0-9]{4,6}$/D', $code) || str_starts_with($code, 'DEMO')) {
                $this->error('II_IDXEDGE_INVALID_TICKER=YES');
                return self::FAILURE;
            }
        }

        $saved = 0;
        $failed = 0;
        foreach ($codes as $code) {
            try {
                $result = $connector->fetch($code);
                $hash = $result['source_sha256'];
                $prefix = 'ii_idxedge_review/'.$code.'/'.$hash;
                $rawPath = $prefix.'.raw';
                $manifestPath = $prefix.'.json';
                $raw = $result['raw_response_body'];
                unset($result['raw_response_body']);

                // The checksum must describe the *stored original HTTP bytes*,
                // not the reserialized JSON review manifest.
                if (! hash_equals($hash, hash('sha256', $raw))) {
                    throw new \RuntimeException('IDXEDGE_SOURCE_HASH_MISMATCH');
                }
                $disk = Storage::disk('local');
                if (! $disk->exists($rawPath)) {
                    if (! $disk->put($rawPath, $raw)) {
                        throw new \RuntimeException('IDXEDGE_RAW_ARCHIVE_FAILED');
                    }
                }
                if (! hash_equals($hash, hash('sha256', $disk->get($rawPath)))) {
                    throw new \RuntimeException('IDXEDGE_STORED_HASH_MISMATCH');
                }

                $result['raw_private_path'] = $rawPath;
                $result['raw_hash_verified'] = true;
                if (! $disk->exists($manifestPath)) {
                    try {
                        $manifest = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        if (! $disk->put($manifestPath, $manifest)) {
                            throw new \RuntimeException('IDXEDGE_MANIFEST_ARCHIVE_FAILED');
                        }
                    } catch (\Throwable $error) {
                        // Avoid leaving an untracked raw-only record after manifest failure.
                        $disk->delete($rawPath);
                        throw $error;
                    }
                    $saved++;
                    $this->line('II_IDXEDGE_REVIEW_SAVED='.$code.';SHA256='.$hash);
                } else {
                    // Legacy manifest may exist; never silently assert it has the new receipt.
                    $this->line('II_IDXEDGE_REVIEW_DUPLICATE='.$code.';RAW_VERIFIED=YES');
                }
            } catch (\Throwable $e) {
                $failed++;
                // No raw upstream error text; protects credentials and PII.
                $this->warn('II_IDXEDGE_REVIEW_FAILED='.$code.';CLASS='.class_basename($e));
            }
        }

        $this->line('II_IDXEDGE_REQUESTS_MAX='.$max);
        $this->line('II_IDXEDGE_FILES_SAVED='.$saved);
        $this->line('II_IDXEDGE_NUMERIC_IMPORT=NO');
        $this->line('II_IDXEDGE_PILOT='.($failed === 0 ? 'PASS' : 'ATTENTION_REQUIRED'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
