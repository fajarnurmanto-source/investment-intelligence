@extends('ii.layout')
@section('title','Cari saham')
@section('surface','ii-light-content')
@push('styles')
<style>.screen-form{display:grid;grid-template-columns:repeat(4,minmax(125px,1fr));gap:12px;align-items:end}.score{font-size:1.35rem;font-weight:800;color:var(--cyan)}.reason{display:inline-block;margin:2px;padding:3px 7px;border-radius:999px;background:#173b42;color:#a8f4ef;font-size:.68rem}@media(max-width:1000px){.screen-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.screen-form{grid-template-columns:1fr}}.screen-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}.screen-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;overflow-wrap:anywhere}.screen-card h3{font-size:1.17rem;margin:4px 0}.screen-card .screen-key{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:15px 0}.screen-card .screen-key span{display:block;font-size:.73rem;color:var(--muted)}.screen-card .screen-key strong{font-size:1.12rem}.screen-table{margin-top:18px}.screen-table summary{cursor:pointer;font-weight:760;color:var(--cyan)}@media(max-width:1100px){.screen-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.screen-cards{grid-template-columns:minmax(0,1fr)}}
.screen-card{box-shadow:0 4px 18px #142a4009}.screen-card .badge.blue{background:#e9f0f5;color:#34475a;letter-spacing:0}.screen-form .field{margin:0}.screen-form{gap:16px}.screen-card details{margin:12px 0}.screen-card>a{font-weight:650}.screen-card summary{cursor:pointer}.screen-table table{min-width:960px}</style>
@endpush
@section('content')
    <p class="eyebrow">Riset perusahaan</p>
    <h1>Temukan saham</h1>
    @php($show = fn($value, $suffix = '') => $value === null ? 'N/A' : number_format((float)$value, 2).$suffix)
    <p class="lead">Temukan perusahaan sesuai kriteria Anda, berdasarkan laporan keuangan yang tersedia dalam akun.</p>
    <div class="boundary">Hasil membantu Anda memilih bahan penelitian, bukan rekomendasi beli atau jual. Tanda N/A berarti data belum mencukupi.</div>
    <section class="panel"><h2>Kriteria pencarian</h2><form method="GET" action="{{ route('ii.screener.index') }}" class="screen-form">
        <div class="field"><label for="sector">Sektor</label><select id="sector" name="sector"><option value="">Semua sektor</option>@foreach($sectors as $sector)<option value="{{ $sector }}" @selected($filters['sector']===$sector)>{{ $sector }}</option>@endforeach</select></div>
        <div class="field"><label for="period">Periode terakhir</label><select id="period" name="period"><option value="">Semua periode</option>@foreach($periods as $period)<option value="{{ $period }}" @selected($filters['period']===$period)>{{ $period }}</option>@endforeach</select></div>
        <div class="field"><label for="min_margin">Margin laba minimum (%)</label><input id="min_margin" name="min_margin" type="number" step="0.1" value="{{ $filters['min_margin'] }}"></div>
        <div class="field"><label for="min_growth">Pertumbuhan pendapatan minimum (%)</label><input id="min_growth" name="min_growth" type="number" step="0.1" value="{{ $filters['min_growth'] }}"></div>
        <div class="field"><label for="min_roe">ROE minimum (%)</label><input id="min_roe" name="min_roe" type="number" step="0.1" value="{{ $filters['min_roe'] }}"></div>
        <div class="field"><label for="sort">Urutkan</label><select id="sort" name="sort"><option value="quality_score" @selected($filters['sort']==='quality_score')>Skor penyaringan</option><option value="data_quality_score" @selected($filters['sort']==='data_quality_score')>Kelengkapan data</option><option value="pe_ratio" @selected($filters['sort']==='pe_ratio')>P/E</option><option value="pbv_ratio" @selected($filters['sort']==='pbv_ratio')>P/BV</option><option value="ev_ebitda_ratio" @selected($filters['sort']==='ev_ebitda_ratio')>EV/EBITDA</option><option value="revenue_growth_pct" @selected($filters['sort']==='revenue_growth_pct')>Pertumbuhan pendapatan</option><option value="earnings_growth_pct" @selected($filters['sort']==='earnings_growth_pct')>Pertumbuhan laba</option><option value="roe_end_pct" @selected($filters['sort']==='roe_end_pct')>ROE</option><option value="debt_equity_ratio" @selected($filters['sort']==='debt_equity_ratio')>Rasio utang terhadap ekuitas</option></select></div>
        <div class="field"><label for="sort_direction">Arah</label><select id="sort_direction" name="sort_direction"><option value="desc" @selected($filters['sort_direction']==='desc')>Tertinggi</option><option value="asc" @selected($filters['sort_direction']==='asc')>Terendah</option></select></div>
        <div><label><input style="width:auto" type="checkbox" name="cash_positive" value="1" @checked($filters['cash_positive'])> Arus kas operasi positif</label><button type="submit" style="width:100%;margin-top:8px">Cari saham</button></div>
    </form><p class="caption">ROE mengukur laba relatif terhadap ekuitas. <a href="{{ route('ii.screener.index') }}">Hapus semua kriteria</a>.</p></section>
    <div class="section-header"><h2>{{ count($rows) }} perusahaan ditemukan</h2><a class="button" href="{{ route('ii.screener.export', request()->query()) }}">Unduh CSV</a></div>
    @if(count($rows) === 0)
        <section class="panel"><h2>Belum ada hasil</h2><p class="muted">Tambahkan laporan keuangan atau longgarkan kriteria pencarian.</p><a href="{{ route('ii.fundamentals.index') }}">Tambahkan laporan keuangan →</a></section>
    @else
        <div class="screen-cards" aria-label="Perbandingan singkat emiten">
            @foreach($rows as $row)
                <article class="screen-card">
                    <span class="badge blue">{{ $row['sector'] }} · FY{{ $row['latest_year'] }}</span>
                    <h3>{{ $row['ticker'] }}</h3><p class="caption">{{ $row['issuer_name'] }}</p>
                    <div class="screen-key">
                        <div><span>Skor penyaringan</span><strong>{{ $row['quality_score'] }}</strong></div>
                        <div><span>Data lengkap</span><strong>{{ $row['data_quality_score'] }}/100</strong></div>
                        <div><span>P/E</span><strong>{{ $show($row['pe_ratio']) }}</strong></div>
                        <div><span>P/BV</span><strong>{{ $show($row['pbv_ratio']) }}</strong></div>
                        <div><span>ROE</span><strong>{{ $show($row['roe_end_pct'],'%') }}</strong></div>
                        <div><span>Pertumbuhan laba</span><strong>{{ $show($row['earnings_growth_pct'],'%') }}</strong></div>
                    </div>
                    <div class="caption">{{ str_starts_with($row['valuation_status'], 'AVAILABLE_') ? 'Rasio valuasi tersedia untuk diteliti' : 'Rasio valuasi belum lengkap atau perlu diperiksa' }} · {{ $row['market_price_as_of'] ?? 'harga belum tersedia' }}</div>
                    <details><summary class="caption">Pemeriksaan data</summary><p class="caption">Valuasi: {{ $row['valuation_status'] }} · Basis saham: {{ $row['share_basis_status'] ?? 'Tidak berlaku' }}. Data belum disahkan sebagai dasar keputusan investasi.</p></details>
                    <a href="{{ route('ii.company', ['ticker' => $row['ticker']]) }}">Analisis perusahaan →</a>
                </article>
            @endforeach
        </div>
        <details class="panel screen-table"><summary>Tampilkan tabel perbandingan lengkap ({{ count($rows) }} emiten)</summary><div class="tablewrap"><table><thead><tr><th>Emiten</th><th>Score / kelengkapan</th><th>P/E</th><th>P/BV</th><th>EV/EBITDA</th><th>Pertumbuhan pendapatan / laba</th><th>ROE</th><th>D/E</th><th>OCF</th><th>Sumber dan pemeriksaan data</th></tr></thead><tbody>
            @foreach($rows as $row)<tr><td><strong>{{ $row['ticker'] }}</strong><br><span class="caption">{{ $row['issuer_name'] }}<br>{{ $row['sector'] }} · FY{{ $row['latest_year'] }}</span></td><td class="score">{{ $row['quality_score'] }}<br><span class="caption">Kelengkapan {{ $row['data_quality_score'] }}/100</span></td><td>{{ $show($row['pe_ratio']) }}<br><span class="caption">{{ $row['market_price_as_of'] ?? 'Tanggal harga belum tersedia' }}<br>{{ $row['market_price_source'] ?? 'Sumber harga belum tersedia' }}<br>{{ $row['valuation_status'] }}</span></td><td>{{ $show($row['pbv_ratio']) }}</td><td>{{ $show($row['ev_ebitda_ratio']) }}</td><td>{{ $show($row['revenue_growth_pct'],'%') }} / {{ $show($row['earnings_growth_pct'],'%') }}</td><td>{{ $show($row['roe_end_pct'],'%') }}</td><td>{{ $show($row['debt_equity_ratio']) }}</td><td>{{ $show($row['operating_cash_flow']) }}</td><td>{{ $row['source_name'] }}<br><span class="caption">{{ $row['source_authenticity_status'] }} · {{ $row['numeric_reconciliation_status'] }} · {{ $row['licensing_assessment'] }}<br>{{ $row['conflict_status'] }} · {{ $row['gate'] }} · {{ $row['decision'] }}</span></td></tr>@endforeach
        </tbody></table></div></details>
    @endif
@endsection
