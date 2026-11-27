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
        if ($header !== self::HEADERS || !$records) {
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
        if (!$commit) {
            return $result;
        }
        return Cache::lock('ii-idx-evidence-'.$owner,120)->block(2,function () use ($owner,$session,$sha,$reviewSha,$meta,$rows,$result) {
            $this->owner($owner);
            return DB::transaction(function () use ($owner,$session,$sha,$reviewSha,$meta,$rows,$result) {
                $existing=DB::table('ii_idx_evidence_batches')->where('user_id',$owner)->where('session_date',$session)->first();
                if ($existing) {
                    if ($existing->source_sha256!==$sha || $existing->review_sha256!==$reviewSha
                        || DB::table('ii_idx_evidence_rows')->where('batch_id',$existing->id)->count()!==count($rows)) {
                        throw new \RuntimeException('II_IDX_WAREHOUSE_EXISTING_BATCH_CONFLICT');
                    }
                    return array_replace($result,['status'=>'UNCHANGED','batch_id'=>$existing->id]);
                }
                $id=DB::table('ii_idx_evidence_batches')->insertGetId(['user_id'=>$owner,'session_date'=>$session,
                    'source_sha256'=>$sha,'review_sha256'=>$reviewSha,'review_json'=>$meta,'created_at'=>now('UTC')]);
                foreach (array_chunk($rows,50) as $chunk) {
                    DB::table('ii_idx_evidence_rows')->insert(array_map(fn($row)=>$row+['batch_id'=>$id],$chunk));
                }
                return array_replace($result,['status'=>'STORED_EVIDENCE_ONLY','batch_id'=>$id]);
            });
        });
    }

    public function status(int $owner): array
    {
        $this->owner($owner);
        $batches=DB::table('ii_idx_evidence_batches')->where('user_id',$owner)->orderByDesc('session_date')->limit(30)->get(['id','session_date','source_sha256','review_sha256']);
        return ['batches'=>$batches->map(fn($b)=>['session_date'=>$b->session_date,'sha256'=>$b->source_sha256,
            'review_sha256'=>$b->review_sha256,'rows'=>DB::table('ii_idx_evidence_rows')->where('batch_id',$b->id)->count()])->all(),
            'admitted'=>false,'trading_authorized'=>false];
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
