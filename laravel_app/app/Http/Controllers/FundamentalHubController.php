<?php

namespace App\Http\Controllers;

use App\Services\FundamentalSnapshotService;
use App\Services\SignalInsightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FundamentalHubController extends Controller
{
    public function index(FundamentalSnapshotService $fundamentals, SignalInsightService $signals)
    {
        $records = $fundamentals->latestByTicker((int) auth()->id());
        $market = [];
        foreach ($signals->list((int) auth()->id()) as $signal) {
            $market[$signal['ticker']] = $signal;
        }
        return response()->view('ii.fundamentals', compact('records', 'market'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request, FundamentalSnapshotService $fundamentals)
    {
        $data = $request->validate([
            'ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'kind' => ['required', 'in:user_supplied,synthetic'],
            'currency' => ['required', 'in:IDR,USD'],
            'multiplier' => ['required', 'in:1,1000,1000000,1000000000'],
            'source_name' => ['required', 'string', 'max:160'],
            'source_url' => ['nullable', 'url', 'max:400'],
            'rights_confirmed' => ['accepted'],
            'fundamental_file' => ['required', 'file', 'max:1024'],
        ]);
        $ticker = strtoupper($data['ticker']);
        if (($data['kind'] === 'synthetic') !== str_starts_with($ticker, 'DEMO')) {
            return back()->withErrors(['ticker' => 'Ticker DEMO hanya untuk data sintetis; data sintetis wajib menggunakan ticker DEMO.'])->withInput();
        }

        $file = $request->file('fundamental_file');
        if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return back()->withErrors(['fundamental_file' => 'Gunakan file .csv sesuai template.'])->withInput();
        }
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return back()->withErrors(['fundamental_file' => 'File tidak dapat dibaca.'])->withInput();
        }

        try {
            $header = fgetcsv($handle);
            if (!is_array($header)) {
                throw new \InvalidArgumentException('CSV kosong.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $header = array_map(fn ($x) => strtolower(trim((string) $x)), $header);
            $expected = ['year','revenue','net_income_attributable','total_assets',
                'equity_attributable','operating_cash_flow','shares_outstanding'];
            if ($header !== $expected) {
                throw new \InvalidArgumentException('Header tidak sesuai template. Unduh contoh CSV sebagai acuan.');
            }
            $years = [];
            $unique = [];
            $thisYear = (int) now('Asia/Jakarta')->format('Y');
            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) === 1 && trim((string) $line[0]) === '') {
                    continue;
                }
                if (count($line) !== 7) {
                    throw new \InvalidArgumentException('Setiap baris harus memuat tujuh kolom.');
                }
                $year = trim((string) $line[0]);
                if (!preg_match('/^\d{4}$/', $year) || (int) $year < 1990 || (int) $year > $thisYear || isset($unique[$year])) {
                    throw new \InvalidArgumentException('Tahun fiskal tidak valid atau duplikat: '.$year);
                }
                $unique[$year] = true;
                foreach (array_slice($line, 1) as $number) {
                    if (!is_numeric(trim((string) $number)) || !is_finite((float) $number)) {
                        throw new \InvalidArgumentException('Angka laporan harus menggunakan desimal titik, tanpa pemisah ribuan.');
                    }
                }
                [$revenue, $netIncome, $assets, $equity, $ocf, $shares] = array_map('floatval', array_slice($line, 1));
                if ($revenue <= 0 || $assets <= 0 || $shares <= 0 || $assets < abs($equity)
                    || floor($shares) != $shares || abs($netIncome) > 1.0e18) {
                    throw new \InvalidArgumentException('Periksa revenue, assets, equity dan jumlah saham pada CSV.');
                }
                $years[] = [
                    'year' => (int) $year, 'revenue' => $revenue,
                    'net_income_attributable' => $netIncome, 'total_assets' => $assets,
                    'equity_attributable' => $equity, 'operating_cash_flow' => $ocf,
                    'shares_outstanding' => $shares,
                ];
                if (count($years) > 10) {
                    throw new \InvalidArgumentException('Maksimal 10 tahun fiskal per unggahan.');
                }
            }
            if (count($years) < 2) {
                throw new \InvalidArgumentException('Dibutuhkan minimal dua tahun untuk menghitung pertumbuhan.');
            }
            usort($years, fn (array $a, array $b) => $a['year'] <=> $b['year']);
            $document = [
                'schema' => 'ii.fundamentals.vs7.r2',
                'ticker' => $ticker, 'kind' => $data['kind'],
                'currency' => $data['currency'], 'multiplier' => (int) $data['multiplier'],
                'source_name' => $data['source_name'], 'source_url' => $data['source_url'] ?? null,
                'imported_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s.u T'),
                'evidence' => 'USER_IMPORTED_UNVERIFIED', 'decision' => 'HOLD',
                'trading_authorized' => false, 'years' => $years,
            ];
            $fundamentals->save((int) auth()->id(), $document);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['fundamental_file' => $exception->getMessage()])->withInput();
        } catch (\Throwable $exception) {
            Log::warning('II VS7 R2 fundamentals import error', ['type' => get_class($exception)]);
            return back()->withErrors(['fundamental_file' => 'Penyimpanan gagal; data lama tidak dihapus.'])->withInput();
        } finally {
            fclose($handle);
        }
        return redirect()->route('ii.fundamentals.index')
            ->with('fundamental_notice', 'Laporan '.$ticker.' disimpan dan rasio dihitung. Bukti belum diverifikasi.');
    }

    public function example()
    {
        $csv = <<<'CSV'
year,revenue,net_income_attributable,total_assets,equity_attributable,operating_cash_flow,shares_outstanding
2022,100000,9300,205000,132000,11600,120000000
2023,109000,10500,214000,141000,11900,120000000
2024,118000,11700,225000,151000,12900,120000000
2025,129000,13600,238000,162000,14600,120000000
CSV;
        return response($csv."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_VS7_DEMO7_FUNDAMENTAL_SYNTHETIC.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
