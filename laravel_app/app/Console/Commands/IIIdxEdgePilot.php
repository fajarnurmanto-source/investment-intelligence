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
                // Lock is separate from ticker evidence listing and scoped to
                // the exact checksum; concurrent invocations cannot interleave.
                $lockDir = storage_path('app/private/ii_idxedge_locks');
                if (! is_dir($lockDir) && ! mkdir($lockDir, 0700, true) && ! is_dir($lockDir)) {
                    throw new \RuntimeException('IDXEDGE_LOCK_DIRECTORY_FAILED');
                }
                $lockFile = $lockDir.'/'.$hash.'.lock';
                $lockHandle = fopen($lockFile, 'c');
                if ($lockHandle === false) {
                    throw new \RuntimeException('IDXEDGE_LOCK_OPEN_FAILED');
                }
                try {
                    if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
                        throw new \RuntimeException('IDXEDGE_ARCHIVE_BUSY');
                    }
                    $this->recoverStaging($disk, 'ii_idxedge_review/'.$code);
                $manifestExists = $disk->exists($manifestPath);
                $rawExists = $disk->exists($rawPath);

                // A manifest from the legacy implementation has no immutable raw
                // byte evidence. Never silently promote it into a verified receipt.
                if ($manifestExists) {
                    $existing = json_decode($disk->get($manifestPath), true);
                    if (! is_array($existing)
                        || ($existing['raw_private_path'] ?? null) !== $rawPath
                        || ($existing['raw_hash_verified'] ?? false) !== true
                        || ($existing['source_sha256'] ?? null) !== $hash
                        || ! $rawExists) {
                        throw new \RuntimeException('IDXEDGE_LEGACY_OR_INVALID_MANIFEST');
                    }
                }

                // Stage on the same filesystem, then rename. Never expose a
                // partly written final path to another process.
                $rawCreated = false;
                if (! $rawExists) {
                    $this->writeStagedEvidence($disk, $rawPath, $raw);
                    $rawCreated = true;
                }

                if (! hash_equals($hash, hash('sha256', $disk->get($rawPath)))) {
                    if ($rawCreated) {
                        $disk->delete($rawPath);
                    }
                    throw new \RuntimeException('IDXEDGE_STORED_HASH_MISMATCH');
                }

                $result['raw_private_path'] = $rawPath;
                $result['raw_hash_verified'] = true;
                if (! $manifestExists) {
                    try {
                        $manifest = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        $this->writeStagedEvidence($disk, $manifestPath, $manifest);
                    } catch (\Throwable $error) {
                        // Clean a possibly partial manifest before returning failure.
                        $disk->delete($manifestPath);
                        // Only remove the raw file created by this attempt.
                        if ($rawCreated) {
                            $disk->delete($rawPath);
                        }
                        throw $error;
                    }
                    $saved++;
                    $this->line('II_IDXEDGE_REVIEW_SAVED='.$code.';SHA256='.$hash);
                } else {
                    $this->line('II_IDXEDGE_REVIEW_DUPLICATE='.$code.';RAW_VERIFIED=YES');
                }
                } finally {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
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
    /**
     * Same-directory staging followed by filesystem rename. The caller holds
     * a checksum-scoped flock; the destination must not already exist.
     */
    private function writeStagedEvidence($disk, string $destination, string $bytes): void
    {
        if ($disk->exists($destination)) {
            throw new \RuntimeException('IDXEDGE_ARCHIVE_ALREADY_EXISTS');
        }

        $temporary = $destination.'.pending-'.bin2hex(random_bytes(8));
        try {
            if (! $disk->put($temporary, $bytes)) {
                throw new \RuntimeException('IDXEDGE_STAGE_WRITE_FAILED');
            }
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($temporary)))) {
                throw new \RuntimeException('IDXEDGE_STAGE_HASH_MISMATCH');
            }
            if (! $disk->move($temporary, $destination)) {
                throw new \RuntimeException('IDXEDGE_STAGE_RENAME_FAILED');
            }
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($destination)))) {
                // Do not report a corrupt final artifact as success.
                $disk->delete($destination);
                throw new \RuntimeException('IDXEDGE_FINAL_HASH_MISMATCH');
            }
        } finally {
            $disk->delete($temporary);
        }
    }

    /**
     * Only remove abandoned staging files; never remove immutable final raw
     * evidence or a reviewed manifest. Runs under the same ticker/hash lock.
     */
    private function recoverStaging($disk, string $directory): void
    {
        foreach ($disk->files($directory) as $path) {
            if (preg_match('/\.(?:raw|json)\.pending-[a-f0-9]{16}$/D', $path)) {
                $disk->delete($path);
            }
        }
    }

}
