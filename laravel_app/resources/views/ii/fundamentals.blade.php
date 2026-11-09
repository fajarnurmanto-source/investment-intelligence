@extends('ii.layout')
@section('title','Issuer Fundamentals')
@push('styles')
<style>
    .finance-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(300px,.9fr);gap:20px;align-items:start}
    .financial-card{margin-bottom:16px}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin:16px 0}
    .stat{background:#0b192b;border-radius:10px;padding:13px}.stat span{font-size:.72rem;color:var(--muted);display:block}.stat strong{font-size:1.2rem;display:block;margin-top:5px}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:10px}.checkline{display:flex;gap:11px;align-items:flex-start;color:#d2e4f1;font-size:.82rem;font-weight:500}.checkline input{width:auto;margin-top:4px;flex:0 0 auto}
    .source{border-top:1px solid var(--line);margin-top:16px;padding-top:14px;color:var(--muted);font-size:.78rem;overflow-wrap:anywhere}
    @media(max-width:1160px){.finance-layout{display:block}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-card{margin-top:22px}}
</style>
@endpush
@section('content')
    <p class="eyebrow">II / Research Intelligence / VS7 R2</p>
    <h1>Issuer Fundamentals</h1>
    <p class="lead">Kumpulkan laporan keuangan tahunan dari sumber yang Anda berhak gunakan. II menghitung pertumbuhan pendapatan, marjin laba, ROE dan EPS, lalu menghubungkan konteksnya dengan sinyal pasar pada ticker yang sama.</p>
    <div class="boundary"><strong>FINANCIAL INPUT = USER_IMPORTED_UNVERIFIED.</strong> Tidak ada klaim bahwa data telah diverifikasi dari keterbukaan informasi resmi. Angka historis tidak otomatis memberi otoritas valuasi atau sinyal beli/jual. Data fundamental dan OHLCV tetap disimpan terpisah.</div>
    @if(session('fundamental_notice'))<div class="success" role="status">{{ session('fundamental_notice') }}</div>@endif
    @if($errors->any())<div class="error" role="alert"><strong>Impor belum disimpan.</strong> {{ $errors->first() }}</div>@endif
    <div class="finance-layout">
        <div>
            <div class="section-header"><h2>Riwayat emiten · {{ count($records) }} ticker</h2><span class="caption">Data pengguna ini saja</span></div>
            @forelse($records as $record)
                <section class="panel financial-card">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:start">
                        <div><span class="badge {{ $record['kind'] === 'synthetic' ? 'amber' : 'blue' }}">{{ $record['kind'] === 'synthetic' ? 'SYNTHETIC DEMO' : 'IMPORTED / UNVERIFIED' }}</span>
                        <h2 style="font-size:1.75rem;margin:12px 0 4px">{{ $record['ticker'] }}</h2><p class="caption">Tahun fiskal {{ $record['latest_year'] }} · {{ $record['currency'] }} · angka keuangan × {{ number_format($record['multiplier'], 0) }}</p></div>
                        <div>@if(isset($market[$record['ticker']]))<a class="button" href="{{ route('ii.signals.index') }}">Lihat konteks harga →</a>@else<span class="badge amber">BELUM ADA DATA HARGA</span>@endif</div>
                    </div>
                    <div class="stat-grid">
                        <div class="stat"><span>Revenue growth YoY</span><strong>{{ $record['revenue_growth_pct'] === null ? 'N/A' : $record['revenue_growth_pct'].'%' }}</strong></div>
                        <div class="stat"><span>Net margin</span><strong>{{ $record['net_margin_pct'] === null ? 'N/A' : $record['net_margin_pct'].'%' }}</strong></div>
                        <div class="stat"><span>ROE (closing equity)</span><strong>{{ $record['roe_end_pct'] === null ? 'N/A' : $record['roe_end_pct'].'%' }}</strong></div>
                        <div class="stat"><span>EPS ({{ $record['currency'] }}/lembar)</span><strong>{{ $record['eps'] === null ? 'N/A' : number_format($record['eps'], 2) }}</strong></div>
                    </div>
                    <div class="tablewrap"><table><thead><tr><th>Tahun</th><th>Revenue</th><th>Laba pemilik</th><th>Margin</th><th>ROE</th><th>Arus kas operasi</th></tr></thead><tbody>
                        @foreach($record['years'] as $year)
                        <tr><td>{{ $year['year'] }}</td><td>{{ number_format($year['revenue'], 0) }}</td><td>{{ number_format($year['net_income'], 0) }}</td><td>{{ $year['net_margin_pct'] === null ? 'N/A' : $year['net_margin_pct'].'%' }}</td><td>{{ $year['roe_end_pct'] === null ? 'N/A' : $year['roe_end_pct'].'%' }}</td><td>{{ number_format($year['operating_cash_flow'], 0) }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                    <p class="caption">ROE memakai ekuitas pada akhir tahun, bukan rata-rata ekuitas. EPS dihitung dari laba atribusi kepada pemilik dikali pengali satuan, dibagi saham beredar. Angka dapat terdistorsi oleh aksi korporasi/perbedaan kebijakan akuntansi.</p>
                    <div class="source"><strong>Provenance:</strong> {{ $record['source_name'] }} @if($record['source_url']) · {{ $record['source_url'] }}@endif<br><strong>Impor:</strong> {{ $record['imported_at'] }}<br><strong>Gate:</strong> USER_IMPORTED_UNVERIFIED · HOLD · AUTHORITY NONE</div>
                </section>
            @empty
                <section class="panel"><h2>Belum ada laporan fundamental</h2><p class="muted">Impor dua atau lebih tahun fiskal. Untuk contoh tanpa risiko data nyata, unduh dan unggah CSV DEMO7 sintetis yang sudah disediakan.</p><a href="{{ route('ii.fundamentals.example') }}">Unduh contoh CSV fundamental →</a></section>
            @endforelse
        </div>
        <section class="panel form-card" aria-labelledby="import-title">
            <span class="badge">FUNDAMENTAL DATA FOUNDATION</span><h2 id="import-title" style="margin-top:12px">Impor laporan tahunan</h2>
            <p class="caption">Nilai revenue, net income, assets, equity, dan cash flow harus konsisten dalam satu pengali dan mata uang. Shares outstanding selalu jumlah saham aktual.</p>
            <form method="POST" action="{{ route('ii.fundamentals.import') }}" enctype="multipart/form-data">@csrf
                <div class="split">
                    <div class="field"><label for="ticker">Ticker</label><input id="ticker" name="ticker" required maxlength="16" placeholder="BBCA.JK" value="{{ old('ticker') }}"></div>
                    <div class="field"><label for="kind">Jenis data</label><select id="kind" name="kind"><option value="user_supplied" @selected(old('kind') === 'user_supplied')>Data pengguna</option><option value="synthetic" @selected(old('kind') === 'synthetic')>Demo sintetis</option></select></div>
                </div>
                <div class="split">
                    <div class="field"><label for="currency">Mata uang</label><select id="currency" name="currency"><option value="IDR">IDR</option><option value="USD" @selected(old('currency') === 'USD')>USD</option></select></div>
                    <div class="field"><label for="multiplier">Satuan dalam CSV</label><select id="multiplier" name="multiplier"><option value="1">Unit</option><option value="1000">Ribuan</option><option value="1000000" @selected(old('multiplier','1000000') === '1000000')>Jutaan</option><option value="1000000000">Miliaran</option></select></div>
                </div>
                <div class="field"><label for="source_name">Dokumen sumber</label><input id="source_name" name="source_name" maxlength="160" required placeholder="Annual Report 2025 / laporan sumber" value="{{ old('source_name') }}"></div>
                <div class="field"><label for="source_url">URL sumber (opsional)</label><input id="source_url" type="url" name="source_url" maxlength="400" placeholder="https://..." value="{{ old('source_url') }}"></div>
                <div class="field"><label for="fundamental_file">CSV fundamental · max 1MB</label><input id="fundamental_file" type="file" name="fundamental_file" accept=".csv,text/csv" required><p class="hint">Minimal 2 tahun, maksimal 10 tahun. Tahun unik. Header harus sama persis dengan template.</p></div>
                <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required>Saya memiliki hak penggunaan data ini dan memahami bahwa hasil masih belum diverifikasi sebagai bukti investasi.</label></div>
                <button type="submit" style="width:100%">Impor & hitung rasio</button>
            </form>
            <p class="hint" style="margin-top:16px"><a href="{{ route('ii.fundamentals.example') }}">Unduh DEMO7 fundamental sintetis</a> · gunakan ticker DEMO7, Demo sintetis, IDR, satuan Jutaan, sumber II Fundamental Demo Generator.</p>
            <p class="hint">Bila ticker pernah diimpor, versi lama tetap disimpan; dashboard mengambil versi terbaru.</p>
        </section>
    </div>
@endsection
