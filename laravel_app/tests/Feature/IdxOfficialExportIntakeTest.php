<?php

namespace Tests\Feature;

use App\Services\IdxOfficialExportIntake;
use App\Services\IdxPrimaryEvidenceReconciliation;
use App\Services\ZeroCostAcquisitionPolicy;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class IdxOfficialExportIntakeTest extends TestCase
{
    public function test_reviewed_local_csv_normalizes_volume_and_reconciles_without_network(): void
    {
        $this->app->instance(ZeroCostAcquisitionPolicy::class, new ZeroCostAcquisitionPolicy());
        Http::fake();
        $file = tempnam(sys_get_temp_dir(), 'ii-idx-');
        $review = tempnam(sys_get_temp_dir(), 'ii-review-');
        try {
            file_put_contents($file,"Code,Open,High,Low,Close,Volume,NonRegular\nBBCA,100,102,99,101,120,20\n");
            $r = ['source_url'=>IdxOfficialExportIntake::SOURCE,'terms_url'=>IdxOfficialExportIntake::TERMS,
                'document_sha256'=>hash_file('sha256',$file),'acquisition'=>'MANUAL_OFFICIAL_DOWNLOAD',
                'usage'=>'PERSONAL_NONCOMMERCIAL_RESEARCH','rights_confirmed'=>true,'no_redistribution'=>true,
                'direct_cost_idr'=>0,'reviewer'=>'Synthetic test','session_date'=>'2024-02-01','access_date'=>'2024-02-02',
                'currency'=>'IDR','basis'=>'AS_TRADED','price_market'=>'REGULAR','format'=>'ORIGINAL_CSV',
                'numeric_format'=>'PLAIN_DECIMAL','volume_scope'=>'TOTAL_MINUS_NONREGULAR','volume_unit'=>'LOTS',
                'columns'=>['ticker'=>'Code','open'=>'Open','high'=>'High','low'=>'Low','close'=>'Close','volume'=>'Volume','nonregular_volume'=>'NonRegular']];
            $write = function () use (&$r,$review) { file_put_contents($review,json_encode($r,JSON_THROW_ON_ERROR)); };
            $write();
            $service = app(IdxOfficialExportIntake::class);
            $result = $service->inspect($file,$review,'BBCA');
            $this->assertSame(10000,$result['observations'][0]['values']['volume']);
            $this->assertFalse($result['admitted']);
            // Synthetic candidate, no actual evidence or verified pin asserted.
            $candidate = ['pin_verified'=>true,'ticker'=>'BBCA','sha256'=>str_repeat('a',64),'rows'=>[['date'=>'2024-02-01','open'=>100,'high'=>102,'low'=>99,'close'=>101,'volume'=>10000]]];
            $comp = app(IdxPrimaryEvidenceReconciliation::class)->compareReceipt($candidate,$result,$result['document_sha256'],$result['review_sha256']);
            $this->assertSame(5,$comp['matched_fields']);
            $this->assertFalse($comp['admitted']);
            $r['volume_scope']='TOTAL';
            $write();
            try {
                $service->inspect($file,$review,'BBCA');
                $this->fail('Ambiguous market scope accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('II_IDX_EXPORT_VOLUME_SCOPE_INVALID',$e->getMessage());
            }
            $r['volume_scope']='TOTAL_MINUS_NONREGULAR';
            $r['direct_cost_idr']=1;
            $write();
            try {
                $service->inspect($file,$review,'BBCA');
                $this->fail('Paid source accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('II_IDX_EXPORT_REVIEW_INVALID',$e->getMessage());
            }
            $r['direct_cost_idr']=0;
            $write();
            file_put_contents($file,"\n",FILE_APPEND);
            $this->expectExceptionMessage('II_IDX_EXPORT_REVIEW_INVALID');
            $service->inspect($file,$review,'BBCA');
        } finally {
            Http::assertNothingSent();
            unlink($file);
            unlink($review);
        }
    }
}
