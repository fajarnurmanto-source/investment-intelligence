<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Private local evidence warehouse. Raw source fields are never promoted to signals. */
final class IdxDailyEvidenceWarehouse
{
    public const HEADERS = ['No','Stock Code','Company Name','Remarks','Previous','Open Price','Last Trading Date','First Trade','High','Low','Close','Change','Volume','Value','Frequency','Index Individual','Offer','Offer Volume','Bid','Bid Volume','Listed Shares','Tradeble Shares','Weight For Index','Foreign Sell','Foreign Buy','Non Regular Volume','Non Regular Value','Non Regular Frequency'];

    private function owner(int $owner): void
    {
        $settings = app(MarketFeedSettings::class);
        if ($owner < 1 || (int)($settings->current()['owner_id'] ?? 0) !== $owner || !$settings->canManage($owner)) {
            throw new \InvalidArgumentException('II_IDX_WAREHOUSE_OWNER_REQUIRED');
        }
    }

    public function ingest(int $owner, string $file, string $review, bool $commit = false): array
    {
        $this->owner($owner);
        $prepared = $this->prepare($file, $review);
        if (!$commit) {
            return $prepared['result'];
        }
        return Cache::lock('ii-idx-evidence-'.$owner,120)->block(2, function () use ($owner, $prepared) {
            $this->owner($owner);
            return DB::transaction(fn () => $this->storePrepared($owner, $prepared));
        });
    }

    /** Validate all pairs before acquiring the write lock; commit all or none. */
    public function ingestBatch(int $owner, array $pairs, bool $commit = false): array
    {
        $this->owner($owner);
        if (!array_is_list($pairs) || count($pairs) < 1 || count($pairs) > 5) {
            throw new \InvalidArgumentException('II_IDX_BATCH_SIZE_INVALID');
        }
        $prepared = [];
        $total = 0;
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !is_string($pair['file'] ?? null) || !is_string($pair['review'] ?? null)
                || !is_file($pair['file']) || !is_file($pair['review'])
                || ($total += filesize($pair['file'])) > IdxOfficialExportIntake::MAX_BYTES) {
                throw new \InvalidArgumentException('II_IDX_BATCH_INPUT_INVALID');
            }
            $item = $this->prepare($pair['file'], $pair['review']);
            $date = $item['result']['session_date'];
            if (isset($prepared[$date])) {
                throw new \InvalidArgumentException('II_IDX_BATCH_DUPLICATE_DATE');
            }
            $prepared[$date] = $item;
        }
        ksort($prepared);
        $apply = function () use ($owner, $prepared, $commit): array {
            $this->owner($owner);
            $results = [];
            foreach ($prepared as $item) {
                $results[] = $this->storePrepared($owner, $item, $commit);
            }
            return ['status'=>$commit ? 'BATCH_COMMITTED' : 'BATCH_PREVIEW',
                'sessions'=>$results, 'rows'=>array_sum(array_column($results,'rows')),
                'admitted'=>false, 'trading_authorized'=>false];
        };
        return Cache::lock('ii-idx-evidence-'.$owner,120)->block(2, fn () => DB::transaction($apply));
    }

    private function prepare(string $file, string $review): array
    {
        if (!is_file($file) || !is_file($review)) {
            throw new \InvalidArgumentException('II_IDX_WAREHOUSE_FILE_REQUIRED');
        }
        $raw = file_get_contents($file,false,null,0,IdxOfficialExportIntake::MAX_BYTES+1);
        $meta = file_get_contents($review,false,null,0,100001);
        if (!is_string($raw) || $raw === '' || strlen($raw)>IdxOfficialExportIntake::MAX_BYTES || !is_string($meta) || strlen($meta)>100000) {
            throw new \InvalidArgumentException('II_IDX_WAREHOUSE_SIZE_INVALID');
        }
        $receipt = json_decode($meta,true,12,JSON_THROW_ON_ERROR);
        $sha = hash('sha256',$raw);
        $reviewSha = hash('sha256',$meta);
        $session = $receipt['session_date'] ?? null;
        $access = $receipt['access_date'] ?? null;
        $today = now('Asia/Jakarta')->toDateString();
        if (($receipt['source_url'] ?? null) !== IdxOfficialExportIntake::SOURCE
            || ($receipt['terms_url'] ?? null) !== IdxOfficialExportIntake::TERMS
            || ($receipt['document_sha256'] ?? null) !== $sha
            || ($receipt['acquisition'] ?? null) !== 'MANUAL_OFFICIAL_DOWNLOAD'
            || ($receipt['usage'] ?? null) !== 'PERSONAL_NONCOMMERCIAL_RESEARCH'
            || ($receipt['rights_confirmed'] ?? null) !== true || ($receipt['no_redistribution'] ?? null) !== true
            || ($receipt['direct_cost_idr'] ?? null) !== 0
            || ($receipt['local_retention_confirmed'] ?? null) !== true
            || !is_string($receipt['reviewer'] ?? null) || trim($receipt['reviewer']) === '' || strlen($receipt['reviewer'])>150
            || !$this->date($session) || !$this->date($access) || $session >= $today || $access < $session || $access > $today
            || ($receipt['format'] ?? null) !== 'ORIGINAL_XLSX') {
            throw new \InvalidArgumentException('II_IDX_WAREHOUSE_REVIEW_INVALID');
        }
        [$header,$records] = app(IdxXlsxExportReader::class)->read($raw,$receipt['sheet'] ?? null,$receipt['header_row'] ?? null);
        $records = app(IdxSessionIntelligence::class)->normalizeExport($header, $records);
        if (!$records || count($records) > 2000) {
            throw new \InvalidArgumentException('II_IDX_WAREHOUSE_SCHEMA_INVALID');
        }
        $seen = [];
        $rows = [];
        $positive = $zeroVolume = $invalid = 0;
        foreach ($records as $offset=>$row) {
            $ticker = $row['Stock Code'];
            if (!preg_match('/^[A-Z0-9]{4,5}$/D',$ticker) || isset($seen[$ticker])
                || $row['Last Trading Date'] !== (new \DateTimeImmutable($session))->format('d M Y')) {
                throw new \InvalidArgumentException('II_IDX_WAREHOUSE_IDENTITY_OR_SESSION_INVALID');
            }
            $seen[$ticker]=true;
            $valid = true;
            $numbers = [];
            foreach (['Open Price','High','Low','Close','Volume'] as $key) {
                $v = $row[$key];
                if (!preg_match('/^\d{1,15}(?:\.\d{1,6})?$/D',$v)) {
                    $valid=false;
                }
                $numbers[$key]=(float)$v;
            }
            $o=$numbers['Open Price']; $hi=$numbers['High']; $lo=$numbers['Low']; $c=$numbers['Close']; $v=$numbers['Volume'];
            $zeroVolume+=(int)($v===0.0 && $valid);
            $quality = !$valid || $v<0 || floor($v)!==$v || $c<=0 || ($o>0 && ($lo<=0 || $lo>min($o,$c) || $hi<max($o,$c)))
                ? 'STRUCTURAL_REVIEW' : ($o<=0 || $v===0.0 ? 'INCOMPLETE_SESSION' : 'STRUCTURAL_CANDIDATE');
            $positive+=(int)($o>0 && $valid);
            $invalid+=(int)($quality==='STRUCTURAL_REVIEW');
            $rows[]=['ticker'=>$ticker,'row_number'=>$offset,'quality'=>$quality,'payload'=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];
        }
        $result=['status'=>'PREVIEW','sha256'=>$sha,'review_sha256'=>$reviewSha,'session_date'=>$session,
            'rows'=>count($rows),'positive_open'=>$positive,'zero_volume'=>$zeroVolume,'structural_review'=>$invalid,
            'structural_candidates'=>count(array_filter($rows,fn($row)=>$row['quality']==='STRUCTURAL_CANDIDATE')),
            'rights'=>'USER_ATTESTED_LOCAL_NONCOMMERCIAL','authenticity'=>'USER_ATTESTED_EXPORT',
            'admitted'=>false,'trading_authorized'=>false];
        return compact('session','sha','reviewSha','meta','rows','result');
    }

    private function storePrepared(int $owner, array $prepared, bool $commit = true): array
    {
        ['session'=>$session,'sha'=>$sha,'reviewSha'=>$reviewSha,'meta'=>$meta,'rows'=>$rows,'result'=>$result] = $prepared;
        $existing=DB::table('ii_idx_evidence_batches')->where('user_id',$owner)->where('session_date',$session)->first();
        if ($existing) {
            $stored = DB::table('ii_idx_evidence_rows')->where('batch_id',$existing->id)->orderBy('row_number')
                ->get(['ticker','row_number','quality','payload'])->map(fn ($row) => [
                    'ticker'=>$row->ticker,'row_number'=>(int)$row->row_number,'quality'=>$row->quality,'payload'=>$row->payload
                ])->all();
            if ($existing->source_sha256!==$sha || $existing->review_sha256!==$reviewSha
                || !hash_equals($reviewSha, hash('sha256',$existing->review_json)) || $stored!==$rows) {
                throw new \RuntimeException('II_IDX_WAREHOUSE_EXISTING_BATCH_CONFLICT');
            }
            return array_replace($result,['status'=>'UNCHANGED','batch_id'=>$existing->id]);
        }
        if (!$commit) {
            return $result;
        }
        $id=DB::table('ii_idx_evidence_batches')->insertGetId(['user_id'=>$owner,'session_date'=>$session,
            'source_sha256'=>$sha,'review_sha256'=>$reviewSha,'review_json'=>$meta,'created_at'=>now('UTC')]);
        foreach (array_chunk($rows,50) as $chunk) {
            DB::table('ii_idx_evidence_rows')->insert(array_map(fn($row)=>$row+['batch_id'=>$id],$chunk));
        }
        return array_replace($result,['status'=>'STORED_EVIDENCE_ONLY','batch_id'=>$id]);
    }

    public function status(int $owner): array
    {
        $this->owner($owner);
        $batches=DB::table('ii_idx_evidence_batches')->where('user_id',$owner)->orderByDesc('session_date')->limit(30)->get(['id','session_date','source_sha256','review_sha256']);
        $scope = DB::table('ii_idx_evidence_batches')->where('user_id',$owner);
        $coverage = (clone $scope)->selectRaw('COUNT(*) as sessions, MIN(session_date) as first_date, MAX(session_date) as last_date')->first();
        $observations = DB::table('ii_idx_evidence_rows')->whereIn('batch_id',(clone $scope)->select('id'))->count();
        return ['coverage'=>['sessions'=>(int)$coverage->sessions,'first_date'=>$coverage->first_date,
            'last_date'=>$coverage->last_date,'observations'=>$observations],
            'batches'=>$batches->map(fn($b)=>['session_date'=>$b->session_date,'sha256'=>$b->source_sha256,
            'review_sha256'=>$b->review_sha256,'rows'=>DB::table('ii_idx_evidence_rows')->where('batch_id',$b->id)->count()])->all(),
            'admitted'=>false,'trading_authorized'=>false];
    }

    public function isOwner(int $owner): bool
    {
        try {
            $this->owner($owner);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function historicalCompare(int $owner, array $dates): array
    {
        $this->owner($owner);
        if (count($dates) < 2 || count($dates) > 5 || count(array_unique($dates, SORT_REGULAR)) !== count($dates)) {
            throw new \InvalidArgumentException('II_IDX_HISTORY_DATES_INVALID');
        }
        foreach ($dates as $date) {
            if (!$this->date($date) || $date >= now('Asia/Jakarta')->toDateString()) {
                throw new \InvalidArgumentException('II_IDX_HISTORY_DATES_INVALID');
            }
        }

        return DB::transaction(function () use ($owner, $dates) {
            $this->owner($owner);
            return app(IdxMultisessionIntelligence::class)->compare(iterator_to_array($this->reviewedSessions($owner, $dates), false)) + ['archive_source' => true];
        });
    }

    public function historicalMomentum(int $owner): array
    {
        $this->owner($owner);
        return DB::transaction(function () use ($owner) {
            $this->owner($owner);
            $dates = DB::table('ii_idx_evidence_batches')->where('user_id',$owner)->orderByDesc('session_date')
                ->limit(250)->pluck('session_date')->all();
            return app(IdxMomentumIntelligence::class)->analyze($this->reviewedSessions($owner, array_reverse($dates)));
        });
    }

    public function historicalLiquidity(int $owner): array
    {
        $this->owner($owner);
        return DB::transaction(function () use ($owner) {
            $this->owner($owner);
            $dates = DB::table('ii_idx_evidence_batches')->where('user_id',$owner)->orderByDesc('session_date')
                ->limit(IdxLiquidityAnomalyIntelligence::BASELINE+1)->pluck('session_date')->all();
            return app(IdxLiquidityAnomalyIntelligence::class)->analyze($this->reviewedSessions($owner, array_reverse($dates)));
        });
    }

    private function reviewedSessions(int $owner, array $dates): iterable
    {
            foreach ($dates as $date) {
                $batch = DB::table('ii_idx_evidence_batches')->where('user_id', $owner)
                    ->where('session_date', $date)->first();
                if (!$batch) {
                    throw new \InvalidArgumentException('II_IDX_HISTORY_SESSION_NOT_FOUND');
                }
                $review = json_decode($batch->review_json, true, 12, JSON_THROW_ON_ERROR);
                if (!hash_equals($batch->review_sha256, hash('sha256', $batch->review_json))
                    || ($review['document_sha256'] ?? null) !== $batch->source_sha256
                    || ($review['session_date'] ?? null) !== $date
                    || ($review['source_url'] ?? null) !== IdxOfficialExportIntake::SOURCE
                    || ($review['terms_url'] ?? null) !== IdxOfficialExportIntake::TERMS
                    || ($review['acquisition'] ?? null) !== 'MANUAL_OFFICIAL_DOWNLOAD'
                    || ($review['format'] ?? null) !== 'ORIGINAL_XLSX'
                    || ($review['usage'] ?? null) !== 'PERSONAL_NONCOMMERCIAL_RESEARCH'
                    || ($review['rights_confirmed'] ?? null) !== true
                    || ($review['local_retention_confirmed'] ?? null) !== true
                    || ($review['no_redistribution'] ?? null) !== true
                    || ($review['direct_cost_idr'] ?? null) !== 0
                    || !is_string($review['reviewer'] ?? null) || trim($review['reviewer']) === ''
                    || !$this->date($review['access_date'] ?? null)
                    || $review['access_date'] < $date || $review['access_date'] > now('Asia/Jakarta')->toDateString()) {
                    throw new \InvalidArgumentException('II_IDX_HISTORY_REVIEW_INVALID');
                }
                $stored = DB::table('ii_idx_evidence_rows')->where('batch_id', $batch->id)
                    ->orderBy('row_number')->limit(2001)->get();
                if ($stored->isEmpty() || $stored->count() > 2000) {
                    throw new \InvalidArgumentException('II_IDX_HISTORY_ROWS_INVALID');
                }
                $rows = [];
                foreach ($stored as $entry) {
                    $row = json_decode($entry->payload, true, 12, JSON_THROW_ON_ERROR);
                    if (array_keys($row) !== self::HEADERS
                        || $row['Stock Code'] !== $entry->ticker
                        || $row['Last Trading Date'] !== (new \DateTimeImmutable($date))->format('d M Y')) {
                        throw new \InvalidArgumentException('II_IDX_HISTORY_ROW_INVALID');
                    }
                    $rows[] = $row;
                }
                yield ['rows' => $rows, 'sha256' => $batch->source_sha256];
            }
    }

    private function date(mixed $v): bool
    {
        if (!is_string($v)) {
            return false;
        }
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        return $d!==false && $d->format('Y-m-d')===$v;
    }
}
