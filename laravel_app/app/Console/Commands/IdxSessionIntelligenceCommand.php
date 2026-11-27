<?php

namespace App\Console\Commands;

use App\Services\IdxSessionIntelligence;
use App\Services\IdxOfficialExportIntake;
use Illuminate\Console\Command;

final class IdxSessionIntelligenceCommand extends Command
{
    protected $signature = 'ii:idx-intelligence {--file=} {--rights-confirmed}';
    protected $description = 'Analyze a local IDX workbook for descriptive personal research without persistence';

    public function handle(IdxSessionIntelligence $engine): int
    {
        if (!$this->option('rights-confirmed')) {
            $this->error('II_IDX_LOCAL_RIGHTS_REQUIRED');
            return self::FAILURE;
        }
        $path = (string) $this->option('file');
        if (!is_file($path) || !is_readable($path)) {
            $this->error('II_IDX_FILE_REQUIRED');
            return self::FAILURE;
        }
        try {
            $raw = file_get_contents($path, false, null, 0, IdxOfficialExportIntake::MAX_BYTES + 1);
            if (!is_string($raw) || $raw === '' || strlen($raw) > IdxOfficialExportIntake::MAX_BYTES) {
                throw new \InvalidArgumentException('II_IDX_FILE_SIZE_INVALID');
            }
            $this->line(json_encode($engine->analyze($raw), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $e->getMessage()) ? $e->getMessage() : 'II_IDX_INTELLIGENCE_FAILED');
            return self::FAILURE;
        }
    }
}
