@extends('ii.layout')
@section('title','Fundamental Screener')
@push('styles')
<style>.screen-form{display:grid;grid-template-columns:repeat(4,minmax(125px,1fr));gap:12px;align-items:end}.score{font-size:1.35rem;font-weight:800;color:var(--cyan)}.reason{display:inline-block;margin:2px;padding:3px 7px;border-radius:999px;background:#173b42;color:#a8f4ef;font-size:.68rem}@media(max-width:1000px){.screen-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.screen-form{grid-template-columns:1fr}}.screen-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}.screen-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;overflow-wrap:anywhere}.screen-card h3{font-size:1.17rem;margin:4px 0}.screen-card .screen-key{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:15px 0}.screen-card .screen-key span{display:block;font-size:.73rem;color:var(--muted)}.screen-card .screen-key strong{font-size:1.12rem}.screen-table{margin-top:18px}.screen-table summary{cursor:pointer;font-weight:760;color:var(--cyan)}@media(max-width:1100px){.screen-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.screen-cards{grid-template-columns:minmax(0,1fr)}}
</style>
@endpush
@section('content')
    <p class="eyebrow">II / Idea Generation / Benchmark Gap</p>
    <h1>Fundamental Screener</h1>
    @php($show = fn($value, $suffix = '') => $value === null ? 'N/A' : number_format((float)$value, 2).$suffix)
    <p class="lead">Saring snapshot fundamental milik Anda berdasarkan profitabilitas, pertumbuhan, ROE, dan arus kas. Valuation hanya muncul bila harga, saham, utang, kas, dan EBITDA yang diperlukan tersedia.</p>
    <div class="boundary"><strong>NOT_ADMITTED · HOLD · TRADING_AUTHORITY=NONE.</strong> Skor adalah ringkasan aturan transparan, bukan fair value, rekomendasi, atau ranking investasi. N/A berarti input wajib tidak tersedia.</div>
    <section class="panel"><form method="GET" action="{{ route('ii.screener.index') }}" class="screen-form">
        <div class="field"><label for="sector">Sektor</label><select id="sector" name="sector"><option value="">Semua sektor</option>@foreach($sectors as $sector)<option value="{{ $sector }}" @selected($filters['sector']===$sector)>{{ $sector }}</option>@endforeach</select></div>
        <div class="field"><label for="period">Periode terakhir</label><select id="period" name="period"><option value="">Semua periode</option>@foreach($periods as $period)<option value="{{ $period }}" @selected($filters['period']===$period)>{{ $period }}</option>@endforeach</select></div>
        <div class="field"><label for="min_margin">Min margin %</label><input id="min_margin" name="min_margin" type="number" step="0.1" value="{{ $filters['min_margin'] }}"></div>
        <div class="field"><label for="min_growth">Min growth %</label><input id="min_growth" name="min_growth" type="number" step="0.1" value="{{ $filters['min_growth'] }}"></div>
        <div class="field"><label for="min_roe">Min ROE %</label><input id="min_roe" name="min_roe" type="number" step="0.1" value="{{ $filters['min_roe'] }}"></div>
        <div class="field"><label for="sort">Urutkan</label><select id="sort" name="sort"><option value="quality_score" @selected($filters['sort']==='quality_score')>Screen score</option><option value="data_quality_score" @selected($filters['sort']==='data_quality_score')>Data quality</option><option value="pe_ratio" @selected($filters['sort']==='pe_ratio')>P/E</option><option value="pbv_ratio" @selected($filters['sort']==='pbv_ratio')>P/BV</option><option value="ev_ebitda_ratio" @selected($filters['sort']==='ev_ebitda_ratio')>EV/EBITDA</option><option value="revenue_growth_pct" @selected($filters['sort']==='revenue_growth_pct')>Revenue growth</option><option value="earnings_growth_pct" @selected($filters['sort']==='earnings_growth_pct')>Earnings growth</option><option value="roe_end_pct" @selected($filters['sort']==='roe_end_pct')>ROE</option><option value="debt_equity_ratio" @selected($filters['sort']==='debt_equity_ratio')>Debt/equity</option></select></div>
        <div class="field"><label for="sort_direction">Arah</label><select id="sort_direction" name="sort_direction"><option value="desc" @selected($filters['sort_direction']==='desc')>Tertinggi</option><option value="asc" @selected($filters['sort_direction']==='asc')>Terendah</option></select></div>
        <div><label><input style="width:auto" type="checkbox" name="cash_positive" value="1" @checked($filters['cash_positive'])> OCF positif</label><button type="submit" style="width:100%;margin-top:8px">Terapkan screen</button></div>
    </form></section>
    <div class="section-header"><h2>{{ count($rows) }} emiten cocok</h2><a class="button" href="{{ route('ii.screener.export', request()->query()) }}">Export CSV</a></div>
    @if(count($rows) === 0)
        <section class="panel"><h2>Belum ada hasil</h2><p class="muted">Impor laporan di Issuer Fundamentals atau longgarkan filter.</p><a href="{{ route('ii.fundamentals.index') }}">Buka Issuer Fundamentals →</a></section>
    @else
        <div class="screen-cards" aria-label="Perbandingan singkat emiten">
            @foreach($rows as $row)
                <article class="screen-card">
                    <span class="badge blue">{{ $row['sector'] }} · FY{{ $row['latest_year'] }}</span>
                    <h3>{{ $row['ticker'] }}</h3><p class="caption">{{ $row['issuer_name'] }}</p>
                    <div class="screen-key">
                        <div><span>Screen score</span><strong>{{ $row['quality_score'] }}</strong></div>
                        <div><span>Data lengkap</span><strong>{{ $row['data_quality_score'] }}/100</strong></div>
                        <div><span>P/E</span><strong>{{ $show($row['pe_ratio']) }}</strong></div>
                        <div><span>P/BV</span><strong>{{ $show($row['pbv_ratio']) }}</strong></div>
                        <div><span>ROE</span><strong>{{ $show($row['roe_end_pct'],'%') }}</strong></div>
                        <div><span>Growth laba</span><strong>{{ $show($row['earnings_growth_pct'],'%') }}</strong></div>
                    </div>
                    <div class="caption">Valuasi: {{ $row['valuation_status'] }} · {{ $row['market_price_as_of'] ?? 'harga belum tersedia' }}</div>
                    <div class="caption">Basis saham: {{ $row['share_basis_status'] ?? 'NOT_APPLICABLE' }} · keputusan {{ $row['decision'] }}</div>
                    <a href="{{ route('ii.fundamentals.index', ['workspace' => 'overview']) }}">Lihat fundamental →</a>
                </article>
            @endforeach
        </div>
        <details class="panel screen-table"><summary>Tampilkan tabel perbandingan lengkap ({{ count($rows) }} emiten)</summary><div class="tablewrap"><table><thead><tr><th>Emiten</th><th>Score / kelengkapan</th><th>P/E</th><th>P/BV</th><th>EV/EBITDA</th><th>Growth revenue / laba</th><th>ROE</th><th>D/E</th><th>OCF</th><th>Sumber, konflik & gate</th></tr></thead><tbody>
            @foreach($rows as $row)<tr><td><strong>{{ $row['ticker'] }}</strong><br><span class="caption">{{ $row['issuer_name'] }}<br>{{ $row['sector'] }} · FY{{ $row['latest_year'] }}</span></td><td class="score">{{ $row['quality_score'] }}<br><span class="caption">complete {{ $row['data_quality_score'] }}/100</span></td><td>{{ $show($row['pe_ratio']) }}<br><span class="caption">{{ $row['market_price_as_of'] ?? 'no price date' }}<br>{{ $row['market_price_source'] ?? 'no price source' }}<br>{{ $row['valuation_status'] }}</span></td><td>{{ $show($row['pbv_ratio']) }}</td><td>{{ $show($row['ev_ebitda_ratio']) }}</td><td>{{ $show($row['revenue_growth_pct'],'%') }} / {{ $show($row['earnings_growth_pct'],'%') }}</td><td>{{ $show($row['roe_end_pct'],'%') }}</td><td>{{ $show($row['debt_equity_ratio']) }}</td><td>{{ $show($row['operating_cash_flow']) }}</td><td>{{ $row['source_name'] }}<br><span class="caption">{{ $row['source_authenticity_status'] }} · {{ $row['numeric_reconciliation_status'] }} · {{ $row['licensing_assessment'] }}<br>{{ $row['conflict_status'] }} · {{ $row['gate'] }} · {{ $row['decision'] }}</span></td></tr>@endforeach
        </tbody></table></div></details>
    @endif
@endsection
