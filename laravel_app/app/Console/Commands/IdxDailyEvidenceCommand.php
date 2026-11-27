<?php

namespace App\Console\Commands;

use App\Services\IdxDailyEvidenceWarehouse;
use Illuminate\Console\Command;

final class IdxDailyEvidenceCommand extends Command
{
    protected $signature = 'ii:idx-evidence {action : preview|store|status} {--user=} {--file=} {--review=}';
    protected $description = 'Private noncommercial IDX daily evidence warehouse';

    public function handle(IdxDailyEvidenceWarehouse $warehouse): int
    {
        try {
            $owner=(int)$this->option('user');
            $result=match($this->argument('action')) {
                'preview','store'=>$warehouse->ingest($owner,(string)$this->option('file'),(string)$this->option('review'),$this->argument('action')==='store'),
                'status'=>$warehouse->status($owner),
                default=>throw new \InvalidArgumentException('II_IDX_WAREHOUSE_ACTION_INVALID'),
            };
            $this->line(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $code=$e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D',$code)?$code:'II_IDX_WAREHOUSE_FAILED');
            return self::FAILURE;
        }
    }
}
