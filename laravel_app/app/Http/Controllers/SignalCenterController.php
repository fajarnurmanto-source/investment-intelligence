<?php

namespace App\Http\Controllers;

use App\Services\SignalInsightService;
use App\Services\FundamentalSnapshotService;
use App\Services\MarketSourceMetadataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SignalCenterController extends Controller
{
    public function index(SignalInsightService $signals, FundamentalSnapshotService $fundamentals)
    {
        return response()
            ->view('ii.signals', [
                'signals' => $signals->list((int) auth()->id()),
                'fundamentals' => $fundamentals->latestByTicker((int) auth()->id()),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request, SignalInsightService $signals)
    {
        $input = $request->validate([
            'ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'kind' => ['required', 'in:user_supplied,synthetic'],
            'source_name' => ['required', 'string', 'max:120'],
            'source_url' => ['nullable', 'url', 'max:400'],
            'rights_confirmed' => ['accepted'],
            'price_file' => ['required', 'file', 'max:1024'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'price_adjustment' => ['nullable', 'in:unknown,adjusted,unadjusted'],
            'session_timezone' => ['nullable', 'in:UTC,Asia/Jakarta,Asia/Singapore,Asia/Hong_Kong,Asia/Tokyo,Europe/London,America/New_York,Australia/Sydney'],
        ]);

        $ticker = strtoupper($input['ticker']);
        if ($input['kind'] === 'synthetic' && !str_starts_with($ticker, 'DEMO')) {
            return back()->withErrors(['ticker' => 'Data sintetis wajib memakai awalan ticker DEMO.'])->withInput();
        }
        if ($input['kind'] === 'user_supplied' && str_starts_with($ticker, 'DEMO')) {
            return back()->withErrors(['ticker' => 'Ticker DEMO hanya untuk data sintetis.'])->withInput();
        }

        $file = $request->file('price_file');
        if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return back()->withErrors(['price_file' => 'Unggah file .csv dengan kolom date,open,high,low,close,volume.'])->withInput();
        }

        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return back()->withErrors(['price_file' => 'File CSV tidak dapat dibuka.']);
        }

        try {
            $header = fgetcsv($handle);
            if (!is_array($header)) {
                throw new \InvalidArgumentException('CSV kosong.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $header = array_map(fn ($x) => strtolower(trim((string) $x)), $header);
            if ($header !== ['date', 'open', 'high', 'low', 'close', 'volume']) {
                throw new \InvalidArgumentException('Header harus persis: date,open,high,low,close,volume.');
            }
            $rows = [];
            $dates = [];
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) === 1 && trim((string) $line[0]) === '') {
                    continue;
                }
                if (count($line) !== 6) {
                    throw new \InvalidArgumentException('Setiap baris wajib berisi tepat enam kolom.');
                }
                $date = trim($line[0]);
                $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$dt || $dt->format('Y-m-d') !== $date || $date > $today || isset($dates[$date])) {
                    throw new \InvalidArgumentException('Tanggal tidak valid, duplikat, atau berada di masa depan: '.$date);
                }
                $dates[$date] = true;
                foreach (array_slice($line, 1) as $number) {
                    if (!is_numeric(trim($number)) || !is_finite((float) $number)) {
                        throw new \InvalidArgumentException('Harga/volume harus angka format desimal titik.');
                    }
                }
                [$open, $high, $low, $close, $volume] = array_map('floatval', array_slice($line, 1));
                if (min($open, $high, $low, $close) <= 0 || $low > min($open, $close)
                    || $high < max($open, $close) || $volume < 0 || floor($volume) != $volume) {
                    throw new \InvalidArgumentException('OHLC/volume tidak masuk akal. Periksa nilai tiap baris.');
                }
                $rows[] = compact('date', 'open', 'high', 'low', 'close', 'volume');
                if (count($rows) > 2000) {
                    throw new \InvalidArgumentException('Batas maksimal 2.000 baris per CSV.');
                }
            }
            if (count($rows) < 60) {
                throw new \InvalidArgumentException('Diperlukan minimal 60 sesi harga untuk SMA50, RSI14 dan ATR14.');
            }
            usort($rows, fn (array $a, array $b) => strcmp($a['date'], $b['date']));
            $document = [
                'schema' => 'ii.signal.vs7.r1',
                'ticker' => $ticker,
                'kind' => $input['kind'],
                'source_name' => $input['source_name'],
                'source_url' => $input['source_url'] ?? null,
                'imported_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s.u T'),
                'data_admission' => 'USER_IMPORTED_UNVERIFIED',
                'decision' => 'HOLD',
                'trading_authorized' => false,
                'rows' => $rows,
            ];
            // User declarations apply only to this new immutable version.
            // No legacy imports are altered, and declarations are not verification.
            $document = array_merge($document, MarketSourceMetadataService::forImport($input));
            $signals->save((int) auth()->id(), $ticker, $document);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['price_file' => $exception->getMessage()])->withInput();
        } catch (\Throwable $exception) {
            Log::warning('II VS7 signal import error', ['type' => get_class($exception)]);
            return back()->withErrors(['price_file' => 'Penyimpanan gagal. Data lama tidak dihapus.'])->withInput();
        } finally {
            fclose($handle);
        }

        return redirect()->route('ii.signals.index')->with('signal_notice', 'Seri '.$ticker.' berhasil diimpor beserta deklarasi metadata dan indikator dihitung. Sumber serta deklarasi belum diverifikasi.');
    }

    public function example()
    {
        $lines = ['date,open,high,low,close,volume'];
        $start = (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Jakarta')))->modify('-125 days');
        $price = 100.0;
        for ($i = 0; $i < 126; $i++) {
            $date = $start->modify('+'.$i.' days');
            if ((int) $date->format('N') > 5) {
                continue;
            }
            $open = $price;
            $price = round($price * (1 + 0.0018 + sin($i * 0.47) * 0.009), 4);
            $high = round(max($open, $price) * 1.007, 4);
            $low = round(min($open, $price) * 0.993, 4);
            $lines[] = implode(',', [$date->format('Y-m-d'), $open, $high, $low, $price, 10000 + $i * 17]);
        }
        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_VS7_DEMO7_SYNTHETIC.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
