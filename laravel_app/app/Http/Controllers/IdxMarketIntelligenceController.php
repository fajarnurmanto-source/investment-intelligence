<?php

namespace App\Http\Controllers;

use App\Services\IdxOfficialExportIntake;
use App\Services\IdxDailyEvidenceWarehouse;
use App\Services\IdxSessionIntelligence;
use App\Services\IdxMultisessionIntelligence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class IdxMarketIntelligenceController extends Controller
{
    private function localOnly(Request $request): void
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1'], true), 403);
    }

    public function index(Request $request)
    {
        $this->localOnly($request);
        return $this->page();
    }

    public function analyze(Request $request, IdxSessionIntelligence $engine)
    {
        $this->localOnly($request);
        if ($request->input('mode') === 'liquidity') {
            $warehouse = app(IdxDailyEvidenceWarehouse::class);
            $owner = (int) $request->user()->id;
            abort_unless($warehouse->isOwner($owner), 403);
            try {
                return $this->page(null, null, 200, null, null, $warehouse->historicalLiquidity($owner));
            } catch (\InvalidArgumentException) {
                return $this->page(null, 'Bukti arsip belum memenuhi syarat untuk analisis aktivitas.', 422);
            } catch (\Throwable) {
                return $this->page(null, 'Analisis aktivitas belum dapat dijalankan.', 503);
            }
        }
        if ($request->input('mode') === 'momentum') {
            $warehouse = app(IdxDailyEvidenceWarehouse::class);
            $owner = (int) $request->user()->id;
            abort_unless($warehouse->isOwner($owner), 403);
            try {
                return $this->page(null, null, 200, null, $warehouse->historicalMomentum($owner));
            } catch (\InvalidArgumentException) {
                return $this->page(null, 'Bukti arsip belum memenuhi syarat untuk analisis momentum.', 422);
            } catch (\Throwable) {
                return $this->page(null, 'Analisis momentum belum dapat dijalankan.', 503);
            }
        }
        if ($request->input('mode') === 'history') {
            return $this->history($request);
        }
        if ($request->hasFile('workbooks')) {
            return $this->compare($request);
        }
        $validator = Validator::make($request->all(), [
            'workbook' => ['required', 'file', 'max:19531'],
            'rights' => ['accepted'],
        ]);
        if ($validator->fails()) {
            return $this->page(null, 'Pilih file Excel ringkasan saham (maksimal 19 MB) dan konfirmasikan izin penggunaan data.', 422);
        }
        try {
            $file = $request->file('workbook');
            if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
                return $this->page(null, 'Gunakan file asli berformat .xlsx.', 422);
            }
            $raw = file_get_contents($file->getRealPath(), false, null, 0, IdxOfficialExportIntake::MAX_BYTES + 1);
            if (!is_string($raw) || $raw === '' || strlen($raw) > IdxOfficialExportIntake::MAX_BYTES) {
                return $this->page(null, 'File tidak dapat dibaca atau terlalu besar.', 422);
            }
            return $this->page($engine->analyze($raw));
        } catch (\InvalidArgumentException) {
            return $this->page(null, 'Format atau isi file tidak sesuai dengan ringkasan saham harian. Gunakan file Excel asli BEI untuk satu tanggal perdagangan.', 422);
        } catch (\Throwable) {
            return $this->page(null, 'Analisis belum dapat dijalankan. Periksa kesiapan aplikasi atau coba kembali.', 503);
        }
    }

    private function compare(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'workbooks' => ['required', 'array', 'min:2', 'max:5'],
            'workbooks.*' => ['required', 'file', 'max:19531'],
            'rights' => ['accepted'],
        ]);
        if ($validator->fails()) {
            return $this->page(null, 'Pilih 2–5 file Excel dan konfirmasikan hak penggunaan.', 422);
        }
        try {
            $files = $request->file('workbooks');
            $total = 0;
            $raw = [];
            foreach ($files as $file) {
                if (strtolower($file->getClientOriginalExtension()) !== 'xlsx'
                    || ($total += $file->getSize()) > IdxOfficialExportIntake::MAX_BYTES) {
                    throw new \InvalidArgumentException('Invalid file.');
                }
                $raw[] = file_get_contents($file->getRealPath(), false, null, 0, IdxOfficialExportIntake::MAX_BYTES + 1);
            }
            return $this->page(null, null, 200, app(IdxMultisessionIntelligence::class)->analyze($raw));
        } catch (\InvalidArgumentException) {
            return $this->page(null, 'Gunakan 2–5 file asli BEI dari tanggal berbeda yang telah selesai, dengan total maksimal 19 MB.', 422);
        } catch (\Throwable) {
            return $this->page(null, 'Perbandingan belum dapat dijalankan. Periksa file atau coba kembali.', 503);
        }
    }

    private function history(Request $request)
    {
        $warehouse = app(IdxDailyEvidenceWarehouse::class);
        $owner = (int) $request->user()->id;
        abort_unless($warehouse->isOwner($owner), 403);
        $validator = Validator::make($request->all(), [
            'dates' => ['required', 'array', 'min:2', 'max:5'],
            'dates.*' => ['required', 'string', 'date_format:Y-m-d', 'distinct'],
        ]);
        if ($validator->fails()) {
            return $this->page(null, 'Pilih 2–5 tanggal arsip yang berbeda.', 422);
        }
        try {
            return $this->page(null, null, 200, $warehouse->historicalCompare($owner, $validator->validated()['dates']));
        } catch (\InvalidArgumentException) {
            return $this->page(null, 'Arsip tidak tersedia atau bukti hak penggunaan tidak memenuhi syarat.', 422);
        } catch (\Throwable) {
            return $this->page(null, 'Arsip belum dapat diperiksa. Coba kembali.', 503);
        }
    }

    private function page(?array $result = null, ?string $notice = null, int $status = 200, ?array $comparison = null, ?array $momentum = null, ?array $liquidity = null)
    {
        $warehouse = app(IdxDailyEvidenceWarehouse::class);
        $owner = (int) auth()->id();
        $archive = null;
        if ($warehouse->isOwner($owner)) {
            $archive = $warehouse->status($owner);
        }
        return response()->view('ii.market-intelligence', compact('result', 'notice', 'comparison', 'archive', 'momentum', 'liquidity'), $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
