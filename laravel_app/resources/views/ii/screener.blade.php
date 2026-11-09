@extends('ii.layout')
@section('title','Fundamental Screener')
@push('styles')
<style>.screen-form{display:grid;grid-template-columns:repeat(5,minmax(125px,1fr));gap:12px;align-items:end}.score{font-size:1.35rem;font-weight:800;color:var(--cyan)}.reason{display:inline-block;margin:2px;padding:3px 7px;border-radius:999px;background:#173b42;color:#a8f4ef;font-size:.68rem}@media(max-width:1000px){.screen-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.screen-form{grid-template-columns:1fr}}</style>
@endpush
@section('content')
    <p class="eyebrow">II / Idea Generation / Benchmark Gap</p>
    <h1>Fundamental Screener</h1>
    <p class="lead">Saring snapshot fundamental milik Anda berdasarkan profitabilitas, pertumbuhan, ROE, dan arus kas; hasil dapat diurutkan dan diekspor tanpa mengubah status bukti.</p>
    <div class="boundary"><strong>USER_IMPORTED_UNVERIFIED · HOLD.</strong> Skor adalah ringkasan aturan transparan, bukan fair value, rekomendasi, atau ranking investasi.</div>
    <section class="panel"><form method="GET" action="{{ route('ii.screener.index') }}" class="screen-form">
        <div class="field"><label for="min_margin">Min margin %</label><input id="min_margin" name="min_margin" type="number" step="0.1" value="{{ $filters['min_margin'] }}"></div>
        <div class="field"><label for="min_growth">Min growth %</label><input id="min_growth" name="min_growth" type="number" step="0.1" value="{{ $filters['min_growth'] }}"></div>
        <div class="field"><label for="min_roe">Min ROE %</label><input id="min_roe" name="min_roe" type="number" step="0.1" value="{{ $filters['min_roe'] }}"></div>
        <div class="field"><label for="sort">Urutkan</label><select id="sort" name="sort"><option value="quality_score" @selected($filters['sort']==='quality_score')>Quality score</option><option value="net_margin_pct" @selected($filters['sort']==='net_margin_pct')>Margin</option><option value="revenue_growth_pct" @selected($filters['sort']==='revenue_growth_pct')>Growth</option><option value="roe_end_pct" @selected($filters['sort']==='roe_end_pct')>ROE</option><option value="eps" @selected($filters['sort']==='eps')>EPS</option></select></div>
        <div><label><input style="width:auto" type="checkbox" name="cash_positive" value="1" @checked($filters['cash_positive'])> OCF positif</label><button type="submit" style="width:100%;margin-top:8px">Terapkan screen</button></div>
    </form></section>
    <div class="section-header"><h2>{{ count($rows) }} emiten cocok</h2><a class="button" href="{{ route('ii.screener.export', request()->query()) }}">Export CSV</a></div>
    @if(count($rows) === 0)
        <section class="panel"><h2>Belum ada hasil</h2><p class="muted">Impor minimal dua tahun laporan di Issuer Fundamentals atau longgarkan filter.</p><a href="{{ route('ii.fundamentals.index') }}">Buka Issuer Fundamentals →</a></section>
    @else
        <section class="panel tablewrap"><table><thead><tr><th>Ticker</th><th>Score</th><th>Margin</th><th>Growth</th><th>ROE</th><th>EPS</th><th>Alasan</th><th>Sumber</th></tr></thead><tbody>
            @foreach($rows as $row)<tr><td><strong>{{ $row['ticker'] }}</strong></td><td class="score">{{ $row['quality_score'] }}</td><td>{{ $row['net_margin_pct'] }}%</td><td>{{ $row['revenue_growth_pct'] }}%</td><td>{{ $row['roe_end_pct'] }}%</td><td>{{ $row['eps'] }}</td><td>@foreach($row['screen_reasons'] as $reason)<span class="reason">{{ $reason }}</span>@endforeach</td><td>{{ $row['source_name'] }}<br><span class="caption">{{ $row['gate'] }}</span></td></tr>@endforeach
        </tbody></table></section>
    @endif
@endsection
