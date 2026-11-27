<?php

namespace Tests\Feature;

use App\Services\IdxOfficialExportIntake;
use App\Services\IdxXlsxExportReader;
use Tests\TestCase;

final class IdxXlsxExportReaderTest extends TestCase
{
    private function workbook(string $path, bool $formula=false): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE));
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="'.$ns.'" xmlns:r="'.$rel.'"><sheets><sheet name="Summary" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml','<sst xmlns="'.$ns.'"><si><t>Code</t></si><si><t>BBCA</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="'.$ns.'"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>Close</t></is></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2">'.($formula ? '<f>100+1</f>' : '').'<v>101</v></c></row></sheetData></worksheet>');
        $zip->close();
    }

    public function test_original_xlsx_hash_and_reviewed_sheet_then_formula_rejected(): void
    {
        $file=tempnam(sys_get_temp_dir(),'ii-xlsx-fixture-');
        $review=tempnam(sys_get_temp_dir(),'ii-review-');
        try {
            $this->workbook($file);
            $r=['source_url'=>IdxOfficialExportIntake::SOURCE,'terms_url'=>IdxOfficialExportIntake::TERMS,
                'document_sha256'=>hash_file('sha256',$file),'acquisition'=>'MANUAL_OFFICIAL_DOWNLOAD',
                'usage'=>'PERSONAL_NONCOMMERCIAL_RESEARCH','rights_confirmed'=>true,'no_redistribution'=>true,
                'direct_cost_idr'=>0,'reviewer'=>'Synthetic fixture','session_date'=>'2024-02-01','access_date'=>'2024-02-02',
                'currency'=>'IDR','basis'=>'AS_TRADED','price_market'=>'REGULAR','format'=>'ORIGINAL_XLSX',
                'numeric_format'=>'PLAIN_DECIMAL','sheet'=>'Summary','header_row'=>1,'columns'=>['ticker'=>'Code','close'=>'Close']];
            file_put_contents($review,json_encode($r,JSON_THROW_ON_ERROR));
            $result=app(IdxOfficialExportIntake::class)->inspect($file,$review,'BBCA');
            $this->assertSame(101.0,$result['observations'][0]['values']['close']);
            $this->assertSame('XLSX worksheet Summary row 2',$result['observations'][0]['locator']);
            $this->assertSame($r['document_sha256'],$result['document_sha256']);
            $this->assertFalse($result['admitted']);
            $this->workbook($file,true);
            $this->expectExceptionMessage('II_IDX_XLSX_FORMULA_OR_MERGE');
            app(IdxXlsxExportReader::class)->read(file_get_contents($file),'Summary',1);
        } finally {
            unlink($file);
            unlink($review);
        }
    }
}
