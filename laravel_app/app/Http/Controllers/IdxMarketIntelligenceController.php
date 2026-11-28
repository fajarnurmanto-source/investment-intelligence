<?php

namespace App\Http\Controllers;

use App\Services\IdxOfficialExportIntake;
use App\Services\IdxSessionIntelligence;
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

    private function page(?array $result = null, ?string $notice = null, int $status = 200)
    {
        return response()->view('ii.market-intelligence', compact('result', 'notice'), $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
