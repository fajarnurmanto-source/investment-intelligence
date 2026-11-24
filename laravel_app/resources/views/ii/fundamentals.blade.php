@extends('ii.layout')
@section('title','Issuer Fundamentals')
@push('styles')
<style>
    .finance-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(320px,.9fr);gap:20px;align-items:start}.financial-card{margin-bottom:16px}
    .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin:16px 0}.stat{background:#0b192b;border-radius:10px;padding:13px}.stat span{font-size:.7rem;color:var(--muted);display:block}.stat strong{font-size:1.15rem;display:block;margin-top:5px}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:10px}.checkline{display:flex;gap:11px;align-items:flex-start;color:#d2e4f1;font-size:.82rem;font-weight:500}.checkline input{width:auto;margin-top:4px}.source{border-top:1px solid var(--line);margin-top:16px;padding-top:14px;color:var(--muted);font-size:.78rem;overflow-wrap:anywhere}.mono{font-family:ui-monospace,monospace;font-size:.72rem}
    @media(max-width:1160px){.finance-layout{display:block}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-card{margin-top:22px}}@media(max-width:560px){.split,.stat-grid{grid-template-columns:1fr}}

    .fundamental-workspaces{display:flex;flex-wrap:wrap;gap:9px;margin:18px 0 15px}
    .fundamental-workspaces a{border:1px solid var(--line);background:var(--panel);color:#cddff1;text-decoration:none;padding:10px 14px;border-radius:11px;font-weight:700;font-size:.86rem}
    .fundamental-workspaces a:hover,.fundamental-workspaces a[aria-current="page"]{background:#1b4150;color:#c1f9ff;border-color:#43899b}
    .workspace-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0 22px}
    .workspace-summary .stat{border:1px solid var(--line)}
    .finance-layout.single-view{grid-template-columns:minmax(0,1fr)}.finance-layout.single-view>div:last-child{display:none}
    .issuer-more{margin-top:16px;border-top:1px solid var(--line);padding-top:12px}
    .issuer-more summary{cursor:pointer;color:var(--cyan);font-weight:750;padding:7px 0}
    .issuer-more summary:focus-visible{outline:3px solid var(--cyan);outline-offset:3px}
    .status-line{padding:10px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}
    .status-line:last-child{border:0}.status-line strong{display:block}.status-line small{color:var(--muted);display:block}
    @media(max-width:900px){.workspace-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:560px){.fundamental-workspaces{display:grid;grid-template-columns:1fr 1fr}.fundamental-workspaces a{text-align:center;font-size:.75rem;padding:9px 5px}.workspace-summary{grid-template-columns:1fr 1fr;gap:7px}}
</style>
@endpush
@section('content')
    @php($fmt = fn($value, $decimals = 0) => $value === null ? 'N/A' : number_format((float)$value, $decimals))
    @php($requestedWorkspace = request()->query('workspace', 'overview'))
    @php($workspace = in_array($requestedWorkspace, ['overview', 'sources', 'prices', 'reconciliation', 'activation'], true) ? $requestedWorkspace : 'overview')
    @php($readyCount = collect($records)->filter(fn ($item) => str_starts_with((string) ($item['valuation_status'] ?? ''), 'AVAILABLE_'))->count())
    <p class="eyebrow">II / Multi-Issuer Intelligence V2</p><h1>Issuer Fundamentals</h1>
    <p class="lead">Impor dokumen, CSV normalisasi, atau XBRL yang diperoleh secara sah. II menyimpan versi sumber secara immutable, menormalkan satuan, menilai kelengkapan, dan hanya menghitung rasio saat semua input wajib tersedia.</p>
    <div class="boundary"><strong>NOT_ADMITTED · HOLD · TRADING_AUTHORITY=NONE.</strong> Keterbukaan publik tidak otomatis memberikan hak redistribusi atau otoritas trading. Rasio yang kekurangan input ditampilkan sebagai N/A—tanpa asumsi pengganti.</div>
    @if(session('fundamental_notice'))<div class="success" role="status">{{ session('fundamental_notice') }}</div>@endif
    @if($errors->any())<div class="error" role="alert"><strong>Impor belum disimpan.</strong> {{ $errors->first() }}</div>@endif
    <nav class="fundamental-workspaces" aria-label="Ruang kerja fundamental">
        <a href="{{ route('ii.fundamentals.index', ['workspace' => 'overview']) }}" @if($workspace === 'overview') aria-current="page" @endif>Ringkasan emiten</a>
        <a href="{{ route('ii.fundamentals.index', ['workspace' => 'sources']) }}" @if($workspace === 'sources') aria-current="page" @endif>Sumber &amp; Batch ingestion</a>
        <a href="{{ route('ii.fundamentals.index', ['workspace' => 'prices']) }}" @if($workspace === 'prices') aria-current="page" @endif>Harga pasar</a>
        <a href="{{ route('ii.fundamentals.index', ['workspace' => 'reconciliation']) }}" @if($workspace === 'reconciliation') aria-current="page" @endif>Rekonsiliasi</a>
        <a href="{{ route('ii.fundamentals.index', ['workspace' => 'activation']) }}" @if($workspace === 'activation') aria-current="page" @endif>Aktivasi Data</a>
    </nav>
    <div class="workspace-summary" aria-label="Ringkasan data pengguna">
        <div class="stat"><span>Emiten</span><strong>{{ count($records) }}</strong></div>
        <div class="stat"><span>Dokumen ditampilkan</span><strong>{{ $documents->count() }}</strong></div>
        <div class="stat"><span>Harga ditampilkan</span><strong>{{ $marketPrices->count() }}</strong></div>
        <div class="stat"><span>Valuasi tersedia</span><strong>{{ $readyCount }} / {{ count($records) }}</strong></div>
    </div>
    <div @class(['finance-layout', 'single-view' => in_array($workspace, ['overview', 'activation'], true)])><div>
        @if($workspace === 'activation')
        <section class="panel" aria-labelledby="v9-title">
            <span class="badge blue">V9 · EVIDENCE-LED INTAKE</span>
            <h2 id="v9-title" style="margin-top:12px">Aktivasi sumber dan cakupan emiten</h2>
            <p class="hint">Akun aktif: <strong>{{ auth()->user()->email }}</strong>. Untuk menambahkan emiten, gunakan proses SEC V11 di bawah, review CSV, lalu impor melalui tab <a href="{{ route('ii.fundamentals.index', ['workspace' => 'sources']) }}">Sumber &amp; Batch ingestion</a>. Data hasil unduhan tidak otomatis tersimpan sebagai emiten.</p>
            <p class="caption">Semua angka di bawah ini milik akun yang sedang login; tidak menghitung data milik akun lain. Jika akun baru, hasilnya bisa nol meskipun database sistem sudah menyimpan data dari akun berbeda. Data antarakun tidak dipindahkan otomatis karena isolasi kepemilikan. Status sumber adalah keterangan yang dilaporkan pengguna, bukan verifikasi independen.</p>
            <div class="stat-grid">
                <div class="stat"><span>Emiten dalam register akun</span><strong>{{ $coverage['issuer_count'] }}</strong></div>
                <div class="stat"><span>Emiten dengan periode angka</span><strong>{{ $coverage['has_fundamentals'] }}</strong></div>
                <div class="stat"><span>Emiten dengan harga bertanggal</span><strong>{{ $coverage['has_market_prices'] }}</strong></div>
                <div class="stat"><span>Butuh langkah lanjutan</span><strong>{{ $coverage['review_required'] }}</strong></div>
            </div>
            <a class="button" href="{{ route('ii.fundamentals.coverage.export') }}">Ekspor daftar aktivasi CSV</a>
            <div class="boundary" style="margin-top:16px"><strong>NOT_ADMITTED · HOLD · TRADING_AUTHORITY=NONE</strong> · Daftar ini adalah antrian akuisisi dan review data, bukan rekomendasi saham atau izin perdagangan.</div>
        </section>
        <section class="panel" aria-labelledby="source-guide-title" style="margin-top:16px">
            <h2 id="source-guide-title">Rute sumber resmi, tanpa scraping</h2>
            <p class="caption">Gunakan dokumen yang Anda unduh atau peroleh secara sah. II belum memiliki feed harga BEI yang telah dilisensikan.</p>
            <div class="status-line">
                <strong>Laporan keuangan dan tahunan emiten</strong>
                <small>Arsip laporan resmi BEI; pengguna dapat mengunggah PDF sebagai bukti, atau CSV/XBRL sesuai taksonomi yang didukung untuk pengolahan angka.</small>
                <a href="https://www.idx.co.id/id/perusahaan-tercatat/laporan-keuangan-dan-tahunan/" target="_blank" rel="noopener noreferrer">Buka arsip laporan BEI</a>
            </div>
            <div class="status-line">
                <strong>Taksonomi XBRL BEI</strong>
                <small>Cocokkan namespace, unit, konteks, dan cakupan parser sebelum memasukkan angka ke II. Taksonomi yang tidak didukung tetap HOLD.</small>
                <a href="https://www.idx.co.id/id/perusahaan-tercatat/xbrl" target="_blank" rel="noopener noreferrer">Lihat dokumentasi XBRL BEI</a>
            </div>
            <div class="status-line">
                <strong>Harga pasar berlisensi</strong>
                <small>Produk EOD dan historical perlu hak non-display atau hak lain sesuai penggunaan. Jangan mengambil harga melalui crawling situs BEI.</small>
                <a href="https://data.idx.co.id/" target="_blank" rel="noopener noreferrer">Lihat IDX Data Services</a>
            </div>
            <p class="hint">Ketentuan BEI melarang web scraping/crawling serta penggunaan komersial atau penyebaran kembali data tanpa izin yang diperlukan. <a href="https://www.idx.co.id/id/syarat-penggunaan/" target="_blank" rel="noopener noreferrer">Baca syarat penggunaan BEI</a>. Metadata hak penggunaan yang diinput pengguna belum merupakan bukti lisensi komersial.</p>
            <p><a href="{{ route('ii.fundamentals.index', ['workspace' => 'sources']) }}">Buka unggah laporan dan batch →</a> · <a href="{{ route('ii.fundamentals.index', ['workspace' => 'prices']) }}">Buka harga EOD dan preview →</a></p>
        </section>
        <section class="panel" aria-labelledby="byok-local-title" style="margin-top:16px">
            <h2 id="byok-local-title">Sumber pasar BYOK lokal</h2>
            <p>Data pasar diperoleh melalui API key milik pengguna langsung dari komputer ini.
            Feed BYOK berlisensi tidak dapat diekspor, disinkronkan, atau diarsipkan melalui alur fundamental.</p>
            <a class="button" href="{{ route('ii.byok.index') }}">Kelola API key pribadi &amp; lihat quote</a>
        </section>

        <section class="panel" aria-labelledby="sec-live-title" style="margin-top:16px">
            <span class="badge blue">V11 · SEC OFFICIAL API · ON-DEMAND</span>
            <h2 id="sec-live-title" style="margin-top:12px">Ambil laporan SEC langsung (CIK)</h2>
            <p class="caption">Untuk emiten US-GAAP. Masukkan nomor CIK perusahaan yang telah Anda periksa dan alamat email kontak penelitian yang benar. II mengakses <code>data.sec.gov</code> untuk satu CIK atas permintaan Anda; tidak menjelajahi situs, membuat jadwal crawling, atau memasukkan data langsung ke portofolio.</p>
            <form method="POST" action="{{ route('ii.fundamentals.sec-companyfacts.live') }}">@csrf
                <div class="split">
                    <div class="field"><label for="sec_cik">Nomor SEC CIK (1–10 digit)</label><input id="sec_cik" name="sec_cik" inputmode="numeric" pattern="[0-9]{1,10}" placeholder="0000320193" minlength="1" maxlength="10" required></div>
                    <div class="field"><label for="sec_contact_email">Email kontak penelitian untuk User-Agent SEC</label><input id="sec_contact_email" name="sec_contact_email" type="email" placeholder="contact@example.org" maxlength="180" required></div>
                </div>
                <div class="field"><label class="checkline"><input type="checkbox" name="sec_contact_confirmed" value="1" required>Alamat kontak tersebut benar, dapat dihubungi, dan boleh dikirim kepada SEC sebagai identitas permintaan saya.</label></div>
                <button type="submit">Ambil dari SEC dan unduh CSV untuk review</button>
            </form>
            <p class="hint" style="margin-top:12px">Paling banyak 2 permintaan/menit dari form ini; respons maks. 20 MB. Jika SEC mengembalikan 403/429 atau file terlalu besar, proses gagal tertutup dan tidak menyimpan data. File CSV memuat hanya fakta tahunan yang didukung dan tetap memerlukan review; tidak otomatis menjadi rekomendasi saham.</p>
            <p class="hint"><a href="https://www.sec.gov/search-filings/edgar-application-programming-interfaces" target="_blank" rel="noopener noreferrer">API publik SEC</a> · <a href="https://www.sec.gov/about/webmaster-frequently-asked-questions" target="_blank" rel="noopener noreferrer">Kebijakan User-Agent dan fair access SEC</a>. Tidak membutuhkan API key. Alamat kontak dikirim ke SEC dalam header User-Agent, tidak disimpan oleh fitur ini di database.</p>
        </section>
        <section class="panel" aria-labelledby="sec-normalize-title" style="margin-top:16px">
            <span class="badge blue">V10 · SEC US-GAAP · OFFLINE</span>
            <h2 id="sec-normalize-title" style="margin-top:12px">Normalisasi data riil SEC EDGAR</h2>
            <p class="caption">Untuk emiten Amerika Serikat yang melaporkan US-GAAP. Unduh berkas JSON <em>companyfacts</em> dari SEC menggunakan CIK yang tepat, lalu unggah ke sini. Tidak diperlukan API key; tidak ada koneksi otomatis atau pengisian angka buatan.</p>
            <form method="POST" action="{{ route('ii.fundamentals.sec-companyfacts.normalize') }}" enctype="multipart/form-data">@csrf
                <div class="field"><label for="sec_companyfacts_json">SEC companyfacts JSON (maks. 20 MB)</label><input id="sec_companyfacts_json" type="file" name="sec_companyfacts_json" accept=".json,application/json" required></div>
                <button type="submit">Unduh CSV normalisasi untuk review</button>
            </form>
            <p class="hint" style="margin-top:12px">CSV memakai kolom impor II yang sudah ada. Hanya fakta tahunan 10-K/10-K/A yang jelas unit, periode dan nomor aksesinya yang dipertimbangkan. Nilai yang tidak tersedia tetap kosong, termasuk utang, EBITDA dan harga pasar. Hasil belum diimpor ke database dan belum dinyatakan terverifikasi; periksa angka, tahun fiskal, saham, restatement, nama perusahaan, serta hak penggunaan sebelum mengunggah hasil ke <a href="{{ route('ii.fundamentals.index', ['workspace' => 'sources']) }}">formulir fundamental</a>.</p>
            <p class="hint"><a href="https://www.sec.gov/search-filings/edgar-application-programming-interfaces" target="_blank" rel="noopener noreferrer">Dokumentasi resmi SEC EDGAR</a> · <a href="https://www.sec.gov/about/webmaster-frequently-asked-questions" target="_blank" rel="noopener noreferrer">Ketentuan akses dan User-Agent SEC</a>. File tidak disimpan permanen dalam proses konversi. Konversi ini tidak menyediakan data BEI atau harga EOD.</p>
        </section>
        <section class="panel" aria-labelledby="coverage-queue-title" style="margin-top:16px">
            <h2 id="coverage-queue-title">Antrian cakupan berdasarkan data akun</h2>
            <p class="caption">Prioritas otomatis adalah kebutuhan bukti berikutnya, bukan verifikasi status sumber oleh pihak independen. Angka yang tidak tersedia tetap N/A.</p>
            <div class="tablewrap">
                <table>
                    <thead><tr><th>Emiten</th><th>Arsip dan periode</th><th>Klaim sumber/hak</th><th>Harga dan valuasi</th><th>Langkah berikutnya</th></tr></thead>
                    <tbody>
                        @forelse($coverage['rows'] as $item)
                        <tr>
                            <td><strong>{{ $item['ticker'] }}</strong><br><span class="caption">{{ $item['issuer_name'] }}</span></td>
                            <td>{{ $item['documents'] }} dokumen · {{ $item['periods'] }} periode<br><span class="caption">Terbaru: {{ $item['latest_period'] ?? 'belum ada' }}</span></td>
                            <td>{{ $item['source_claim'] }}<br><span class="caption">{{ $item['numeric_claim'] }} · {{ $item['usage_rights'] }}</span>
                                @if($item['source_url'])<br><a href="{{ $item['source_url'] }}" rel="noopener noreferrer" target="_blank">Tautan sumber terlapor</a>@endif
                            </td>
                            <td>{{ $item['price_date'] ?? 'N/A' }}<br><span class="caption">{{ $item['price_status'] }}</span><br><span class="caption">{{ $item['valuation_status'] }}</span></td>
                            <td><strong>{{ $item['next_action'] }}</strong><br><span class="caption">NOT_ADMITTED / HOLD</span></td>
                        </tr>
                        @empty
                        <tr><td colspan="5">Belum ada dokumen fundamental atau observasi harga yang tercatat untuk akun ini. Gunakan sumber resmi dan unggah berkas secara sah untuk membentuk antrian.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif
        @if($workspace === 'overview')
        <div class="section-header"><h2>Riwayat emiten · {{ count($records) }} ticker</h2><span class="caption">Data pengguna ini saja</span></div>
        @forelse($records as $record)
        <section class="panel financial-card">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:start">
                <div><span class="badge blue">{{ $record['quality_status'] }} · COMPLETENESS {{ $record['data_quality_score'] }}/100</span><h2 style="font-size:1.7rem;margin:12px 0 4px">{{ $record['ticker'] }} · {{ $record['issuer_name'] }}</h2><p class="caption">{{ $record['sector'] }} · {{ $record['report_period'] }} · tanggal laporan {{ $record['report_date'] }} · {{ $record['currency'] }} · {{ $record['consolidated'] ? 'Konsolidasian' : 'Non-konsolidasian' }} · {{ $record['version_count'] }} versi</p></div>
                <a class="button" href="{{ route('ii.fundamentals.export', $record['ticker']) }}">Ekspor laporan CSV</a>
            </div>
            <div class="stat-grid">
                <div class="stat"><span>P/E</span><strong>{{ $fmt($record['pe_ratio'],2) }}</strong></div><div class="stat"><span>P/BV</span><strong>{{ $fmt($record['pbv_ratio'],2) }}</strong></div><div class="stat"><span>EV/EBITDA</span><strong>{{ $fmt($record['ev_ebitda_ratio'],2) }}</strong></div><div class="stat"><span>ROE</span><strong>{{ $record['roe_end_pct'] === null ? 'N/A' : $record['roe_end_pct'].'%' }}</strong></div>
                <div class="stat"><span>Revenue growth</span><strong>{{ $record['revenue_growth_pct'] === null ? 'N/A' : $record['revenue_growth_pct'].'%' }}</strong></div><div class="stat"><span>Earnings growth</span><strong>{{ $record['earnings_growth_pct'] === null ? 'N/A' : $record['earnings_growth_pct'].'%' }}</strong></div><div class="stat"><span>Debt / equity</span><strong>{{ $fmt($record['debt_equity_ratio'],2) }}</strong></div><div class="stat"><span>Operating cash flow</span><strong>{{ $fmt($record['operating_cash_flow']) }}</strong></div>
            </div>
            <p class="caption">Status valuasi: {{ $record['valuation_status'] }} · harga {{ $record['market_price_as_of'] ?? 'N/A' }} dari {{ $record['market_price_source'] ?? 'belum tersedia' }}. Basis saham: {{ $record['share_basis_status'] ?? 'NOT_APPLICABLE' }}@if(isset($record['share_basis_factor'])) · faktor {{ $record['share_basis_factor'] }} @endif @if(!empty($record['share_basis_source'])) · review {{ $record['share_basis_source'] }} @endif. Rasio tidak dihitung jika tanggal, unit, mata uang, hak, atau basis saham belum selaras.</p>
            <details class="issuer-more"><summary>Periode fiskal, provenance &amp; bukti sumber</summary><div class="tablewrap"><table><thead><tr><th>Periode</th><th>Revenue</th><th>Laba pemilik</th><th>Aset</th><th>Ekuitas</th><th>OCF</th><th>Status</th></tr></thead><tbody>
            @foreach($record['years'] as $year)<tr><td>{{ $year['period_end'] }}</td><td>{{ $fmt($year['revenue']) }}</td><td>{{ $fmt($year['net_income']) }}</td><td>{{ $fmt($year['total_assets']) }}</td><td>{{ $fmt($year['equity']) }}</td><td>{{ $fmt($year['operating_cash_flow']) }}</td><td>{{ $year['restated'] ? 'RESTATED' : 'AS REPORTED' }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="source"><strong>Provenance:</strong> {{ $record['source_name'] }} @if($record['source_url']) · <a href="{{ $record['source_url'] }}" rel="noopener noreferrer">sumber</a>@endif<br><strong>Assessment terpisah:</strong> authenticity {{ $record['source_authenticity_status'] }} · numeric {{ $record['numeric_reconciliation_status'] }} · licensing {{ $record['licensing_assessment'] }} · confidence {{ $record['provenance_confidence'] }}<br><strong>Hak penggunaan:</strong> {{ $record['license_status'] }} · {{ $record['usage_rights'] }} · redistribusi {{ $record['redistribution_allowed'] ? 'diizinkan' : 'tidak dinyatakan' }}<br><strong>Mapping:</strong> {{ $record['taxonomy_status'] }} · <strong>Konflik versi:</strong> {{ $record['conflict_status'] }}<br><strong>Valuation:</strong> {{ $record['valuation_status'] }}<br><strong>Checksum SHA-256:</strong> <span class="mono">{{ $record['sha256'] ?? 'legacy unavailable' }}</span><br><strong>Impor:</strong> {{ $record['imported_at'] }} · <strong>Gate:</strong> {{ $record['gate'] }} · {{ $record['decision'] }} · AUTHORITY {{ $record['trading_authority'] }}</div>
            </details>
        </section>
        @empty <section class="panel"><h2>Belum ada laporan fundamental</h2><p class="muted">Impor dokumen resmi untuk arsip provenance, atau CSV/XBRL untuk normalisasi dan rasio.</p><a href="{{ route('ii.fundamentals.example') }}">Unduh template CSV →</a></section>
        @endforelse
        @if($marketPrices->isNotEmpty())
        <section class="panel" aria-label="Ringkasan provenance harga" style="margin-top:18px">
            <h2>Provenance harga terbaru</h2>
            <p class="caption">Sumber ditampilkan sebagai bukti, bukan izin redistribusi atau otorisasi trading.</p>
            @foreach($marketPrices->take(5) as $price)
                <div class="status-line">
                    <strong>{{ $price->issuer_code }} · {{ $price->source_name }}</strong>
                    <small>{{ $price->observed_on->toDateString() }} · {{ $price->adjustment_status }} · {{ $price->usage_rights }} · SHA batch {{ $price->batch_sha256 ? substr($price->batch_sha256,0,16) : 'manual' }}</small>
                </div>
            @endforeach
            <a href="{{ route('ii.fundamentals.index', ['workspace' => 'prices']) }}">Buka register lengkap →</a>
        </section>
        @endif
        @if($shareBasisReviews->isNotEmpty())
        <section class="panel" aria-label="Review basis saham terbaru" style="margin-top:18px">
            <h2>Review basis saham tercatat</h2>
            <p class="caption">Review pengguna bukan verifikasi bursa. Periksa status terkini melalui ruang Rekonsiliasi.</p>
            @foreach($shareBasisReviews->take(5) as $review)
                <div class="status-line"><strong>{{ $review->issuer_code }} · {{ $review->source_name }}</strong><small>{{ $review->period_end->toDateString() }} → {{ $review->price_observed_on->toDateString() }} · perlu cek ulang bila ada aksi baru</small></div>
            @endforeach
            <a href="{{ route('ii.fundamentals.index', ['workspace' => 'reconciliation']) }}">Buka rekonsiliasi →</a>
        </section>
        @endif
        @endif
        @if($workspace === 'sources')
        @if($documents->isNotEmpty())
        <section class="panel"><h2>Register dokumen sumber</h2><div class="tablewrap"><table><thead><tr><th>Emiten</th><th>Periode</th><th>Format</th><th>Kelengkapan</th><th>Rekonsiliasi</th><th>Hak</th><th>Checksum</th></tr></thead><tbody>
        @foreach($documents as $document)<tr><td>{{ $document->issuer_code }}<br><span class="caption">{{ $document->issuer_name }} · {{ $document->sector }}</span></td><td>{{ $document->report_period }}<br><span class="caption">{{ $document->report_date->toDateString() }}</span></td><td>{{ strtoupper($document->format) }}<br><span class="caption">{{ $document->taxonomy_status }}</span></td><td>{{ $document->quality_status }} · {{ $document->quality_score }}/100<br><span class="caption">{{ $document->conflict_status }}</span></td><td>{{ $document->numeric_reconciliation_status }}<br><span class="caption">{{ $document->source_authenticity_status }} · {{ $document->provenance_confidence }}</span></td><td>{{ $document->licensing_assessment }}<br><span class="caption">{{ $document->source->usage_rights }}</span></td><td class="mono">{{ substr($document->sha256,0,16) }}…</td></tr>@endforeach
        </tbody></table></div></section>
        @endif
        @if($batches->isNotEmpty())
        <section class="panel"><h2>Batch ingestion</h2><div class="tablewrap"><table><thead><tr><th>Batch</th><th>Status</th><th>Item</th><th>Proses</th></tr></thead><tbody>
        @foreach($batches as $batch)<tr><td class="mono">{{ substr($batch->id,0,12) }}…<br><span class="caption">{{ $batch->original_filename }}</span></td><td>{{ $batch->status }}<br><span class="caption">{{ $batch->succeeded_items }} berhasil · {{ $batch->failed_items }} gagal</span></td><td>@foreach($batch->items as $item)<span class="caption">{{ $item->ticker }} · {{ $item->status }} · attempt {{ $item->attempts }}</span><br>@endforeach</td><td>@if($batch->failed_items > 0)<form method="POST" action="{{ route('ii.fundamentals.batches.retry',$batch->id) }}">@csrf<button type="submit">Retry gagal</button></form>@else<span class="caption">Tidak ada item gagal</span>@endif</td></tr>@endforeach
        </tbody></table></div></section>
        @endif
        @endif
        @if($workspace === 'prices')
        <section class="panel" aria-labelledby="eod-quality-title" style="margin-bottom:16px">
            <h2 id="eod-quality-title">Review kualitas harga terbaru</h2>
            <p class="caption">Semua sumber pada tanggal harga terbaru diperbandingkan per emiten. Harga yang berbeda, basis penyesuaian tidak selaras, atau tanggal masa depan ditahan dari valuasi. Histori tidak diubah dan tidak ada sumber yang dipilih otomatis.</p>
            @forelse($priceReviews as $review)
                <div class="status-line">
                    <strong>{{ $review['ticker'] }} · {{ $review['observed_on'] }}</strong>
                    <span @class(['badge', 'red' => $review['conflict'] || str_starts_with($review['status'], 'N/A_')])>{{ $review['status'] }}</span>
                    <small>{{ $review['source_count'] }} sumber/observasi pada tanggal terakhir · keputusan riset tetap HOLD</small>
                    <details class="issuer-more">
                        <summary>Lihat provenance sumber ({{ $review['source_count'] }})</summary>
                        <div class="tablewrap"><table>
                            <thead><tr><th>Sumber</th><th>Harga</th><th>Basis</th><th>Hak penggunaan</th><th>Checksum</th></tr></thead>
                            <tbody>
                                @foreach($review['sources'] as $eodSource)
                                    <tr>
                                        <td>{{ $eodSource['name'] }}</td>
                                        <td>{{ $eodSource['currency'] }} {{ $fmt($eodSource['price'], 6) }}</td>
                                        <td>{{ $eodSource['adjustment'] }}</td>
                                        <td>{{ $eodSource['rights'] }}</td>
                                        <td class="mono">{{ substr((string) $eodSource['sha256'], 0, 16) }}…</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    </details>
                </div>
            @empty
                <p class="muted">Belum ada harga bertanggal untuk ditinjau. Impor harga dari sumber yang sah melalui formulir di sebelah kanan.</p>
            @endforelse
        </section>
        @if($marketPrices->isNotEmpty())
        <section class="panel"><h2>Register harga bertanggal</h2><div class="tablewrap"><table><thead><tr><th>Emiten</th><th>Harga</th><th>Tanggal</th><th>Provenance</th><th>Hak</th></tr></thead><tbody>
        @foreach($marketPrices as $price)<tr><td>{{ $price->issuer_code }}</td><td>{{ $price->currency }} {{ $fmt($price->price,2) }} / saham</td><td>{{ $price->observed_on->toDateString() }}<br><span class="caption">{{ $price->adjustment_status }}</span></td><td>{{ $price->source_name }}<br><span class="caption">{{ $price->source_authenticity_status }} · {{ $price->provenance_confidence }}</span><br><span class="caption">SHA batch: {{ $price->batch_sha256 ? substr($price->batch_sha256,0,16).'…' : 'manual' }}</span></td><td>{{ $price->usage_rights }}<br><span class="caption">redistribusi {{ $price->redistribution_allowed ? 'ya' : 'tidak' }}</span></td></tr>@endforeach
        </tbody></table></div></section>
        @else
        <section class="panel"><h2>Belum ada harga pasar</h2><p class="muted">Data EOD harus bersumber sah dan disertai hak penggunaan. Tidak ada harga estimasi.</p></section>
        @endif
        @endif
        @if($workspace === 'reconciliation')
        <section class="panel">
            <h2>Status keselarasan basis saham</h2>
            <p class="caption">Ringkasan status mutakhir dari snapshot masing-masing emiten. Nilai N/A bukan nol dan tidak boleh dipakai sebagai ranking.</p>
            @forelse($records as $record)
                <div class="status-line">
                    <strong>{{ $record['ticker'] }} · {{ $record['share_basis_status'] ?? 'NOT_APPLICABLE' }}</strong>
                    <small>{{ $record['valuation_status'] }} · tanggal harga {{ $record['market_price_as_of'] ?? 'belum tersedia' }} · keputusan HOLD</small>
                </div>
            @empty
                <p class="muted">Belum ada emiten untuk direkonsiliasi.</p>
            @endforelse
        </section>
        @endif
    </div>
    <div>
    @if($workspace === 'sources')
    <section class="panel form-card" aria-labelledby="import-title"><span class="badge">IMMUTABLE SOURCE REGISTER</span><h2 id="import-title" style="margin-top:12px">Impor laporan emiten</h2>
        <form method="POST" action="{{ route('ii.fundamentals.import') }}" enctype="multipart/form-data">@csrf
            <div class="split"><div class="field"><label for="ticker">Kode emiten</label><input id="ticker" name="ticker" required maxlength="16" placeholder="TLKM.JK" value="{{ old('ticker') }}"></div><div class="field"><label for="issuer_name">Nama emiten</label><input id="issuer_name" name="issuer_name" required maxlength="200" value="{{ old('issuer_name') }}"></div></div>
            <div class="split"><div class="field"><label for="sector">Sektor</label><input id="sector" name="sector" maxlength="80" placeholder="Telecommunication" value="{{ old('sector') }}"></div><div class="field"><label for="fiscal_period_type">Jenis periode</label><select id="fiscal_period_type" name="fiscal_period_type"><option value="annual">Annual</option><option value="quarterly">Quarterly</option><option value="half_year">Half year</option><option value="trailing_twelve_months">TTM</option></select></div></div>
            <div class="field"><label for="kind">Jenis data</label><select id="kind" name="kind"><option value="user_supplied">Data pengguna</option><option value="synthetic" @selected(old('kind')==='synthetic')>Template sintetis (ticker DEMO*)</option></select></div>
            <div class="split"><div class="field"><label for="report_period">Periode laporan</label><input id="report_period" name="report_period" required placeholder="FY 2024" value="{{ old('report_period') }}"></div><div class="field"><label for="report_date">Tanggal laporan</label><input id="report_date" type="date" name="report_date" required value="{{ old('report_date') }}"></div></div>
            <div class="split"><div class="field"><label for="currency">Mata uang</label><select id="currency" name="currency"><option>IDR</option><option @selected(old('currency')==='USD')>USD</option></select></div><div class="field"><label for="multiplier">Satuan angka</label><select id="multiplier" name="multiplier"><option value="1">Unit</option><option value="1000">Ribuan</option><option value="1000000">Jutaan</option><option value="1000000000" @selected(old('multiplier','1000000000')==='1000000000')>Miliaran</option></select></div></div>
            <div class="field"><label class="checkline"><input type="checkbox" name="consolidated" value="1" @checked(old('consolidated',true))>Laporan konsolidasian</label></div>
            <div class="field"><label for="source_name">Nama dokumen sumber</label><input id="source_name" name="source_name" required maxlength="160" value="{{ old('source_name') }}"></div><div class="field"><label for="publisher">Penerbit</label><input id="publisher" name="publisher" maxlength="160" value="{{ old('publisher') }}"></div>
            <div class="field"><label for="source_url">URL sumber</label><input id="source_url" type="url" name="source_url" maxlength="1000" value="{{ old('source_url') }}"></div><div class="field"><label for="terms_url">URL ketentuan/lisensi</label><input id="terms_url" type="url" name="terms_url" maxlength="1000" value="{{ old('terms_url') }}"></div>
            <div class="split"><div class="field"><label for="license_status">Status sumber</label><select id="license_status" name="license_status"><option value="public_disclosure">Public disclosure</option><option value="licensed">Licensed</option><option value="user_provided">User provided</option><option value="unknown">Unknown</option></select></div><div class="field"><label for="usage_rights">Hak penggunaan</label><select id="usage_rights" name="usage_rights"><option value="internal_research">Internal research</option><option value="licensed_internal">Licensed internal</option><option value="redistribution_permitted">Redistribution permitted</option><option value="unknown">Unknown</option></select></div></div>
            <div class="field"><label for="source_authenticity_status">Keaslian sumber</label><select id="source_authenticity_status" name="source_authenticity_status"><option value="official_publisher">Official publisher</option><option value="licensed_provider">Licensed provider</option><option value="user_attested">User attested</option><option value="unverified">Unverified</option></select></div>
            <div class="split"><div class="field"><label for="numeric_reconciliation_status">Rekonsiliasi angka</label><select id="numeric_reconciliation_status" name="numeric_reconciliation_status"><option value="unreconciled">Unreconciled</option><option value="user_attested">User attested</option><option value="verified_against_source">Verified against source</option></select></div><div class="field"><label for="provenance_confidence">Confidence provenance</label><select id="provenance_confidence" name="provenance_confidence"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div></div>
            <div class="field"><label for="fundamental_file">CSV, XBRL/XML, atau PDF · max 20MB</label><input id="fundamental_file" type="file" name="fundamental_file" accept=".csv,.xbrl,.xml,.pdf" required><p class="hint">PDF disimpan sebagai dokumen sumber tanpa ekstraksi angka otomatis. CSV/XBRL dinormalisasi. Angka keuangan dikali satuan; saham dan harga selalu unit aktual.</p></div>
            <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required>Saya berhak menggunakan berkas ini dan status hak di atas akurat.</label></div><button type="submit" style="width:100%">Simpan, normalisasi & nilai kualitas</button>
        </form><p class="hint" style="margin-top:16px"><a href="{{ route('ii.fundamentals.example') }}">Unduh template CSV lengkap</a>. Versi sumber lama tidak pernah ditimpa.</p>
    </section>
    <section class="panel form-card"><span class="badge blue">QUEUE · 20 ITEMS MAX</span><h2 style="margin-top:12px">Batch manifest</h2><p class="hint">Unggah manifest JSON schema <span class="mono">ii.issuer-batch.v2</span> dan berkas yang namanya cocok. Setiap item memiliki provenance, status, error, dan retry sendiri. <a href="{{ route('ii.fundamentals.batch-example') }}">Unduh contoh sintetis</a>.</p>
        <form method="POST" action="{{ route('ii.fundamentals.batches.import') }}" enctype="multipart/form-data">@csrf
            <div class="field"><label for="manifest">Manifest JSON</label><input id="manifest" type="file" name="manifest" accept=".json,application/json" required></div>
            <div class="field"><label for="issuer_files">Dokumen/CSV/XBRL (1–20)</label><input id="issuer_files" type="file" name="issuer_files[]" accept=".csv,.xbrl,.xml,.pdf" multiple required></div><button type="submit" style="width:100%">Validasi & antrekan batch</button>
        </form>
    </section>
    @endif
    @if($workspace === 'prices')
    <section class="panel form-card"><span class="badge">DATED PRICE · SEPARATE PROVENANCE</span><h2 style="margin-top:12px">Tambah harga pasar</h2>
        <form method="POST" action="{{ route('ii.fundamentals.market-prices.store') }}">@csrf
            <div class="split"><div class="field"><label for="price_ticker">Kode emiten</label><input id="price_ticker" name="ticker" required maxlength="16"></div><div class="field"><label for="observed_on">Tanggal harga</label><input id="observed_on" type="date" name="observed_on" required></div></div>
            <div class="split"><div class="field"><label for="price_currency">Mata uang</label><select id="price_currency" name="currency"><option>IDR</option><option>USD</option></select></div><div class="field"><label for="price">Harga per saham</label><input id="price" type="number" min="0.000001" step="0.000001" name="price" required></div></div>
            <input type="hidden" name="unit" value="per_share"><div class="field"><label for="adjustment_status">Status penyesuaian</label><select id="adjustment_status" name="adjustment_status"><option value="unadjusted_close">Unadjusted close</option><option value="split_adjusted">Split adjusted</option></select></div>
            <div class="field"><label for="price_source_name">Nama sumber</label><input id="price_source_name" name="source_name" required maxlength="160"></div><div class="field"><label for="price_publisher">Penerbit</label><input id="price_publisher" name="publisher" maxlength="160"></div><div class="field"><label for="price_source_url">URL sumber</label><input id="price_source_url" type="url" name="source_url" required></div>
            <div class="split"><div class="field"><label for="price_license">Status sumber</label><select id="price_license" name="license_status"><option value="licensed">Licensed</option><option value="public_disclosure">Public disclosure</option><option value="user_provided">User provided</option></select></div><div class="field"><label for="price_rights">Hak penggunaan</label><select id="price_rights" name="usage_rights"><option value="licensed_internal">Licensed internal</option><option value="internal_research">Internal research</option><option value="redistribution_permitted">Redistribution permitted</option></select></div></div>
            <div class="split"><div class="field"><label for="price_authenticity">Keaslian</label><select id="price_authenticity" name="source_authenticity_status"><option value="licensed_provider">Licensed provider</option><option value="official_exchange">Official exchange</option><option value="official_publisher">Official publisher</option><option value="user_attested">User attested</option></select></div><div class="field"><label for="price_confidence">Confidence</label><select id="price_confidence" name="provenance_confidence"><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></div></div>
            <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required>Saya berhak memakai observasi harga ini sesuai status di atas.</label></div><button type="submit" style="width:100%">Simpan harga bertanggal</button>
        </form>
    </section>
    <section class="panel form-card" aria-labelledby="eod-preflight-title">
        <span class="badge blue">V8 · CSV PREFLIGHT</span>
        <h2 id="eod-preflight-title" style="margin-top:12px">Periksa file sebelum mengimpor</h2>
        <p class="hint">Preview memeriksa validitas sampai 1.000 observasi, cakupan emiten/tanggal, dan potensi konflik dengan data milik akun ini. File hanya diproses untuk pemeriksaan permintaan ini, tanpa disimpan oleh proses preview. Saat siap mengimpor, unggah file yang sama pada formulir berikutnya.</p>
        <form method="POST" action="{{ route('ii.fundamentals.market-prices.preview') }}" enctype="multipart/form-data">@csrf
            <div class="field"><label for="eod_preview_file">CSV untuk preview (maks. 2 MB)</label><input id="eod_preview_file" type="file" name="market_prices_csv" accept=".csv,text/csv" required></div>
            <button type="submit" style="width:100%">Periksa tanpa menyimpan</button>
        </form>
        @if(session('market_price_preview'))
            @php($preview = session('market_price_preview'))
            <div class="boundary" role="status" style="margin-top:14px">
                <strong>Preview saja — belum ada data diimpor.</strong>
                <p>{{ $preview['rows'] }} baris · {{ $preview['tickers'] }} emiten · {{ $preview['first_date'] }} s.d. {{ $preview['last_date'] }}</p>
                <p>SHA-256: <span class="mono">{{ $preview['sha256_prefix'] }}…</span> · Batch sudah pernah diimpor: {{ $preview['already_imported'] ? 'YA' : 'TIDAK' }}</p>
                <p>Baris bertanggal sama dengan data tersimpan: <strong>{{ $preview['overlaps'] }}</strong> · Potensi konflik: <strong>{{ $preview['potential_conflicts'] }}</strong></p>
                @if($preview['examples'])
                    <p class="hint">Contoh konflik: {{ implode(', ', $preview['examples']) }}. Periksa sumber dan harga melalui register; preview tidak memilih vendor secara otomatis.</p>
                @endif
                <p class="hint">Preview bukan validasi lisensi dan bukan persetujuan valuasi. Hak penggunaan harus dikonfirmasi secara terpisah saat impor.</p>
            </div>
        @endif
    </section>
    <section class="panel form-card" aria-labelledby="eod-batch-title">
        <span class="badge blue">V3 · RIGHTS-AWARE EOD CSV</span><h2 id="eod-batch-title" style="margin-top:12px">Batch harga penutupan</h2>
        <p class="hint">Impor 1–1.000 observasi harga dengan sumber dan hak penggunaan yang jelas. Tidak mengambil data melalui scraping BEI. Semua baris divalidasi atomik; satu baris gagal membatalkan seluruh batch. <a href="{{ route('ii.fundamentals.market-prices.batch-example') }}">Unduh contoh CSV sintetis</a>.</p>
        <form method="POST" action="{{ route('ii.fundamentals.market-prices.batch') }}" enctype="multipart/form-data">@csrf
            <div class="field"><label for="market_prices_csv">File CSV sumber (maksimal 2 MB)</label><input id="market_prices_csv" type="file" name="market_prices_csv" accept=".csv,text/csv" required></div>
            <div class="field"><label for="eod_source_name">Nama sumber</label><input id="eod_source_name" name="source_name" required maxlength="160"></div>
            <div class="field"><label for="eod_publisher">Penerbit</label><input id="eod_publisher" name="publisher" maxlength="160"></div>
            <div class="field"><label for="eod_source_url">URL dokumentasi sumber/lisensi</label><input id="eod_source_url" type="url" name="source_url" required></div>
            <div class="split">
                <div class="field"><label for="eod_license">Status sumber</label><select id="eod_license" name="license_status"><option value="licensed">Licensed</option><option value="public_disclosure">Public disclosure</option><option value="user_provided">User provided</option></select></div>
                <div class="field"><label for="eod_rights">Hak penggunaan</label><select id="eod_rights" name="usage_rights"><option value="licensed_internal">Licensed internal</option><option value="internal_research">Internal research</option><option value="redistribution_permitted">Redistribution permitted</option></select></div>
            </div>
            <div class="split">
                <div class="field"><label for="eod_authenticity">Keaslian sumber</label><select id="eod_authenticity" name="source_authenticity_status"><option value="licensed_provider">Licensed provider</option><option value="official_exchange">Official exchange</option><option value="official_publisher">Official publisher</option><option value="user_attested">User attested</option></select></div>
                <div class="field"><label for="eod_confidence">Confidence</label><select id="eod_confidence" name="provenance_confidence"><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></div>
            </div>
            <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required>Saya memiliki hak penggunaan data dan memahami larangan redistribusi tanpa izin.</label></div>
            <button type="submit" style="width:100%">Validasi & impor harga batch</button>
        </form>
        <p class="hint" style="margin-top:12px">Harga <span class="mono">split_adjusted</span> disimpan sebagai bukti tetapi tidak digunakan untuk valuasi sebelum penyesuaian saham selaras. Semua keputusan tetap HOLD.</p>
    </section>
    @endif
    @if($workspace === 'reconciliation')
    @include('ii.partials.share-basis-v4')
    @endif
    </div></div>
@endsection
