@extends('ii.layout')
@section('title','Market Data Workspace')
@section('content')
<p class="eyebrow">II / Market Data Operations</p>
<h1>Autonomous Data Control Center</h1>
<p class="lead">Pantau cakupan observasi, kesegaran data, dan hasil akuisisi lokal.</p>
<div class="boundary">Status konfigurasi: {{ $overview['status'] }}. Data tetap USER_IMPORTED_UNVERIFIED; tidak ada otorisasi trading.</div>
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
<dt>Kredensial lokal</dt><dd>{{ $control['connected'] ? 'TERSIMPAN — entitlement belum terverifikasi' : 'BELUM TERSIMPAN' }}</dd>
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
<section class="panel" aria-labelledby="market-policy">
<h2 id="market-policy">1. Kebijakan penelitian</h2>
<p class="muted">Tetapkan cakupan bursa dan batas operasional. Sistem mencari ticker dan metadata. Menyimpan kebijakan akan menonaktifkan otomatisasi dan mengosongkan universe aktif.</p>
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
<button type="submit">Simpan kebijakan (nonaktif)</button>
</form>
</section>
<section class="panel" aria-labelledby="market-credential">
<h2 id="market-credential">2. Kredensial provider</h2>
<p class="muted">API key disimpan melalui vault lokal, tidak ditampilkan kembali. Mengganti key menonaktifkan discovery dan akuisisi. Pastikan kebijakan telah tersimpan.</p>
<form method="POST" action="{{ route('ii.market-data.credential') }}" autocomplete="off">
@csrf
<p><label for="market-api-key">Twelve Data API key</label><br>
<input id="market-api-key" name="api_key" type="password" autocomplete="new-password" minlength="8" maxlength="128" required @disabled(!$control['configured'])></p>
<button type="submit" @disabled(!$control['configured'])>Simpan kredensial lokal</button>
</form>
</section>
<section class="panel" aria-labelledby="market-actions">
<h2 id="market-actions">3. Otorisasi operasi</h2>
<p class="muted">Discovery terjadwal pada 10.00 UTC; akuisisi harga terjadwal pada 12.00 UTC. Katalog tidak membuktikan hak akses harga. Aktivasi memerlukan universe yang valid. Penghentian tidak membatalkan permintaan yang telah berlangsung.</p>
<form method="POST" action="{{ route('ii.market-data.action') }}">
@csrf
<input type="hidden" name="action" value="discovery">
<p><label><input type="checkbox" name="confirm" value="1" required> Izinkan discovery katalog sesuai kebijakan tersimpan.</label></p>
<button type="submit" @disabled(!$control['configured'] || !$control['connected'])>Otorisasi discovery</button>
</form>
<form method="POST" action="{{ route('ii.market-data.action') }}">
@csrf
<input type="hidden" name="action" value="activate">
<p><label><input type="checkbox" name="confirm" value="1" required> Izinkan akuisisi harga untuk universe tersimpan sesuai lisensi.</label></p>
<button type="submit" @disabled(!$control['configured'] || !$control['connected'] || empty($overview['instruments']))>Aktifkan akuisisi harga</button>
</form>
<form method="POST" action="{{ route('ii.market-data.action') }}">
@csrf
<input type="hidden" name="action" value="stop">
<p><label><input type="checkbox" name="confirm" value="1" required> Hentikan discovery dan akuisisi otomatis berikutnya.</label></p>
<button type="submit" @disabled(!$control['configured'])>Hentikan otomatisasi</button>
</form>
</section>
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
<h2>50 eksekusi terakhir</h2>
@forelse($overview['receipts'] as $receipt)
<article style="border-top:1px solid #94a3b8;padding:10px 0"><strong>{{ $receipt->ticker }}</strong> · {{ $receipt->status }}<p>{{ $receipt->started_at }} UTC — {{ $receipt->completed_at ?? 'belum selesai' }}</p></article>
@empty
<p>Belum ada receipt.</p>
@endforelse
</section>
<p class="muted">Pengambilan data dilakukan oleh scheduler dan queue lokal berdasarkan otorisasi. Halaman ini tidak memberikan rekomendasi investasi atau otorisasi trading.</p>
@endsection
