@extends('ii.layout')
@section('title','Issuer Fundamentals')
@push('styles')
<style>
    .finance-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(320px,.9fr);gap:20px;align-items:start}.financial-card{margin-bottom:16px}
    .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin:16px 0}.stat{background:#0b192b;border-radius:10px;padding:13px}.stat span{font-size:.7rem;color:var(--muted);display:block}.stat strong{font-size:1.15rem;display:block;margin-top:5px}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:10px}.checkline{display:flex;gap:11px;align-items:flex-start;color:#d2e4f1;font-size:.82rem;font-weight:500}.checkline input{width:auto;margin-top:4px}.source{border-top:1px solid var(--line);margin-top:16px;padding-top:14px;color:var(--muted);font-size:.78rem;overflow-wrap:anywhere}.mono{font-family:ui-monospace,monospace;font-size:.72rem}
    @media(max-width:1160px){.finance-layout{display:block}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-card{margin-top:22px}}@media(max-width:560px){.split,.stat-grid{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
    @php($fmt = fn($value, $decimals = 0) => $value === null ? 'N/A' : number_format((float)$value, $decimals))
    <p class="eyebrow">II / Issuer Intelligence V1</p><h1>Issuer Fundamentals</h1>
    <p class="lead">Impor dokumen, CSV normalisasi, atau XBRL yang diperoleh secara sah. II menyimpan versi sumber secara immutable, menormalkan satuan, menilai kelengkapan, dan hanya menghitung rasio saat semua input wajib tersedia.</p>
    <div class="boundary"><strong>NOT_ADMITTED · HOLD · TRADING_AUTHORITY=NONE.</strong> Keterbukaan publik tidak otomatis memberikan hak redistribusi atau otoritas trading. Rasio yang kekurangan input ditampilkan sebagai N/A—tanpa asumsi pengganti.</div>
    @if(session('fundamental_notice'))<div class="success" role="status">{{ session('fundamental_notice') }}</div>@endif
    @if($errors->any())<div class="error" role="alert"><strong>Impor belum disimpan.</strong> {{ $errors->first() }}</div>@endif
    <div class="finance-layout"><div>
        <div class="section-header"><h2>Riwayat emiten · {{ count($records) }} ticker</h2><span class="caption">Data pengguna ini saja</span></div>
        @forelse($records as $record)
        <section class="panel financial-card">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:start">
                <div><span class="badge blue">{{ $record['quality_status'] }} · QUALITY {{ $record['data_quality_score'] }}/100</span><h2 style="font-size:1.7rem;margin:12px 0 4px">{{ $record['ticker'] }} · {{ $record['issuer_name'] }}</h2><p class="caption">{{ $record['report_period'] }} · tanggal laporan {{ $record['report_date'] }} · {{ $record['currency'] }} · {{ $record['consolidated'] ? 'Konsolidasian' : 'Non-konsolidasian' }} · {{ $record['version_count'] }} versi</p></div>
                <a class="button" href="{{ route('ii.fundamentals.export', $record['ticker']) }}">Ekspor laporan CSV</a>
            </div>
            <div class="stat-grid">
                <div class="stat"><span>P/E</span><strong>{{ $fmt($record['pe_ratio'],2) }}</strong></div><div class="stat"><span>P/BV</span><strong>{{ $fmt($record['pbv_ratio'],2) }}</strong></div><div class="stat"><span>EV/EBITDA</span><strong>{{ $fmt($record['ev_ebitda_ratio'],2) }}</strong></div><div class="stat"><span>ROE</span><strong>{{ $record['roe_end_pct'] === null ? 'N/A' : $record['roe_end_pct'].'%' }}</strong></div>
                <div class="stat"><span>Revenue growth</span><strong>{{ $record['revenue_growth_pct'] === null ? 'N/A' : $record['revenue_growth_pct'].'%' }}</strong></div><div class="stat"><span>Earnings growth</span><strong>{{ $record['earnings_growth_pct'] === null ? 'N/A' : $record['earnings_growth_pct'].'%' }}</strong></div><div class="stat"><span>Debt / equity</span><strong>{{ $fmt($record['debt_equity_ratio'],2) }}</strong></div><div class="stat"><span>Operating cash flow</span><strong>{{ $fmt($record['operating_cash_flow']) }}</strong></div>
            </div>
            <div class="tablewrap"><table><thead><tr><th>Periode</th><th>Revenue</th><th>Laba pemilik</th><th>Aset</th><th>Ekuitas</th><th>OCF</th><th>Status</th></tr></thead><tbody>
            @foreach($record['years'] as $year)<tr><td>{{ $year['period_end'] }}</td><td>{{ $fmt($year['revenue']) }}</td><td>{{ $fmt($year['net_income']) }}</td><td>{{ $fmt($year['total_assets']) }}</td><td>{{ $fmt($year['equity']) }}</td><td>{{ $fmt($year['operating_cash_flow']) }}</td><td>{{ $year['restated'] ? 'RESTATED' : 'AS REPORTED' }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="source"><strong>Provenance:</strong> {{ $record['source_name'] }} @if($record['source_url']) · <a href="{{ $record['source_url'] }}" rel="noopener noreferrer">sumber</a>@endif<br><strong>Hak penggunaan:</strong> {{ $record['license_status'] }} · {{ $record['usage_rights'] }} · redistribusi {{ $record['redistribution_allowed'] ? 'diizinkan' : 'tidak dinyatakan' }}<br><strong>Checksum SHA-256:</strong> <span class="mono">{{ $record['sha256'] ?? 'legacy unavailable' }}</span><br><strong>Impor:</strong> {{ $record['imported_at'] }} · <strong>Gate:</strong> {{ $record['gate'] }} · {{ $record['decision'] }} · AUTHORITY {{ $record['trading_authority'] }}</div>
        </section>
        @empty <section class="panel"><h2>Belum ada laporan fundamental</h2><p class="muted">Impor dokumen resmi untuk arsip provenance, atau CSV/XBRL untuk normalisasi dan rasio.</p><a href="{{ route('ii.fundamentals.example') }}">Unduh template CSV →</a></section>
        @endforelse
        @if($documents->isNotEmpty())
        <section class="panel"><h2>Register dokumen sumber</h2><div class="tablewrap"><table><thead><tr><th>Emiten</th><th>Periode</th><th>Format</th><th>Kualitas</th><th>Hak</th><th>Checksum</th></tr></thead><tbody>
        @foreach($documents as $document)<tr><td>{{ $document->issuer_code }}<br><span class="caption">{{ $document->issuer_name }}</span></td><td>{{ $document->report_period }}<br><span class="caption">{{ $document->report_date->toDateString() }}</span></td><td>{{ strtoupper($document->format) }}</td><td>{{ $document->quality_status }} · {{ $document->quality_score }}/100</td><td>{{ $document->source->license_status }}<br><span class="caption">{{ $document->source->usage_rights }}</span></td><td class="mono">{{ substr($document->sha256,0,16) }}…</td></tr>@endforeach
        </tbody></table></div></section>
        @endif
    </div>
    <section class="panel form-card" aria-labelledby="import-title"><span class="badge">IMMUTABLE SOURCE REGISTER</span><h2 id="import-title" style="margin-top:12px">Impor laporan emiten</h2>
        <form method="POST" action="{{ route('ii.fundamentals.import') }}" enctype="multipart/form-data">@csrf
            <div class="split"><div class="field"><label for="ticker">Kode emiten</label><input id="ticker" name="ticker" required maxlength="16" placeholder="TLKM.JK" value="{{ old('ticker') }}"></div><div class="field"><label for="issuer_name">Nama emiten</label><input id="issuer_name" name="issuer_name" required maxlength="200" value="{{ old('issuer_name') }}"></div></div>
            <input type="hidden" name="kind" value="user_supplied">
            <div class="split"><div class="field"><label for="report_period">Periode laporan</label><input id="report_period" name="report_period" required placeholder="FY 2024" value="{{ old('report_period') }}"></div><div class="field"><label for="report_date">Tanggal laporan</label><input id="report_date" type="date" name="report_date" required value="{{ old('report_date') }}"></div></div>
            <div class="split"><div class="field"><label for="currency">Mata uang</label><select id="currency" name="currency"><option>IDR</option><option @selected(old('currency')==='USD')>USD</option></select></div><div class="field"><label for="multiplier">Satuan angka</label><select id="multiplier" name="multiplier"><option value="1">Unit</option><option value="1000">Ribuan</option><option value="1000000">Jutaan</option><option value="1000000000" @selected(old('multiplier','1000000000')==='1000000000')>Miliaran</option></select></div></div>
            <div class="field"><label class="checkline"><input type="checkbox" name="consolidated" value="1" @checked(old('consolidated',true))>Laporan konsolidasian</label></div>
            <div class="field"><label for="source_name">Nama dokumen sumber</label><input id="source_name" name="source_name" required maxlength="160" value="{{ old('source_name') }}"></div><div class="field"><label for="publisher">Penerbit</label><input id="publisher" name="publisher" maxlength="160" value="{{ old('publisher') }}"></div>
            <div class="field"><label for="source_url">URL sumber</label><input id="source_url" type="url" name="source_url" maxlength="1000" value="{{ old('source_url') }}"></div><div class="field"><label for="terms_url">URL ketentuan/lisensi</label><input id="terms_url" type="url" name="terms_url" maxlength="1000" value="{{ old('terms_url') }}"></div>
            <div class="split"><div class="field"><label for="license_status">Status sumber</label><select id="license_status" name="license_status"><option value="public_disclosure">Public disclosure</option><option value="licensed">Licensed</option><option value="user_provided">User provided</option><option value="unknown">Unknown</option></select></div><div class="field"><label for="usage_rights">Hak penggunaan</label><select id="usage_rights" name="usage_rights"><option value="internal_research">Internal research</option><option value="licensed_internal">Licensed internal</option><option value="redistribution_permitted">Redistribution permitted</option><option value="unknown">Unknown</option></select></div></div>
            <div class="field"><label for="fundamental_file">CSV, XBRL/XML, atau PDF · max 20MB</label><input id="fundamental_file" type="file" name="fundamental_file" accept=".csv,.xbrl,.xml,.pdf" required><p class="hint">PDF disimpan sebagai dokumen sumber tanpa ekstraksi angka otomatis. CSV/XBRL dinormalisasi. Angka keuangan dikali satuan; saham dan harga selalu unit aktual.</p></div>
            <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required>Saya berhak menggunakan berkas ini dan status hak di atas akurat.</label></div><button type="submit" style="width:100%">Simpan, normalisasi & nilai kualitas</button>
        </form><p class="hint" style="margin-top:16px"><a href="{{ route('ii.fundamentals.example') }}">Unduh template CSV lengkap</a>. Versi sumber lama tidak pernah ditimpa.</p>
    </section></div>
@endsection
