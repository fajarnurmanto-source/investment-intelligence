<?php

namespace App\Console\Commands;

use App\Services\AetherealIdxProbe;
use Illuminate\Console\Command;

final class ProbeAetherealIdxCommand extends Command
{
    protected $signature = 'ii:probe-aethereal-idx {--file=} {--rights-confirmed}';
    protected $description = 'Inspect research-only Aethereal CSV without importing it';

    public function handle(AetherealIdxProbe $probe): int
    {
        try {
            if (!$this->option('rights-confirmed')) {
                throw new \InvalidArgumentException('II_AETHEREAL_RESEARCH_RIGHTS_REQUIRED');
            }
            $result = $this->option('file') ? $probe->inspectFile($this->option('file')) : $probe->download(true);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_AETHEREAL_PROBE_FAILED');
            return self::FAILURE;
        }
    }
}
