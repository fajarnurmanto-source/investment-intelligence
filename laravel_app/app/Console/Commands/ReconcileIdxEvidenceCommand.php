<?php

namespace App\Console\Commands;

use App\Services\SeoultraIdxProbe;
use App\Services\IdxPrimaryEvidenceReconciliation;
use Illuminate\Console\Command;

final class ReconcileIdxEvidenceCommand extends Command
{
    protected $signature = 'ii:reconcile-idx-evidence {--ticker=} {--file=} {--document=} {--receipt=} {--official-csv=} {--official-xlsx=} {--export-review=} {--rights-confirmed}';
    protected $description = 'Diagnose pinned Seoultra JSON and optionally compare a local IDX evidence transcription';

    public function handle(SeoultraIdxProbe $probe, IdxPrimaryEvidenceReconciliation $comparison, \App\Services\IdxOfficialExportIntake $intake): int
    {
        try {
            if (!$this->option('rights-confirmed') || !$this->option('file')) {
                throw new \InvalidArgumentException('II_IDX_LOCAL_RIGHTS_REQUIRED');
            }
            $candidate = $probe->read($this->option('file'), (string)$this->option('ticker'));
            if ($this->option('official-csv') || $this->option('official-xlsx') || $this->option('export-review')) {
                if ($this->option('document') || $this->option('receipt') || ($this->option('official-csv') && $this->option('official-xlsx'))) {
                    throw new \InvalidArgumentException('II_IDX_EVIDENCE_MODE_CONFLICT');
                }
                $receipt = $intake->inspect((string)($this->option('official-xlsx') ?: $this->option('official-csv')), (string)$this->option('export-review'), (string)$this->option('ticker'));
                $result = $comparison->compareReceipt($candidate, $receipt, $receipt['document_sha256'], $receipt['review_sha256']);
                $result['access_date'] = $receipt['access_date'];
                $result['rights_status'] = $receipt['rights_status'];
                $result['evidence_authenticity'] = $receipt['evidence_authenticity'];
            } elseif ($this->option('document') || $this->option('receipt')) {
                $result = $comparison->compare($candidate, (string)$this->option('document'), (string)$this->option('receipt'));
            } else {
                $result = $candidate;
                unset($result['rows']);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_IDX_EVIDENCE_DIAGNOSTIC_FAILED');
            return self::FAILURE;
        }
    }
}
