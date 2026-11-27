<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IdxDailyEvidenceWarehouse;
use App\Services\IdxOfficialExportIntake;
use App\Services\MarketFeedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IdxDailyEvidenceWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_store_replay_conflict_and_owner_isolation(): void
    {
        $owner=User::factory()->create(['ii_research_role'=>'analyst']);
        app(MarketFeedSettings::class)->initializeOwner($owner->id);
        $file=tempnam(sys_get_temp_dir(),'ii-xlsx-');
        $review=tempnam(sys_get_temp_dir(),'ii-review-');
        try {
            $zip=new \ZipArchive();
            $this->assertTrue($zip->open($file,\ZipArchive::CREATE|\ZipArchive::OVERWRITE));
            $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rel='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $zip->addFromString('xl/workbook.xml','<workbook xmlns="'.$ns.'" xmlns:r="'.$rel.'"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $cell=fn($col,$row,$v)=>'<c r="'.$col.$row.'" t="str"><v>'.htmlspecialchars($v,ENT_XML1).'</v></c>';
            $xml='<worksheet xmlns="'.$ns.'"><sheetData><row r="1">';
            $cols=array_merge(range('A','Z'),['AA','AB']);
            foreach(IdxDailyEvidenceWarehouse::HEADERS as $i=>$name) {
                $xml.=$cell($cols[$i],1,$name);
            }
            $xml.='</row>';
            foreach([2=>['BBCA','100','102','99','101','1000'],3=>['GOTOM','0','0','0','10','0']] as $n=>$vals) {
                $xml.='<row r="'.$n.'">';
                $data=['B'=>$vals[0],'F'=>$vals[1],'G'=>'01 Feb 2024','I'=>$vals[2],'J'=>$vals[3],'K'=>$vals[4],'M'=>$vals[5]];
                foreach($data as $col=>$v) {
                    $xml.=$cell($col,$n,$v);
                }
                $xml.='</row>';
            }
            $zip->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');
            $zip->close();
            $receipt=['source_url'=>IdxOfficialExportIntake::SOURCE,'terms_url'=>IdxOfficialExportIntake::TERMS,'document_sha256'=>hash_file('sha256',$file),
                'acquisition'=>'MANUAL_OFFICIAL_DOWNLOAD','usage'=>'PERSONAL_NONCOMMERCIAL_RESEARCH','rights_confirmed'=>true,'no_redistribution'=>true,
                'local_retention_confirmed'=>true,'direct_cost_idr'=>0,'reviewer'=>'Synthetic fixture','session_date'=>'2024-02-01','access_date'=>'2024-02-02',
                'format'=>'ORIGINAL_XLSX','sheet'=>'Sheet1','header_row'=>1];
            file_put_contents($review,json_encode($receipt,JSON_THROW_ON_ERROR));
            $warehouse=app(IdxDailyEvidenceWarehouse::class);
            $preview=$warehouse->ingest($owner->id,$file,$review);
            $this->assertSame(2,$preview['rows']);
            $this->assertSame(1,$preview['structural_candidates']);
            $this->assertSame(1,$preview['zero_volume']);
            $this->assertDatabaseCount('ii_idx_evidence_rows',0);
            $this->assertSame('STORED_EVIDENCE_ONLY',$warehouse->ingest($owner->id,$file,$review,true)['status']);
            $this->assertSame('UNCHANGED',$warehouse->ingest($owner->id,$file,$review,true)['status']);
            $this->assertDatabaseCount('ii_idx_evidence_rows',2);
            $this->assertFalse($warehouse->status($owner->id)['admitted']);
            $other=User::factory()->create(['ii_research_role'=>'analyst']);
            try {
                $warehouse->status($other->id);
                $this->fail('Other owner read accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('II_IDX_WAREHOUSE_OWNER_REQUIRED',$e->getMessage());
            }
            $receipt['reviewer']='Changed fixture';
            file_put_contents($review,json_encode($receipt,JSON_THROW_ON_ERROR));
            $this->expectExceptionMessage('II_IDX_WAREHOUSE_EXISTING_BATCH_CONFLICT');
            $warehouse->ingest($owner->id,$file,$review,true);
        } finally {
            unlink($file);
            unlink($review);
        }
    }
}
