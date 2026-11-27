<?php

namespace Tests\Feature;

use App\Services\SeoultraIdxProbe;
use App\Services\IdxPrimaryEvidenceReconciliation;
use Tests\TestCase;

final class IdxPrimaryEvidenceReconciliationTest extends TestCase
{
    public function test_diagnostic_conflict_and_pin_boundary(): void
    {
        $rows = [];
        $d = new \DateTimeImmutable('2024-01-01');
        while (count($rows) < 60) {
            if ((int)$d->format('N') <= 5) {
                $rows[] = [$d->format('Y-m-d'),100,102,99,101,1000];
            }
            $d = $d->modify('+1 day');
        }
        $j = ['schema'=>1,'code'=>'BBCA','exchange'=>'IDX','currency'=>'IDR','adjusted'=>false,
            'source'=>['name'=>'Yahoo Finance','symbol'=>'BBCA.JK'],'columns'=>['date','open','high','low','close','volume'],
            'sessions'=>60,'first_date'=>$rows[0][0],'last_date'=>$rows[59][0],'rows'=>$rows];
        $raw = json_encode($j, JSON_THROW_ON_ERROR);
        $probe = app(SeoultraIdxProbe::class);
        $candidate = $probe->parse($raw,'BBCA');
        $this->assertSame(60,$candidate['sessions']);
        $this->assertFalse($candidate['pin_verified']);
        $doc = tempnam(sys_get_temp_dir(),'ii-doc-');
        $receipt = tempnam(sys_get_temp_dir(),'ii-receipt-');
        try {
            file_put_contents($doc,'synthetic document fixture');
            $r = ['source_url'=>'https://www.idx.id/fixture','document_sha256'=>hash_file('sha256',$doc),
                'rights_confirmed'=>true,'ticker'=>'BBCA','currency'=>'IDR','market'=>'REGULAR','basis'=>'AS_TRADED',
                'observations'=>[['date'=>$rows[59][0],'locator'=>'fixture page 1','values'=>['close'=>101,'volume'=>999]]]];
            file_put_contents($receipt,json_encode($r,JSON_THROW_ON_ERROR));
            try {
                app(IdxPrimaryEvidenceReconciliation::class)->compare($candidate,$doc,$receipt);
                $this->fail('Unpinned candidate accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('II_IDX_CANDIDATE_PIN_REQUIRED',$e->getMessage());
            }
            // Explicit test-only fixture: not an actual primary document or pin.
            $candidate['pin_verified'] = true;
            $candidate['sha256'] = hash('sha256',$raw);
            $result = app(IdxPrimaryEvidenceReconciliation::class)->compare($candidate,$doc,$receipt);
            $this->assertSame('CONFLICT',$result['status']);
            $this->assertSame(1,$result['matched_fields']);
            $this->assertSame(1,$result['conflicted_fields']);
            $this->assertFalse($result['admitted']);
            $r['observations'][0]['values']['volume'] = 1000;
            file_put_contents($receipt,json_encode($r,JSON_THROW_ON_ERROR));
            $this->assertSame('TRANSCRIPTION_AGREEMENT',app(IdxPrimaryEvidenceReconciliation::class)->compare($candidate,$doc,$receipt)['status']);
            file_put_contents($doc,'changed fixture');
            $this->expectExceptionMessage('II_IDX_EVIDENCE_CONTRACT_INVALID');
            app(IdxPrimaryEvidenceReconciliation::class)->compare($candidate,$doc,$receipt);
        } finally {
            unlink($doc);
            unlink($receipt);
        }
    }
}
