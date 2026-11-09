@extends('ii.layout')
@section('title','Trading Signal Center')
@push('styles')
<style>
    .signal-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(300px,1fr);gap:20px;align-items:start}
    .signal-item{margin-bottom:16px}.signal-top{display:flex;gap:15px;align-items:start;justify-content:space-between;flex-wrap:wrap}
    .signal-title{font-size:1.7rem;font-weight:800;letter-spacing:-.035em;margin:8px 0 0}.signal-price{font-size:1.9rem;font-weight:800;letter-spacing:-.035em;margin:10px 0 0}
    .spark{width:100%;height:112px;display:block;margin:8px 0 12px;border-radius:10px;background:#0b192b}
    .indicator-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}
    .indicator{background:#0e1c2e;border-radius:10px;padding:10px}.indicator span{display:block;font-size:.7rem;color:var(--muted)}.indicator strong{display:block;font-size:1.05rem;margin-top:5px;font-variant-numeric:tabular-nums}
    .evidence{border-top:1px solid var(--line);padding-top:13px;margin-top:18px;font-size:.76rem;color:var(--muted)}
    .checkline{display:flex;gap:11px;align-items:flex-start;color:#d2e4f1;font-size:.82rem;font-weight:500}.checkline input{width:auto;margin-top:4px;flex:0 0 auto}
    .import-panel{position:sticky;top:20px}.signal-info{line-height:1.65}.split{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    @media(max-width:1150px){.signal-layout{display:block}.import-panel{position:static}.indicator-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.signal-list{margin-bottom:20px}}
</style>
@endpush
@section('content')
    <p class="eyebrow">II / Trading Signal Intelligence</p>
    <h1>Trading Signal Center</h1>
    <p class="lead">Pantau struktur tren, momentum dan besaran risiko dari riwayat harga yang diimpor. Sinyal riset aktif; sinyal transaksi beli/jual tetap memerlukan data terverifikasi dan milestone strategi berikutnya.</p>
    <div class="grid" style="margin-bottom:15px">
        <div class="panel"><span class="badge">LEVEL 1 · AKTIF</span><h3 style="margin-top:13px">Market Insight</h3><p class="caption">SMA20, SMA50, RSI14, ATR14; langsung dari CSV, tanpa layanan AI berbayar.</p></div>
        <div class="panel"><span class="badge blue">LEVEL 2 · TERBATAS</span><h3 style="margin-top:13px">Research Signal</h3><p class="caption">Klasifikasi tren untuk investigasi. Bukti impor belum diterima sebagai data canonical.</p></div>
        <div class="panel"><span class="badge amber">LEVEL 3 · TERKUNCI</span><h3 style="margin-top:13px">Actionable Trading Signal</h3><p class="caption">Entry, exit, stop, target dan alert eksekusi menunggu engine strategi + evaluasi risiko.</p></div>
    </div>
    <div class="boundary"><strong>Research-only / NOT_ADMITTED / HOLD / AUTHORITY NONE.</strong> Seri yang diunggah belum diverifikasi independen; tidak ada order, akun broker, transaksi, klaim akurasi, atau kepastian profit. Angka di sini bukan nasihat investasi personal.</div>
    @if(session('signal_notice'))<div class="success" role="status">{{ session('signal_notice') }}</div>@endif
    @if($errors->any())<div class="error" role="alert"><strong>Impor belum disimpan.</strong> {{ $errors->first() }}</div>@endif
    <div class="signal-layout">
        <div class="signal-list">
            <div class="section-header"><h2>Market observations · {{ count($signals) }} instrumen</h2><span class="caption">Hanya data milik akun ini</span></div>
            @forelse($signals as $signal)
                <article class="panel signal-item">
                    <div class="signal-top"><div><span class="badge {{ $signal['stale'] ? 'amber' : ($signal['state'] === 'MOMENTUM_DOWN' ? 'red' : 'blue') }}">{{ $signal['state'] }}</span><div class="signal-title">{{ $signal['ticker'] }}</div><div class="caption">{{ $signal['kind'] === 'synthetic' ? 'SYNTHETIC DEMO' : 'USER-SUPPLIED / UNVERIFIED' }} · terakhir {{ $signal['last_date'] }}</div></div>
                        <div style="text-align:right"><div class="caption">Harga penutupan</div><div class="signal-price">{{ number_format($signal['price'], 2) }}</div><div class="caption">Satuan mengikuti CSV</div></div></div>
                    <svg class="spark" viewBox="0 0 460 100" role="img" aria-label="Grafik tren 60 harga penutupan terakhir: {{ $signal['ticker'] }}">
                        <line x1="8" y1="88" x2="452" y2="88" stroke="#344961" stroke-width="1" />
                        <line x1="8" y1="51" x2="452" y2="51" stroke="#20374e" stroke-width="1" />
                        <polyline fill="none" stroke="#61d5e8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="{{ $signal['spark'] }}" />
                    </svg>
                    <div class="indicator-grid">
                        <div class="indicator"><span>SMA20</span><strong>{{ number_format($signal['sma20'], 2) }}</strong></div>
                        <div class="indicator"><span>SMA50</span><strong>{{ number_format($signal['sma50'], 2) }}</strong></div>
                        <div class="indicator"><span>RSI14</span><strong>{{ $signal['rsi14'] }}</strong></div>
                        <div class="indicator"><span>ATR14 / harga</span><strong>{{ $signal['atr14_pct'] }}%</strong></div>
                    </div>
                    <p class="signal-info">{{ $signal['description'] }}</p>
                    <p><a class="button" href="{{ route('ii.strategy.index', ['ticker' => $signal['ticker']]) }}">Simulasikan strategi untuk {{ $signal['ticker'] }} →</a></p>
                    @if(isset($fundamentals[$signal['ticker']]))
                        @php($issuer = $fundamentals[$signal['ticker']])
                        <div class="panel soft" style="padding:14px;margin-top:14px">
                            <strong>Fundamental context · {{ $issuer['latest_year'] }}</strong>
                            <p class="caption">Pertumbuhan pendapatan: {{ $issuer['revenue_growth_pct'] === null ? 'N/A' : $issuer['revenue_growth_pct'].'%' }} · Net margin: {{ $issuer['net_margin_pct'] === null ? 'N/A' : $issuer['net_margin_pct'].'%' }} · ROE akhir tahun: {{ $issuer['roe_end_pct'] === null ? 'N/A' : $issuer['roe_end_pct'].'%' }}</p>
                            <p class="caption">Data fundamental dan harga berasal dari impor pengguna; belum ada evaluasi keselarasan, valuasi ataupun keputusan trading.</p>
                            <a href="{{ route('ii.fundamentals.index') }}">Lihat sumber fundamental →</a>
                        </div>
                    @else
                        <p class="caption"><a href="{{ route('ii.fundamentals.index') }}">Hubungkan riset fundamental untuk {{ $signal['ticker'] }} →</a></p>
                    @endif
                    <div class="evidence">
                        <strong>Lineage:</strong> {{ $signal['source_name'] }} @if(!empty($signal['source_url'])) · {{ $signal['source_url'] }}@endif<br>
                        <strong>Cakupan:</strong> {{ $signal['rows'] }} sesi · {{ $signal['age_days'] }} hari sejak sesi terakhir · impor {{ $signal['imported_at'] }}<br>
                        <strong>Metadata sumber:</strong> mata uang {{ $signal['currency'] }} · basis harga {{ $signal['price_adjustment'] }} · zona waktu sesi {{ $signal['session_timezone'] }}<br>
                        <strong>Asal metadata:</strong> {{ $signal['metadata_provenance'] }} (bukan verifikasi penyedia)<br>
                        <strong>Gate:</strong> USER_IMPORTED_UNVERIFIED · HOLD · NO_TRADING_AUTHORITY
                    </div>
                </article>
            @empty
                <div class="panel soft"><h2>Belum ada market observation</h2><p class="muted">Impor CSV agar tampilan sinyal terisi indikator kuantitatif yang benar-benar dihitung dari data Anda. Gunakan contoh sintetis untuk memahami formatnya.</p><p><a href="{{ route('ii.signals.example') }}">Unduh contoh CSV DEMO7 →</a></p></div>
            @endforelse
        </div>
        <section class="panel import-panel" aria-labelledby="import-title">
            <span class="badge">DATA FOUNDATION · VS7</span>
            <h2 id="import-title" style="margin-top:13px">Impor seri harga</h2>
            <p class="caption">Satu langkah menghasilkan observasi tren dan risiko. File disimpan privat per akun, dengan riwayat impor lama tetap dipertahankan.</p>
            <form method="POST" action="{{ route('ii.signals.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="split">
                    <div class="field"><label for="ticker">Ticker</label><input id="ticker" name="ticker" maxlength="16" required placeholder="BBCA.JK" value="{{ old('ticker') }}"></div>
                    <div class="field"><label for="kind">Jenis data</label><select id="kind" name="kind"><option value="user_supplied" @selected(old('kind') === 'user_supplied')>Data pengguna</option><option value="synthetic" @selected(old('kind') === 'synthetic')>Demo sintetis</option></select></div>
                </div>
                <div class="field"><label for="source_name">Nama sumber</label><input id="source_name" name="source_name" maxlength="120" required placeholder="Ekspor resmi / sumber berizin" value="{{ old('source_name') }}"></div>
                <div class="field"><label for="source_url">URL sumber (opsional)</label><input id="source_url" type="url" name="source_url" maxlength="400" placeholder="https://..." value="{{ old('source_url') }}"></div>
                <div class="field"><strong>Metadata sumber · VS10 R3</strong><p class="hint">Deklarasikan hanya yang tercantum dalam sumber. Kosong / tidak diketahui tidak diisi otomatis dari ticker. Ini bukan sertifikasi data.</p></div>
                <div class="split">
                    <div class="field"><label for="currency">Mata uang (opsional)</label><input id="currency" name="currency" maxlength="3" pattern="[A-Za-z]{3}" placeholder="Tidak diketahui / IDR" value="{{ old('currency') }}"><p class="hint">Kode tiga huruf, misalnya IDR atau USD.</p></div>
                    <div class="field"><label for="price_adjustment">Basis harga</label><select id="price_adjustment" name="price_adjustment"><option value="unknown" @selected(old('price_adjustment','unknown') === 'unknown')>Tidak diketahui</option><option value="adjusted" @selected(old('price_adjustment') === 'adjusted')>Adjusted (menurut sumber)</option><option value="unadjusted" @selected(old('price_adjustment') === 'unadjusted')>Unadjusted (menurut sumber)</option></select></div>
                </div>
                <div class="field"><label for="session_timezone">Zona waktu sesi harga (opsional)</label><select id="session_timezone" name="session_timezone"><option value="">Tidak diketahui</option>@foreach(['UTC','Asia/Jakarta','Asia/Singapore','Asia/Hong_Kong','Asia/Tokyo','Europe/London','America/New_York','Australia/Sydney'] as $zone)<option value="{{ $zone }}" @selected(old('session_timezone') === $zone)>{{ $zone }}</option>@endforeach</select><p class="hint">Menjelaskan sesi pasar yang direpresentasikan tanggal CSV; bukan zona waktu komputer pengunggah.</p></div>
                <div class="field"><label for="price_file">CSV harga, maksimum 1 MB</label><input id="price_file" name="price_file" type="file" accept=".csv,text/csv" required><p class="hint">Minimal 60 sesi, maksimal 2.000 sesi. Kolom: date,open,high,low,close,volume · tanggal YYYY-MM-DD · desimal titik. Mata uang/satuan mengikuti sumber.</p></div>
                <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required> Saya memiliki hak menggunakan data ini dan memahami bahwa hasilnya adalah riset, bukan sinyal transaksi berotorisasi.</label></div>
                <button type="submit" style="width:100%">Impor & hitung indikator</button>
            </form>
            <p class="hint" style="margin-top:16px">Belum punya CSV? <a href="{{ route('ii.signals.example') }}">Unduh DEMO7 sintetis</a>, pilih tipe “Demo sintetis”, ticker “DEMO7”, sumber “II Demo7 Generator”.</p>
            <p class="hint">Impor ticker yang sama membuat versi baru tanpa menghapus file sebelumnya; dashboard hanya menampilkan versi terbaru. Arsip lama tidak diubah: metadata yang belum tercatat tetap UNKNOWN sampai ada versi impor baru.</p>
        </section>
    </div>
@endsection
