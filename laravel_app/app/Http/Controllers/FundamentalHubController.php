<?php

namespace App\Http\Controllers;

use App\Models\IssuerDocument;
use App\Models\IssuerImportBatch;
use App\Models\IssuerMarketPrice;
use App\Models\IssuerCorporateAction;
use App\Models\IssuerShareBasisReview;
use App\Services\FundamentalSnapshotService;
use App\Services\IssuerCoverageService;
use App\Services\IdxFilingEvidenceService;
use App\Services\IssuerBatchIngestionService;
use App\Services\IssuerFundamentalImportService;
use App\Services\IssuerImportMetadataService;
use App\Services\IssuerMarketPriceService;
use App\Services\MarketPriceQualityService;
use App\Services\IssuerMarketPriceBatchImportService;
use App\Services\IssuerShareBasisReconciliationService;
use App\Services\SignalInsightService;
use App\Services\SecCompanyFactsNormalizationService;
use App\Services\SecOfficialCompanyFactsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use League\Csv\Writer;

class FundamentalHubController extends Controller
{
    public function index(FundamentalSnapshotService $fundamentals, SignalInsightService $signals, MarketPriceQualityService $priceQuality, IssuerCoverageService $coverageService)
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
        $priceReviews = Schema::hasTable('ii_issuer_market_prices')
            ? $priceQuality->latestByTicker((int) auth()->id())->values()
            : collect();
        $coverage = request()->query('workspace') === 'activation'
            ? $coverageService->report((int) auth()->id(), $records, $priceReviews)
            : null;
        $corporateActions = Schema::hasTable('ii_issuer_corporate_actions')
            ? IssuerCorporateAction::where('user_id', (int) auth()->id())->latest('effective_on')->limit(30)->get()
            : collect();
        $shareBasisReviews = Schema::hasTable('ii_issuer_share_basis_reviews')
            ? IssuerShareBasisReview::where('user_id', (int) auth()->id())->latest()->limit(30)->get()
            : collect();

        return response()->view('ii.fundamentals', compact('records', 'market', 'documents', 'batches', 'marketPrices', 'priceReviews', 'coverage', 'corporateActions', 'shareBasisReviews'))->header('Cache-Control', 'private, no-store');
    }

    /**
     * Explicit user-uploaded SEC companyfacts JSON -> reviewable normalized CSV.
     * No network, DB/storage writes, source approval or trading permission.
     */
    public function normalizeSecCompanyFacts(Request $request, SecCompanyFactsNormalizationService $normalizer)
    {
        $data = $request->validate([
            'sec_companyfacts_json' => ['required', 'file', 'mimes:json,txt', 'max:20480'],
        ]);
        try {
            $result = $normalizer->convert($data['sec_companyfacts_json']);
        } catch (\InvalidArgumentException $exception) {
            return redirect()->route('ii.fundamentals.index', ['workspace' => 'activation'])
                ->withErrors(['sec_companyfacts_json' => $exception->getMessage()]);
        }

        return $this->secCsvResponse($result);
    }

    public function fetchSecCompanyFacts(Request $request, SecOfficialCompanyFactsService $sec)
    {
        $data = $request->validate([
            'sec_cik' => ['required', 'string', 'regex:/^\\d{1,10}$/'],
            'sec_contact_email' => ['required', 'email:rfc', 'max:180'],
            'sec_contact_confirmed' => ['accepted'],
        ]);

        try {
            $result = $sec->downloadAndNormalize($data['sec_cik'], $data['sec_contact_email']);
        } catch (\InvalidArgumentException $exception) {
            return redirect()->route('ii.fundamentals.index', ['workspace' => 'activation'])
                ->withErrors(['sec_cik' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            Log::warning('II SEC official source unavailable', ['type' => get_class($exception)]);

            return redirect()->route('ii.fundamentals.index', ['workspace' => 'activation'])
                ->withErrors(['sec_cik' => 'Pengambilan data SEC gagal; tidak ada data yang disimpan.']);
        }

        return $this->secCsvResponse($result);
    }

    private function secCsvResponse(array $result)
    {
        $writer = Writer::createFromString();
        $fields = ['fiscal_year', 'period_end', 'restated', 'revenue',
            'net_income_attributable', 'total_assets', 'total_equity',
            'equity_attributable', 'operating_cash_flow', 'shares_outstanding',
            'total_debt', 'cash_and_equivalents', 'ebitda', 'market_price', 'market_price_as_of'];
        $writer->insertOne($fields);
        foreach ($result['periods'] as $period) {
            $writer->insertOne(array_map(fn ($key) => $this->csvCell($period[$key]), $fields));
        }

        return response($writer->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_SEC_COMPANYFACTS_CIK'.$result['cik'].'_REVIEW.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-II-Source-Sha256' => $result['source_sha256'],
            'X-II-Review-Required' => 'true',
            'X-II-Source-Origin' => $result['fetch_origin'] ?? 'USER_PROVIDED_JSON',
        ]);
    }

    public function exportCoverage(FundamentalSnapshotService $fundamentals, MarketPriceQualityService $priceQuality, IssuerCoverageService $coverageService)
    {
        $userId = (int) auth()->id();
        $records = $fundamentals->latestByTicker($userId);
        $priceReviews = Schema::hasTable('ii_issuer_market_prices')
            ? $priceQuality->latestByTicker($userId)->values() : collect();
        $coverage = $coverageService->report($userId, $records, $priceReviews);

        $writer = Writer::createFromString();
        $writer->insertOne(['ticker', 'issuer_name', 'documents', 'periods', 'latest_period',
            'source_name', 'source_url', 'source_claim_user_attested', 'numeric_claim_user_attested',
            'usage_rights_claimed', 'price_date', 'price_status', 'valuation_status',
            'next_action', 'gate', 'decision', 'trading_authority']);
        foreach ($coverage['rows'] as $row) {
            $writer->insertOne(array_map($this->csvCell(...), [
                $row['ticker'], $row['issuer_name'], $row['documents'], $row['periods'],
                $row['latest_period'], $row['source_name'], $row['source_url'],
                $row['source_claim'], $row['numeric_claim'], $row['usage_rights'],
                $row['price_date'], $row['price_status'], $row['valuation_status'],
                $row['next_action'], $row['gate'], $row['decision'], $row['trading_authority'],
            ]));
        }

        return response($writer->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_ISSUER_SOURCE_ACTIVATION_V9.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Offline BEI evidence-only path: no URL fetch, PDF OCR, or numeric admission.
     * The user attests that the uploaded PDF came from the linked official URL.
     */
    public function importIdxFiling(Request $request, IdxFilingEvidenceService $evidence)
    {
        $data = $request->validate([
            'idx_ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9]{4,6}(\.JK)?$/i'],
            'idx_issuer_name' => ['required', 'string', 'max:200'],
            'idx_report_period' => ['required', 'string', 'regex:/^(FY|Q[1-4]|H[12]) 20\d{2}$/i'],
            'idx_report_date' => ['required', 'date_format:Y-m-d'],
            'idx_currency' => ['required', 'in:IDR,USD'],
            'idx_consolidated' => ['nullable', 'boolean'],
            'idx_source_url' => ['required', 'url', 'max:1000'],
            'idx_rights_confirmed' => ['accepted'],
            'idx_filing_file' => ['required', 'file', 'max:20480'],
        ]);

        try {
            $document = $evidence->import((int) auth()->id(), $data, $data['idx_filing_file']);
        } catch (\InvalidArgumentException $exception) {
            return redirect()->route('ii.fundamentals.index', ['workspace' => 'activation'])
                ->withErrors(['idx_filing_file' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            Log::warning('II IDX evidence intake error', ['type' => get_class($exception)]);

            return redirect()->route('ii.fundamentals.index', ['workspace' => 'activation'])
                ->withErrors(['idx_filing_file' => 'Bukti BEI gagal disimpan; dokumen dan data lama tetap aman.']);
        }

        return redirect()->route('ii.fundamentals.index', ['workspace' => 'activation'])
            ->with('fundamental_notice', 'Bukti PDF '.$document->issuer_code.' tersimpan sebagai DOCUMENT_ONLY (SHA-256 '.substr($document->sha256, 0, 16).'…). URL dan asal dokumen masih berdasarkan pernyataan pengguna; angka fundamental belum diekstraksi.');
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


    public function previewMarketPriceBatch(Request $request, IssuerMarketPriceBatchImportService $importer)
    {
        $request->validate(['market_prices_csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        try {
            $result = $importer->preview((int) auth()->id(), $request->file('market_prices_csv'));
        } catch (\InvalidArgumentException $exception) {
            return redirect()->route('ii.fundamentals.index', ['workspace' => 'prices'])
                ->withErrors(['market_prices_csv' => $exception->getMessage()]);
        }

        return redirect()->route('ii.fundamentals.index', ['workspace' => 'prices'])
            ->with('market_price_preview', $result);
    }

    public function importMarketPriceBatch(Request $request, IssuerMarketPriceBatchImportService $importer)
    {
        $data = $request->validate([
            'market_prices_csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'source_name' => ['required', 'string', 'max:160'],
            'publisher' => ['nullable', 'string', 'max:160'],
            'source_url' => ['required', 'url', 'max:1000'],
            'license_status' => ['required', 'in:public_disclosure,licensed,user_provided'],
            'usage_rights' => ['required', 'in:internal_research,licensed_internal,redistribution_permitted'],
            'source_authenticity_status' => ['required', 'in:official_publisher,official_exchange,licensed_provider,user_attested'],
            'provenance_confidence' => ['required', 'in:high,medium,low'],
            'rights_confirmed' => ['accepted'],
        ]);
        try {
            $result = $importer->import((int) auth()->id(), $data['market_prices_csv'], $request->except('market_prices_csv'));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['market_prices_csv' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            Log::warning('II market price batch failed', ['type' => get_class($exception), 'message' => $exception->getMessage()]);

            return back()->withErrors(['market_prices_csv' => 'Batch tidak disimpan; seluruh harga lama tetap utuh.']);
        }

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice',
            $result['imported'].' harga tersimpan atomik; SHA-256 batch '.substr($result['sha256'], 0, 16).'…');
    }

    public function marketPriceBatchExample()
    {
        $csv = "ticker,observed_on,currency,price,adjustment_status\n"
            ."DEMO1.JK,2025-01-02,IDR,1000.50,unadjusted_close\n"
            ."DEMO2.JK,2025-01-02,IDR,250,split_adjusted\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_SYNTHETIC_EOD_PRICE_TEMPLATE.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function storeCorporateAction(Request $request, IssuerShareBasisReconciliationService $basis)
    {
        try {
            $action = $basis->recordAction((int) auth()->id(), $request->all());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['corporate_action' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice',
            'Aksi '.$action->issuer_code.' '.$action->effective_on->toDateString().' disimpan immutable; review terdahulu menjadi stale bila intervalnya terdampak.');
    }

    public function reviewShareBasis(Request $request, IssuerShareBasisReconciliationService $basis)
    {
        try {
            $review = $basis->attestCoverage((int) auth()->id(), $request->all());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['share_basis' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('ii.fundamentals.index')->with('fundamental_notice',
            'Review basis saham '.$review->issuer_code.' dicatat untuk '.$review->price_observed_on->toDateString().'; hak dan status valuasi tetap dibatasi.');
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
