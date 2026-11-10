<?php

namespace App\Http\Controllers;

use App\Models\IssuerDocument;
use App\Models\IssuerImportBatch;
use App\Models\IssuerMarketPrice;
use App\Services\FundamentalSnapshotService;
use App\Services\IssuerBatchIngestionService;
use App\Services\IssuerFundamentalImportService;
use App\Services\IssuerImportMetadataService;
use App\Services\IssuerMarketPriceService;
use App\Services\SignalInsightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use League\Csv\Writer;

class FundamentalHubController extends Controller
{
    public function index(FundamentalSnapshotService $fundamentals, SignalInsightService $signals)
    {
        $records = $fundamentals->latestByTicker((int) auth()->id());
        $market = [];
        foreach ($signals->list((int) auth()->id()) as $signal) {
            $market[$signal['ticker']] = $signal;
        }
        $documents = Schema::hasTable('ii_issuer_documents')
            ? IssuerDocument::with('source')->where('user_id', (int) auth()->id())->orderByDesc('imported_at')->limit(50)->get()
            : collect();
        $batches = Schema::hasTable('ii_issuer_import_batches')
            ? IssuerImportBatch::with('items')->where('user_id', (int) auth()->id())->latest()->limit(20)->get()
            : collect();
        $marketPrices = Schema::hasTable('ii_issuer_market_prices')
            ? IssuerMarketPrice::where('user_id', (int) auth()->id())->latest('observed_on')->limit(30)->get()
            : collect();

        return response()->view('ii.fundamentals', compact('records', 'market', 'documents', 'batches', 'marketPrices'))->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request, IssuerFundamentalImportService $importer, IssuerImportMetadataService $metadata)
    {
        $request->validate(['fundamental_file' => ['required', 'file', 'max:20480']]);
        try {
            $data = $metadata->validate($request->except('fundamental_file'));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['fundamental_file' => $exception->getMessage()])->withInput();
        }
        try {
            $document = $importer->import((int) auth()->id(), $data, $request->file('fundamental_file'));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['fundamental_file' => $exception->getMessage()])->withInput();
        } catch (\Throwable $exception) {
            Log::warning('II issuer fundamentals import error', ['type' => get_class($exception), 'message' => $exception->getMessage()]);

            return back()->withErrors(['fundamental_file' => 'Penyimpanan gagal; data dan dokumen lama tidak dihapus.'])->withInput();
        }
        $suffix = match ($document->quality_status) {
            'DOCUMENT_ONLY' => ' Dokumen diarsipkan; unggah CSV/XBRL untuk rasio.',
            'UNSUPPORTED_XBRL' => ' Taxonomy belum didukung; dokumen ditahan dan tidak menghasilkan angka. Gunakan CSV normalisasi.',
            default => ' Normalisasi dan gate kelengkapan selesai.',
        };

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice', 'Laporan '.$data['ticker'].' disimpan sebagai versi immutable.'.$suffix);
    }

    public function importBatch(Request $request, IssuerBatchIngestionService $batches)
    {
        $data = $request->validate([
            'manifest' => ['required', 'file', 'max:1024'],
            'issuer_files' => ['required', 'array', 'min:1', 'max:20'],
            'issuer_files.*' => ['required', 'file', 'max:20480'],
        ]);
        try {
            $batch = $batches->create((int) auth()->id(), $data['manifest'], $data['issuer_files']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['manifest' => $exception->getMessage()]);
        }

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice', 'Batch '.$batch->id.' diterima; setiap item diproses dan diaudit terpisah.');
    }

    public function retryBatch(string $batchId, IssuerBatchIngestionService $batches)
    {
        $batch = IssuerImportBatch::where('user_id', (int) auth()->id())->findOrFail($batchId);
        $count = $batches->retryFailed($batch);

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice', $count.' item gagal dijadwalkan ulang.');
    }

    public function storeMarketPrice(Request $request, IssuerMarketPriceService $prices)
    {
        try {
            $price = $prices->store((int) auth()->id(), $request->all());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['market_price' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice', 'Harga '.$price->issuer_code.' tanggal '.$price->observed_on->toDateString().' disimpan dengan provenance terpisah.');
    }

    public function export(string $ticker, FundamentalSnapshotService $fundamentals)
    {
        $ticker = strtoupper($ticker);
        $record = $fundamentals->latestByTicker((int) auth()->id())[$ticker] ?? abort(404);
        $writer = Writer::createFromString();
        $writer->insertOne(['issuer_code', 'issuer_name', 'fiscal_year', 'period_end', 'revenue', 'net_income_attributable', 'total_assets', 'total_equity', 'equity_attributable', 'operating_cash_flow', 'shares_outstanding', 'total_debt', 'cash_and_equivalents', 'ebitda', 'market_price', 'market_price_as_of', 'restated', 'source', 'sha256', 'license_status', 'usage_rights', 'completeness_status', 'completeness_score', 'source_authenticity', 'numeric_reconciliation', 'licensing_assessment', 'provenance_confidence', 'evidence_status', 'decision', 'trading_authority']);
        foreach ($record['years'] as $year) {
            $writer->insertOne(array_map($this->csvCell(...), [$record['ticker'], $record['issuer_name'], $year['year'], $year['period_end'], $year['revenue'], $year['net_income'], $year['total_assets'], $year['total_equity'], $year['equity'], $year['operating_cash_flow'], $year['shares_outstanding'], $year['total_debt'], $year['cash_and_equivalents'], $year['ebitda'], $year['market_price'], $year['market_price_as_of'], $year['restated'] ? 'TRUE' : 'FALSE', $record['source_name'], $record['sha256'], $record['license_status'], $record['usage_rights'], $record['quality_status'], $record['data_quality_score'], $record['source_authenticity_status'], $record['numeric_reconciliation_status'], $record['licensing_assessment'], $record['provenance_confidence'], $record['gate'], $record['decision'], $record['trading_authority']]));
        }

        return response($writer->toString(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="II_'.$ticker.'_FUNDAMENTALS.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function example()
    {
        $csv = <<<'CSV'
fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of
2023,2023-12-31,false,100000,9000,250000,145000,120000,12500,1000000000,55000,18000,32000,,
2024,2024-12-31,false,108000,9800,264000,152000,126000,13100,1000000000,57000,19500,34000,,
CSV;

        return response($csv."\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="II_ISSUER_FUNDAMENTAL_SYNTHETIC_TEMPLATE.csv"', 'Cache-Control' => 'private, no-store']);
    }

    public function batchExample()
    {
        $manifest = [
            'schema' => 'ii.issuer-batch.v2',
            'items' => [[
                'file' => 'DEMO_ONE.csv',
                'metadata' => [
                    'ticker' => 'DEMO1', 'issuer_name' => 'Synthetic Demo One', 'sector' => 'Demo', 'kind' => 'synthetic',
                    'report_period' => 'FY 2024', 'fiscal_period_type' => 'annual', 'report_date' => '2025-03-01',
                    'currency' => 'IDR', 'multiplier' => '1000000000', 'consolidated' => true,
                    'source_name' => 'Synthetic training template', 'publisher' => 'User', 'source_url' => null, 'terms_url' => null,
                    'license_status' => 'user_provided', 'usage_rights' => 'internal_research',
                    'source_authenticity_status' => 'user_attested', 'numeric_reconciliation_status' => 'user_attested',
                    'provenance_confidence' => 'low', 'redistribution_allowed' => false, 'rights_confirmed' => true,
                ],
            ]],
        ];

        return response(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_ISSUER_BATCH_SYNTHETIC_MANIFEST.json"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            if ((float) $value === 0.0) {
                return '0';
            }

            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }
        $text = (string) $value;

        return preg_match('/^\s*[=+\-@]/u', $text) ? "'".$text : $text;
    }
}
