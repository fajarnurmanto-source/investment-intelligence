@extends('ii.layout')
@section('title','Analisis perusahaan')
@section('surface','ii-light-content')
@push('styles')
<style>
.company-page{max-width:1120px;margin:auto;display:grid;gap:20px}.company-page h1{font-size:clamp(1.65rem,3vw,2.4rem)}.company-search{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.company-search .field{flex:1;min-width:180px;margin:0}.company-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.company-metric{background:var(--surface);padding:16px;border-radius:14px;border:1px solid var(--line)}.company-metric strong{display:block;font-size:1.6rem;font-variant-numeric:tabular-nums}.company-page details summary{cursor:pointer;color:var(--ii-teal);font-weight:650}.company-page .panel{min-width:0}.company-page .company-hero{background:linear-gradient(125deg,#142a40,#20575b);color:white}.company-page .company-hero h2{color:white}.company-page .company-hero p{color:#dceced}.company-page .company-hero .caption{color:#dceced}@media(max-width:650px){.company-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
@endpush
@section('content')
<div class="company-page">
<header><p class="eyebrow">Riset saham</p><h1>Analisis perusahaan</h1><p class="lead">Pelajari kinerja keuangan perusahaan berdasarkan laporan yang tersedia dalam akun Anda.</p></header>
<section class="panel">
<form method="GET" action="{{ route('ii.company') }}" class="company-search">
<div class="field"><label for="company-ticker">Kode saham</label><input id="company-ticker" name="ticker" value="{{ $ticker }}" maxlength="16" list="company-list" placeholder="Contoh: BBCA" required><datalist id="company-list">@foreach($records as $item)<option value="{{ $item['ticker'] }}">{{ $item['issuer_name'] }}</option>@endforeach</datalist></div>
<button type="submit">Lihat perusahaan</button><a class="button quiet" href="{{ route('ii.screener.index') }}">Cari saham</a>
</form></section>
@if($record !== null)
@php($show = fn($value,$suffix='') => $value === null ? '—' : number_format((float)$value,2,',','.').$suffix)
<section class="panel company-hero"><p class="caption">{{ $record['sector'] ?? 'Sektor belum tersedia' }} · Tahun {{ $record['latest_year'] }}</p><h2 style="font-size:2rem">{{ $record['ticker'] }}</h2><p>{{ $record['issuer_name'] }}</p><p class="caption">Data laporan yang tersedia dalam akun. Belum disahkan sebagai dasar keputusan investasi.</p></section>
<section class="panel"><h2>Kinerja dan valuasi</h2>
<div class="company-metrics">
@foreach(['Margin laba'=>['net_margin_pct','%'],'Pertumbuhan pendapatan'=>['revenue_growth_pct','%'],'Pertumbuhan laba'=>['earnings_growth_pct','%'],'ROE'=>['roe_end_pct','%'],'P/E'=>['pe_ratio',''],'P/BV'=>['pbv_ratio',''],'EV/EBITDA'=>['ev_ebitda_ratio',''],'Rasio utang / ekuitas'=>['debt_equity_ratio',''],'EPS'=>['eps','']] as $label=>$metric)
<div class="company-metric"><span class="caption">{{ $label }}</span><strong>{{ $show($record[$metric[0]] ?? null,$metric[1]) }}</strong></div>
@endforeach
</div><p class="caption">Tanda — berarti data belum mencukupi. ROE menggunakan ekuitas akhir periode. Rasio valuasi bergantung pada harga dan kesesuaian data saham; bukan estimasi nilai wajar.</p><p class="caption">Tanggal harga: {{ $record['market_price_as_of'] ?? 'Belum tersedia' }}.</p></section>
<section class="panel"><h2>Riwayat keuangan</h2><p class="caption">Mata uang: {{ $record['currency'] ?? 'Belum diketahui' }}</p><div class="tablewrap"><table><thead><tr><th>Tahun</th><th>Pendapatan</th><th>Laba bersih</th><th>Arus kas operasi</th></tr></thead><tbody>@forelse($record['years'] as $year)<tr><td>{{ $year['year'] }}</td><td>{{ $show($year['revenue'] ?? null) }}</td><td>{{ $show($year['net_income'] ?? null) }}</td><td>{{ $show($year['operating_cash_flow'] ?? null) }}</td></tr>@empty<tr><td colspan="4">Riwayat belum tersedia.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><details><summary>Sumber dan kualitas data</summary><p>Sumber: {{ $record['source_name'] ?? 'Belum tersedia' }}. Kelengkapan: {{ $record['data_quality_score'] ?? '—' }}/100. Kelengkapan bukan verifikasi independen.</p><p class="caption">Data belum memberikan otorisasi transaksi. Periksa laporan, tanggal harga dan kesesuaian basis saham sebelum mengandalkan rasio.</p><a href="{{ route('ii.fundamentals.index') }}">Lihat laporan dan pemeriksaan data</a></details></section>
@elseif(!$demo)
<section class="panel"><h2>{{ $ticker === '' ? 'Pilih perusahaan' : 'Data '.$ticker.' belum tersedia' }}</h2><p>Tambahkan laporan keuangan perusahaan untuk melihat rasio dan riwayat kinerjanya. Data pasar tidak otomatis menggantikan laporan keuangan.</p><a class="button" href="{{ route('ii.fundamentals.index') }}">Buka laporan keuangan</a></section>
@endif
<section class="panel">
<details @if($demo) open @endif><summary>Latihan analisis dengan data simulasi</summary><p class="caption">Contoh berikut menggunakan perusahaan fiktif, bukan saham yang diperdagangkan.</p>
<p>@foreach($universe as $item)<a class="button quiet" href="{{ route('ii.company',['ticker'=>$item['ticker']]) }}">{{ $item['ticker'] }}</a> @endforeach</p>
@if($demo)
<h2>{{ $research['ticker'] }} — Contoh simulasi</h2><p>{{ $ticker === 'DEMO2' ? 'Perusahaan konsumer fiktif' : 'Perusahaan industri fiktif' }}. Apakah margin laba dapat bertahan di atas 15%?</p>
<div class="tablewrap"><table><thead><tr><th>Periode</th><th>Margin laba (%)</th><th>Indeks pendapatan</th></tr></thead><tbody>@foreach($research['history'] as $point)<tr><td>{{ $point['period'] }}</td><td>{{ $point['net_margin_pct'] }}</td><td>{{ $point['revenue_index'] }}</td></tr>@endforeach</tbody></table></div><p class="caption">Indeks pendapatan adalah angka simulasi, bukan nilai pendapatan sebenarnya.</p>
<h3>Uji asumsi margin laba</h3>
<form method="POST" action="{{ route('ii.scenario') }}">@csrf<input type="hidden" name="ticker" value="{{ $ticker }}"><div class="grid"><div class="field"><label for="observed_value">Margin asumsi (%)</label><input id="observed_value" name="observed_value" type="number" step="any" min="-100" max="100" value="{{ old('observed_value',8) }}" required></div><div class="field"><label for="minimum_value">Target margin (%)</label><input id="minimum_value" name="minimum_value" type="number" step="any" min="-100" max="100" value="{{ old('minimum_value',15) }}" required></div><div class="field"><label for="falsification_below">Batas yang menggugurkan asumsi (%)</label><input id="falsification_below" name="falsification_below" type="number" step="any" min="-100" max="100" value="{{ old('falsification_below',10) }}" required></div></div><button type="submit">Uji asumsi</button></form>
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
@if(session('scenario_result.ticker') === $ticker)
@php($scenario = session('scenario_result.scenario_result'))
<div class="boundary"><strong>Hasil latihan:</strong> {{ $scenario['thesis_falsified'] ? 'Asumsi gugur.' : ($scenario['research_state'] === 'RESEARCH_REVIEW_READY' ? 'Target margin tercapai dalam skenario ini.' : 'Target margin belum tercapai.') }} Hasil ini bukan rekomendasi investasi.</div>
@endif
@endif
</details></section>
</div>
@endsection
