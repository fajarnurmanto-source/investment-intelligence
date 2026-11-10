<?php

namespace App\Http\Controllers;

use App\Models\IssuerDocument;
use App\Services\FundamentalSnapshotService;
use App\Services\IssuerFundamentalImportService;
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

        return response()->view('ii.fundamentals', compact('records', 'market', 'documents'))->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request, IssuerFundamentalImportService $importer)
    {
        $data = $request->validate([
            'ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'issuer_name' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'in:user_supplied,synthetic'],
            'report_period' => ['required', 'string', 'max:40'], 'report_date' => ['required', 'date'],
            'currency' => ['required', 'in:IDR,USD'], 'multiplier' => ['required', 'in:1,1000,1000000,1000000000'],
            'consolidated' => ['nullable', 'boolean'],
            'source_name' => ['required', 'string', 'max:160'], 'publisher' => ['nullable', 'string', 'max:160'],
            'source_url' => ['nullable', 'url', 'max:1000'], 'terms_url' => ['nullable', 'url', 'max:1000'],
            'license_status' => ['required', 'in:public_disclosure,licensed,user_provided,unknown'],
            'usage_rights' => ['required', 'in:internal_research,licensed_internal,redistribution_permitted,unknown'],
            'redistribution_allowed' => ['nullable', 'boolean'], 'rights_confirmed' => ['accepted'],
            'fundamental_file' => ['required', 'file', 'max:20480'],
        ]);
        $data['ticker'] = strtoupper($data['ticker']);
        if (($data['kind'] === 'synthetic') !== str_starts_with($data['ticker'], 'DEMO')) {
            return back()->withErrors(['ticker' => 'Ticker DEMO hanya untuk data sintetis; data sintetis wajib menggunakan ticker DEMO.'])->withInput();
        }
        if (($data['redistribution_allowed'] ?? false) && $data['usage_rights'] !== 'redistribution_permitted') {
            return back()->withErrors(['usage_rights' => 'Redistribusi hanya boleh ditandai jika hak redistribusi memang dinyatakan.'])->withInput();
        }
        try {
            $document = $importer->import((int) auth()->id(), $data, $request->file('fundamental_file'));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['fundamental_file' => $exception->getMessage()])->withInput();
        } catch (\Throwable $exception) {
            Log::warning('II issuer fundamentals import error', ['type' => get_class($exception), 'message' => $exception->getMessage()]);

            return back()->withErrors(['fundamental_file' => 'Penyimpanan gagal; data dan dokumen lama tidak dihapus.'])->withInput();
        }
        $suffix = $document->quality_status === 'DOCUMENT_ONLY' ? ' Dokumen diarsipkan; unggah CSV/XBRL untuk rasio.' : ' Normalisasi dan quality gate selesai.';

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice', 'Laporan '.$data['ticker'].' disimpan sebagai versi immutable.'.$suffix);
    }

    public function export(string $ticker, FundamentalSnapshotService $fundamentals)
    {
        $ticker = strtoupper($ticker);
        $record = $fundamentals->latestByTicker((int) auth()->id())[$ticker] ?? abort(404);
        $writer = Writer::createFromString();
        $writer->insertOne(['issuer_code', 'issuer_name', 'fiscal_year', 'period_end', 'revenue', 'net_income_attributable', 'total_assets', 'equity_attributable', 'operating_cash_flow', 'restated', 'source', 'sha256', 'license_status', 'usage_rights', 'quality_status', 'evidence_status', 'decision', 'trading_authority']);
        foreach ($record['years'] as $year) {
            $writer->insertOne([$record['ticker'], $this->csvSafe($record['issuer_name']), $year['year'], $year['period_end'], $this->csvNumber($year['revenue']), $this->csvNumber($year['net_income']), $this->csvNumber($year['total_assets']), $this->csvNumber($year['equity']), $this->csvNumber($year['operating_cash_flow']), $year['restated'] ? 'TRUE' : 'FALSE', $this->csvSafe($record['source_name']), $record['sha256'], $record['license_status'], $record['usage_rights'], $record['quality_status'], $record['gate'], $record['decision'], $record['trading_authority']]);
        }

        return response($writer->toString(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="II_'.$ticker.'_FUNDAMENTALS.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function example()
    {
        $csv = <<<'CSV'
fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price
2023,2023-12-31,false,149216,24560,287042,135744,60581,99062216600,47699,29007,77579,
2024,2024-12-31,false,149967,23649,299675,142094,61600,99062216600,52909,33905,75579,
CSV;

        return response($csv."\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="II_ISSUER_FUNDAMENTAL_TEMPLATE.csv"', 'Cache-Control' => 'private, no-store']);
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    private function csvNumber(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
