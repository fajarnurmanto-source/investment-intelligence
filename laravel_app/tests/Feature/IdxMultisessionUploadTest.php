<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IdxDailyEvidenceWarehouse;
use App\Services\IdxSessionIntelligence;
use App\Services\IdxOfficialExportIntake;
use App\Services\MarketFeedSettings;
use App\Services\IdxMultisessionIntelligence;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class IdxMultisessionUploadTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(string $date, string $close, string $volume, bool $indonesian = false): UploadedFile
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            $this->markTestSkipped('Native XLSX extensions required.');
        }
        $path = tempnam(sys_get_temp_dir(), 'ii-test-xlsx-');
        $zip = new \ZipArchive();
        try {
            $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $zip->addFromString('xl/workbook.xml', '<workbook xmlns="'.$ns.'" xmlns:r="'.$rel.'"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $data = ['Stock Code'=>'BBCA', 'Last Trading Date'=>$date, 'Open Price'=>'100', 'High'=>'200', 'Low'=>'50', 'Close'=>$close, 'Previous'=>'100', 'Volume'=>$volume];
            $xml = '<worksheet xmlns="'.$ns.'"><sheetData>';
            foreach ([1, 2] as $n) {
                $xml .= '<row r="'.$n.'">';
                foreach (IdxDailyEvidenceWarehouse::HEADERS as $i=>$name) {
                    $col = $i < 26 ? chr(65+$i) : 'A'.chr(65+$i-26);
                    $value = $n === 1 ? ($indonesian ? IdxSessionIntelligence::INDONESIAN_HEADERS[$i] : $name) : ($data[$name] ?? '');
                    $xml .= '<c r="'.$col.$n.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
            $zip->close();
            return UploadedFile::fake()->createWithContent('summary.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_original_reader_and_view_compare_two_fixture_workbooks(): void
    {
        $user = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post('http://localhost/ii/market-intelligence', [
                'workbooks'=>[$this->workbook('04 Nov 2025','120','300', true), $this->workbook('03 Nov 2025','100','100')],
                'rights'=>'1',
            ])->assertOk()->assertSee('Perbandingan sesi perdagangan')->assertSee('BBCA')
            ->assertSee('20,00%')->assertSee('3,00')
            ->assertHeader('X-Content-Type-Options','nosniff')
            ->assertViewHas('comparison', fn ($r) => $r['matched_count'] === 1
                && $r['sessions'][0]['date'] === '2025-11-03'
                && $r['admitted'] === false && $r['trading_authorized'] === false);
        $this->assertDatabaseCount('ii_idx_evidence_batches', 0);
        $this->assertDatabaseCount('ii_idx_evidence_rows', 0);
    }

    public function test_indonesian_single_session_upload(): void
    {
        $user = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post('http://localhost/ii/market-intelligence', [
                'workbook'=>$this->workbook('04 Nov 2025','120','300', true),
                'rights'=>'1',
            ])->assertOk()->assertViewHas('result', fn ($r) => $r['universe'] === 1 && $r['breadth']['up'] === 1);
    }

    public function test_stored_bilingual_sessions_match_upload_and_are_owner_private(): void
    {
        $owner = User::factory()->create(['ii_research_role'=>'analyst']);
        app(MarketFeedSettings::class)->initializeOwner($owner->id);
        $warehouse = app(IdxDailyEvidenceWarehouse::class);
        $files = [$this->workbook('03 Nov 2025','100','100'), $this->workbook('04 Nov 2025','120','300', true)];
        $dates = ['2025-11-03', '2025-11-04'];
        $raw = [];
        $reviewPath = tempnam(sys_get_temp_dir(), 'ii-review-');
        try {
            foreach ($files as $i => $file) {
                $path = $file->getRealPath();
                $raw[] = file_get_contents($path);
                $receipt = ['source_url'=>IdxOfficialExportIntake::SOURCE,'terms_url'=>IdxOfficialExportIntake::TERMS,
                    'document_sha256'=>hash_file('sha256',$path),'acquisition'=>'MANUAL_OFFICIAL_DOWNLOAD',
                    'usage'=>'PERSONAL_NONCOMMERCIAL_RESEARCH','rights_confirmed'=>true,'no_redistribution'=>true,
                    'local_retention_confirmed'=>false,'direct_cost_idr'=>0,'reviewer'=>'Synthetic fixture',
                    'session_date'=>$dates[$i],'access_date'=>'2025-11-05','format'=>'ORIGINAL_XLSX','sheet'=>'Sheet1','header_row'=>1];
                file_put_contents($reviewPath,json_encode($receipt,JSON_THROW_ON_ERROR));
                try {
                    $warehouse->ingest($owner->id,$path,$reviewPath,true);
                    $this->fail('Retention not confirmed');
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame('II_IDX_WAREHOUSE_REVIEW_INVALID',$e->getMessage());
                }
                $receipt['local_retention_confirmed'] = true;
                file_put_contents($reviewPath,json_encode($receipt,JSON_THROW_ON_ERROR));
                $this->assertSame('STORED_EVIDENCE_ONLY',$warehouse->ingest($owner->id,$path,$reviewPath,true)['status']);
                $this->assertSame('UNCHANGED',$warehouse->ingest($owner->id,$path,$reviewPath,true)['status']);
            }
        } finally {
            unlink($reviewPath);
        }
        $expected = app(IdxMultisessionIntelligence::class)->analyze($raw) + ['archive_source'=>true];
        $this->assertSame($expected,$warehouse->historicalCompare($owner->id,array_reverse($dates)));
        $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->get('http://localhost/ii/market-intelligence')->assertOk()->assertSee('Analisis arsip');
        $this->post('http://localhost/ii/market-intelligence',['mode'=>'history','dates'=>$dates])
            ->assertOk()->assertViewHas('comparison',$expected);
        $other = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($other)->get('http://localhost/ii/market-intelligence')->assertOk()->assertDontSee('Arsip perdagangan');
        $this->post('http://localhost/ii/market-intelligence',['mode'=>'history','dates'=>$dates])->assertForbidden();
        $this->assertDatabaseCount('ii_idx_evidence_batches',2);
        $this->assertDatabaseCount('ii_idx_evidence_rows',2);
        DB::table('ii_idx_evidence_batches')->where('user_id',$owner->id)->where('session_date',$dates[0])->update(['review_json'=>'{}']);
        $this->actingAs($owner)->post('http://localhost/ii/market-intelligence',['mode'=>'history','dates'=>$dates])->assertStatus(422);
    }

    public function test_multisession_requires_rights_and_distinct_dates(): void
    {
        $user = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post('http://localhost/ii/market-intelligence', [
                'workbooks'=>[$this->workbook('03 Nov 2025','100','100'), $this->workbook('04 Nov 2025','120','300')],
            ])->assertStatus(422)->assertSee('konfirmasikan hak penggunaan');
        $this->post('http://localhost/ii/market-intelligence', [
            'workbooks'=>[$this->workbook('03 Nov 2025','100','100'), $this->workbook('03 Nov 2025','120','300')],
            'rights'=>'1',
        ])->assertStatus(422)->assertSee('tanggal berbeda');
        $this->assertDatabaseCount('ii_idx_evidence_batches', 0);
    }
}
