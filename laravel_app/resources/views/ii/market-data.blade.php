@extends('ii.layout')
@section('title','Kelola data pasar')
@section('surface','ii-light-content')
@push('styles')
<style>.data-ops{display:grid;gap:18px;max-width:1120px;margin:auto}.data-ops .panel{margin:0}.data-ops details>summary{cursor:pointer;color:var(--ii-navy);font-weight:700;font-size:1.1rem}.data-ops details[open]>summary{margin-bottom:16px}.data-ops dl{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px 16px}.data-ops dd{margin:0;overflow-wrap:anywhere}.data-ops dt{color:var(--muted)}.data-ops input[type=checkbox]{width:auto;margin-right:7px}.data-ops form+form{border-top:1px solid var(--line);padding-top:16px;margin-top:20px}.data-ops textarea{width:100%;border:1px solid #b8c5d0;border-radius:9px;padding:11px;font:inherit}.data-ops article{overflow-wrap:anywhere}.data-ops .panel h2{margin-bottom:12px}@media(max-width:540px){.data-ops dl{grid-template-columns:1fr;gap:3px}.data-ops dd{margin-bottom:10px}}</style>
@endpush
@section('content')
<div class="data-ops">
<p class="eyebrow">Pengaturan</p>
<h1>Kelola data pasar</h1>
<p class="lead">Pantau ketersediaan riwayat harga, kualitas sumber dan pengambilan data otomatis.</p>
<div class="boundary">Data penelitian belum diverifikasi secara independen dan tidak memberikan izin transaksi.</div>
<section class="panel" aria-labelledby="research-continuity">
<h2 id="research-continuity">Arsip penelitian</h2>
<p><strong>{{ $continuity['mode'] }}</strong> · {{ count($continuity['instruments']) }} instrumen · {{ $continuity['registered'] }} lineage terdaftar · {{ $continuity['recent'] }} seri relatif baru.</p>
<p class="muted">Status arsip tidak bergantung pada discovery. Koneksi provider: {{ $continuity['live_provider_status'] }}. Lineage terdaftar bukan verifikasi harga independen; umur data dihitung dalam hari kalender.</p>
@forelse($continuity['instruments'] as $item)
<article style="border-top:1px solid #94a3b8;padding:10px 0">
<strong>{{ $item['ticker'] }}</strong> · {{ $item['freshness'] }} · {{ $item['lineage'] }}
<p>{{ $item['sessions'] }} sesi · terakhir {{ $item['last_date'] }} · basis {{ $item['basis'] }} · {{ $item['source'] }}</p>
</article>
@empty
<p>Belum ada arsip riset nyata yang dapat dibaca.</p>
@endforelse
<p><a href="{{ route('ii.signals.index') }}">Lihat sinyal pasar</a></p>
</section>
<details class="panel" @if($errors->any()) open @endif><summary>Pemeriksaan kualitas data</summary>
<p class="muted">Pemeriksaan lokal tanpa request provider. Kesepakatan harga bukan bukti independensi. Tidak ada promosi otomatis atau penggabungan data.</p>
@forelse($accuracy['items'] as $item)
<p><strong>{{ $item['ticker'] }}</strong> · {{ $item['sources'] }} sumber · {{ $item['status'] }}</p>
@foreach($item['comparisons'] as $comparison)
<p>{{ $comparison['status'] }} · {{ $comparison['overlap_sessions'] }} sesi bersama · {{ $comparison['conflict_sessions'] }} sesi konflik · {{ $comparison['independence'] }}</p>
@endforeach
@empty
<p>Belum ada versi sumber terkelola yang dapat dibandingkan.</p>
@endforelse
<h3>Daftar sumber</h3>
@foreach($accuracy['registry'] as $source)
<p><strong>{{ $source['name'] }}</strong> · {{ $source['capability'] }} · {{ $source['status'] }} · {{ $source['rights'] }}</p>
@endforeach
</details>
@if(session('market_status'))
<div class="boundary" role="status">{{ session('market_status') }}</div>
@endif
@if($errors->any())
<div class="boundary" role="alert">
<p>Periksa kembali konfigurasi:</p>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif
@if($control !== null)
<section class="panel" aria-labelledby="market-operations">
<h2 id="market-operations">Status operasional</h2>
<dl>
<dt>Provider</dt><dd>Twelve Data</dd>
<dt>Kebijakan</dt><dd>{{ $control['configured'] ? 'TERSIMPAN' : 'BELUM DIKONFIGURASI' }}</dd>
<dt>Kunci akses lokal</dt><dd>{{ $control['connected'] ? 'TERSIMPAN — entitlement belum terverifikasi' : 'BELUM TERSIMPAN' }}</dd>
<dt>Discovery</dt><dd>{{ $control['discovery_enabled'] ? 'AKTIF' : 'NONAKTIF' }}</dd>
<dt>Akuisisi harga</dt><dd>{{ $control['enabled'] ? 'AKTIF' : 'NONAKTIF' }}</dd>
<dt>Katalog instrumen</dt><dd>{{ $control['catalog_count'] }} kandidat, bukan bukti entitlement</dd>
<dt>Batas harian lokal</dt><dd>{{ $control['daily_limit'] }} permintaan (UTC)</dd>
<dt>Permintaan provider tercatat hari ini</dt><dd>{{ $control['daily_used'] }}</dd>
<dt>Sisa anggaran permintaan lokal</dt><dd>{{ max(0, $control['daily_limit'] - $control['daily_used']) }}</dd>
<dt>Kredit provider terobservasi</dt><dd>{{ $control['provider_credits'] === null ? 'BELUM TEROBSERVASI / KEDALUWARSA' : $control['provider_credits'] }}</dd>
<dt>Pengukuran</dt><dd>Ledger mencatat percobaan permintaan lokal; kredit provider dapat berbeda. Observasi kredit bukan verifikasi paket langganan.</dd>
</dl>
</section>
<details class="panel" @if($errors->any()) open @endif><summary>1. Atur izin dan batas penggunaan</summary>
<p class="muted">Tetapkan cakupan bursa dan batas operasional. Sistem mencari ticker dan metadata. Menyimpan kebijakan akan menonaktifkan otomatisasi dan mengosongkan daftar instrumen aktif.</p>
<form method="POST" action="{{ route('ii.market-data.policy') }}">
@csrf
<p><label for="market-mics">Kode MIC bursa (dipisahkan koma atau spasi)</label><br>
<input id="market-mics" name="mics" type="text" maxlength="80" value="{{ old('mics', $control['mics']) }}" placeholder="XIDX, XNAS" required></p>
<p><label for="market-rights">Referensi hak penggunaan data</label><br>
<textarea id="market-rights" name="rights_reference" maxlength="400" rows="3" required>{{ old('rights_reference') }}</textarea></p>
<p class="muted">Masukkan referensi kontrak atau lisensi, bukan API key. Jangan sertakan informasi rahasia.</p>
<p><label for="market-limit">Batas maksimum permintaan per hari</label><br>
<input id="market-limit" name="daily_request_limit" type="number" min="3" max="10000" step="1" value="{{ old('daily_request_limit', $control['daily_limit']) }}" required></p>
<p><label><input name="storage_permitted" type="checkbox" value="1" required> Saya mengonfirmasi lisensi mengizinkan penyimpanan data untuk penelitian ini.</label></p>
<p><label><input name="automation_permitted" type="checkbox" value="1" required> Saya mengonfirmasi lisensi mengizinkan pengambilan data otomatis dalam cakupan dan batas tersebut.</label></p>
<button type="submit">Simpan pengaturan dan nonaktifkan otomatisasi</button>
</form>
</details>
<details class="panel" @if($errors->any()) open @endif><summary>2. Hubungkan penyedia data</summary>
<p class="muted">API key disimpan secara aman di perangkat ini, tidak ditampilkan kembali. Mengganti key menonaktifkan discovery dan akuisisi. Pastikan kebijakan telah tersimpan.</p>
<form method="POST" action="{{ route('ii.market-data.credential') }}" autocomplete="off">
@csrf
<p><label for="market-api-key">Twelve Data API key</label><br>
<input id="market-api-key" name="api_key" type="password" autocomplete="new-password" minlength="8" maxlength="128" required @disabled(!$control['configured'])></p>
<button type="submit" @disabled(!$control['configured'])>Simpan kunci akses</button>
</form>
</details>
<details class="panel" @if($errors->any()) open @endif><summary>3. Aktifkan pengambilan otomatis</summary>
<p class="muted">Pencarian instrumen terjadwal pada 10.00 UTC; pengambilan harga terjadwal pada 12.00 UTC. Katalog tidak membuktikan hak akses harga. Aktivasi memerlukan daftar instrumen yang valid. Penghentian tidak membatalkan permintaan yang telah berlangsung.</p>
<form method="POST" action="{{ route('ii.market-data.action') }}">
@csrf
<input type="hidden" name="action" value="discovery">
<p><label><input type="checkbox" name="confirm" value="1" required> Izinkan pencarian instrumen sesuai kebijakan tersimpan.</label></p>
<button type="submit" @disabled(!$control['configured'] || !$control['connected'])>Izinkan pencarian instrumen</button>
</form>
<form method="POST" action="{{ route('ii.market-data.action') }}">
@csrf
<input type="hidden" name="action" value="activate">
<p><label><input type="checkbox" name="confirm" value="1" required> Izinkan akuisisi harga untuk daftar instrumen tersimpan sesuai lisensi.</label></p>
<button type="submit" @disabled(!$control['configured'] || !$control['connected'] || empty($overview['instruments']))>Aktifkan akuisisi harga</button>
</form>
<form method="POST" action="{{ route('ii.market-data.action') }}">
@csrf
<input type="hidden" name="action" value="stop">
<p><label><input type="checkbox" name="confirm" value="1" required> Hentikan discovery dan akuisisi otomatis berikutnya.</label></p>
<button type="submit" @disabled(!$control['configured'])>Hentikan otomatisasi</button>
</form>
</details>
@else
<p class="muted">Kontrol operasional hanya tersedia untuk pemilik konfigurasi atau administrator yang menginisialisasi konfigurasi.</p>
@endif
<section class="panel">
<h2>Cakupan instrumen</h2>
<p class="muted">Usia menggunakan hari kalender, bukan kalender perdagangan. Jeda lebih dari tujuh hari adalah indikasi untuk diperiksa, bukan bukti sesi hilang.</p>
@forelse($overview['instruments'] as $item)
<article style="border-top:1px solid #94a3b8;padding:12px 0">
<strong>{{ $item['ticker'] }}</strong> · {{ $item['provider'] }} · {{ $item['status'] }}
<p>{{ $item['sessions'] }} / {{ $item['target'] }} observasi target · {{ $item['first'] ?? '—' }} sampai {{ $item['last'] ?? '—' }}</p>
<p>Usia: {{ $item['age_days'] ?? '—' }} hari · Jeda panjang: {{ $item['long_calendar_gaps'] }}</p>
</article>
@empty
<p>Belum ada instrumen yang dapat dipantau untuk akun ini.</p>
@endforelse
</section>
<section class="panel">
<h2>Riwayat pengambilan data (50 terakhir)</h2>
@forelse($overview['receipts'] as $receipt)
<article style="border-top:1px solid #94a3b8;padding:10px 0"><strong>{{ $receipt->ticker }}</strong> · {{ $receipt->status }}<p>{{ $receipt->started_at }} UTC — {{ $receipt->completed_at ?? 'belum selesai' }}</p></article>
@empty
<p>Belum ada riwayat pengambilan data.</p>
@endforelse
</section>
<p class="muted">Pengambilan data dilakukan oleh jadwal otomatis di perangkat ini berdasarkan otorisasi. Halaman ini tidak memberikan rekomendasi investasi atau otorisasi trading.</p>
</div>
@endsection
