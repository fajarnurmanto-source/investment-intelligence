<?php

namespace App\Services;

/** Compare only explicit transcriptions of lawfully obtained primary documents.
 * A URL and checksum do not prove authenticity or correctness of transcription. */
final class IdxPrimaryEvidenceReconciliation
{
    public function compare(array $candidate, string $documentPath, string $receiptPath): array
    {
        if (($candidate['pin_verified'] ?? false) !== true) {
            throw new \InvalidArgumentException('II_IDX_CANDIDATE_PIN_REQUIRED');
        }
        if (!is_file($documentPath) || !is_file($receiptPath)) {
            throw new \InvalidArgumentException('II_IDX_EVIDENCE_FILE_REQUIRED');
        }
        $doc = file_get_contents($documentPath, false, null, 0, 20_000_001);
        $raw = file_get_contents($receiptPath, false, null, 0, 500001);
        if (!is_string($doc) || $doc === '' || strlen($doc) > 20_000_000
            || !is_string($raw) || strlen($raw) > 500000) {
            throw new \InvalidArgumentException('II_IDX_EVIDENCE_SIZE_INVALID');
        }
        $r = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $url = $r['source_url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), ['idx.id','www.idx.id','idx.co.id','www.idx.co.id'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ($r['document_sha256'] ?? null) !== hash('sha256', $doc)
            || ($r['rights_confirmed'] ?? null) !== true
            || ($r['ticker'] ?? null) !== $candidate['ticker']
            || ($r['currency'] ?? null) !== 'IDR'
            || ($r['market'] ?? null) !== 'REGULAR'
            || ($r['basis'] ?? null) !== 'AS_TRADED'
            || !is_array($r['observations'] ?? null) || !array_is_list($r['observations'])
            || count($r['observations']) < 1 || count($r['observations']) > 250) {
            throw new \InvalidArgumentException('II_IDX_EVIDENCE_CONTRACT_INVALID');
        }
        $byDate = array_column($candidate['rows'], null, 'date');
        $seen = [];
        $results = [];
        $matched = $conflicted = $missing = 0;
        foreach ($r['observations'] as $obs) {
            $date = $obs['date'] ?? '';
            $loc = $obs['locator'] ?? '';
            $values = $obs['values'] ?? null;
            $dt = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (!$dt || $dt->format('Y-m-d') !== $date || $date >= now('Asia/Jakarta')->toDateString()
                || isset($seen[$date]) || !is_string($loc) || trim($loc) === '' || strlen($loc) > 300
                || !is_array($values) || $values === [] || array_is_list($values)
                || array_diff(array_keys($values), ['open','high','low','close','volume'])) {
                throw new \InvalidArgumentException('II_IDX_EVIDENCE_OBSERVATION_INVALID');
            }
            $seen[$date] = true;
            $checks = [];
            foreach ($values as $field => $value) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
                    || ($field === 'volume' ? ($value < 0 || floor((float)$value) !== (float)$value) : $value <= 0)) {
                    throw new \InvalidArgumentException('II_IDX_EVIDENCE_VALUE_INVALID');
                }
                $actual = $byDate[$date][$field] ?? null;
                $state = $actual === null ? 'MISSING' : ((float)$actual === (float)$value ? 'MATCH' : 'CONFLICT');
                $matched += (int) ($state === 'MATCH');
                $conflicted += (int) ($state === 'CONFLICT');
                $missing += (int) ($state === 'MISSING');
                $checks[$field] = ['status'=>$state];
            }
            $results[] = ['date'=>$date,'locator'=>$loc,'fields'=>$checks];
        }
        return ['status'=>$conflicted ? 'CONFLICT' : ($missing ? 'INCOMPLETE' : 'TRANSCRIPTION_AGREEMENT'),
            'ticker'=>$candidate['ticker'],'candidate_sha256'=>$candidate['sha256'],
            'document_sha256'=>hash('sha256',$doc),'receipt_sha256'=>hash('sha256',$raw),
            'source_url'=>$url,'matched_fields'=>$matched,'conflicted_fields'=>$conflicted,'missing_fields'=>$missing,
            'checks'=>$results,'evidence_authenticity'=>'UNVERIFIED_USER_TRANSCRIPTION',
            'independence'=>'UNPROVEN','admitted'=>false,'trading_authorized'=>false];
    }
}
