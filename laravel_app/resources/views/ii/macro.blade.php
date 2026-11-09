@extends('ii.layout')
@section('title','Macro Intelligence')
@push('styles')
<style>
    .macro-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.macro-chart{background:#0b192a;border:1px solid var(--line);border-radius:11px;padding:10px;margin:16px 0}.macro-chart svg{display:block;width:100%;height:105px}.macro-provenance{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.macro-provenance div{background:#0b192a;border-radius:9px;padding:13px}.macro-provenance span{display:block;color:var(--muted);font-size:.76rem}.macro-provenance strong{display:block;margin-top:5px;overflow-wrap:anywhere}@media(max-width:820px){.macro-grid,.macro-provenance{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
    <p class="eyebrow">II / Real Data / Macro Intelligence</p>
    <h1>Macro Intelligence</h1>
    <p class="lead">Data ekonomi nyata mengalir dari sumber FRED ke Laravel, dinormalisasi, dianalisis, lalu disajikan dengan provenance dan kualitas yang dapat ditelusuri.</p>
    <div class="boundary"><strong>RESEARCH CONTEXT ONLY.</strong> Data makro bukan sinyal beli/jual. This product uses FRED data but is not endorsed or certified by the Federal Reserve Bank of St. Louis.</div>
    <div class="section-header"><h2>Snapshot ekonomi</h2><a class="button" href="{{ route('ii.macro.report') }}">Unduh report CSV</a></div>
    @if(count($dashboard['series']) === 0)
        <section class="panel"><h2>Connector siap, ingestion belum dijalankan</h2><p class="muted">Jalankan <code>php artisan ii:ingest-fred --sync</code>. Kegagalan dicatat sebagai ingestion run tanpa menimpa observasi terakhir yang valid.</p><span class="badge amber">{{ $dashboard['pipeline_status'] }}</span></section>
    @else
        <div class="macro-grid">
            @foreach($dashboard['series'] as $series)
                <article class="panel">
                    <span class="badge blue">{{ $series['id'] }} · {{ $series['frequency'] }}</span>
                    <h2 style="margin-top:14px">{{ $series['title'] }}</h2>
                    <div class="metric">{{ number_format($series['latest_value'], 2) }}</div>
                    <p class="caption">{{ $series['units'] }} · observasi {{ $series['latest_date'] }}</p>
                    <div class="macro-chart"><svg viewBox="0 0 460 100" role="img" aria-label="Tren 36 observasi terakhir {{ $series['id'] }}"><path d="M8 16H452 M8 52H452 M8 88H452" stroke="#284358" stroke-dasharray="5 6" fill="none"/><polyline points="{{ $series['sparkline'] }}" fill="none" stroke="#61d5e8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    <div class="macro-provenance">
                        <div><span>{{ $series['headline_label'] }}</span><strong>{{ $series['headline_value'] === null ? 'N/A' : $series['headline_value'].$series['headline_suffix'] }}</strong></div>
                        <div><span>Volatilitas perubahan</span><strong>{{ $series['change_volatility_pct'] === null ? 'N/A' : $series['change_volatility_pct'].'%' }}</strong></div>
                        <div><span>Kualitas</span><strong>{{ $series['quality_status'] }}</strong></div>
                    </div>
                    <p class="caption">Pemilik seri: {{ $series['owner'] }} · diambil {{ $series['retrieved_at'] }}</p>
                    <a href="{{ $series['source_url'] }}" rel="noopener noreferrer">Buka sumber asli →</a>
                </article>
            @endforeach
        </div>
        <section class="panel" style="margin-top:18px"><h2>Provenance & failure handling</h2><p class="muted">Setiap observasi menyimpan source URL, timestamp retrieval, checksum response, run ID, units, frequency, dan quality status. Ingestion memakai allow-list dua seri, retry terbatas, timeout, batas payload 5 MB, transaksi database, serta cache invalidation.</p><p class="caption">Pipeline: {{ $dashboard['pipeline_status'] }} · report dibuat {{ $dashboard['generated_at'] }} · <a href="{{ config('ii.fred.terms_url') }}" rel="noopener noreferrer">FRED Terms of Use</a></p></section>
    @endif
@endsection
