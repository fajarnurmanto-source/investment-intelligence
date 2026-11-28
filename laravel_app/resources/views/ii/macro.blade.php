@extends('ii.layout')
@section('title','Ekonomi makro')
@section('surface','ii-light-content')
@push('styles')
<style>
    .macro-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.macro-chart{background:#0b192a;border:1px solid var(--line);border-radius:11px;padding:10px;margin:16px 0}.macro-chart svg{display:block;width:100%;height:105px}.macro-provenance{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.macro-provenance div{background:#0b192a;border-radius:9px;padding:13px}.macro-provenance span{display:block;color:var(--muted);font-size:.76rem}.macro-provenance strong{display:block;margin-top:5px;overflow-wrap:anywhere}@media(max-width:820px){.macro-grid,.macro-provenance{grid-template-columns:1fr}}
.macro-chart{background:#fff}.macro-provenance div{background:#f1f5f7}.macro-chart path{stroke:#dce2e9}.macro-chart polyline{stroke:#147d78}
</style>
@endpush
@section('content')
    <p class="eyebrow">Pasar &amp; ekonomi</p>
    <h1>Ekonomi makro</h1>
    <p class="lead">Pantau indikator ekonomi dari FRED dan pelajari perubahan dari waktu ke waktu.</p>
    <div class="boundary"><strong>Konteks penelitian.</strong> Data makro bukan sinyal beli/jual. This product uses FRED data but is not endorsed or certified by the Federal Reserve Bank of St. Louis.</div>
    <div class="section-header">
        <h2>Ringkasan ekonomi</h2>
        @if(($rights['status'] ?? 'DENIED') === 'REVIEWED_ALLOWED_LOCAL_NONCOMMERCIAL')
            <a class="button" href="{{ route('ii.macro.report') }}">Unduh CSV pribadi</a>
        @else
            <span class="badge amber">Unduhan belum diizinkan</span>
        @endif
    </div>
    @if(($rights['status'] ?? 'DENIED') !== 'REVIEWED_ALLOWED_LOCAL_NONCOMMERCIAL')
        <p class="caption">Dashboard tetap tersedia untuk penelitian lokal. Pengunduhan memerlukan pemeriksaan hak penggunaan untuk setiap indikator. <a href="{{ route('ii.macro.report.rights') }}">Lihat catatan hak penggunaan</a>.</p>
    @else
        <p class="caption">Izin dibatasi untuk CSV pribadi nonkomersial; tidak termasuk redistribusi atau transfer cloud. <a href="{{ route('ii.macro.report.rights') }}">Lihat manifest hak</a>.</p>
    @endif
    @if(count($dashboard['series']) === 0)
        <section class="panel"><h2>Data ekonomi belum tersedia</h2><p class="muted">Data belum berhasil dimuat. Hubungi pengelola aplikasi untuk memperbarui data. Observasi terakhir yang valid tidak akan diganti oleh hasil pengambilan yang gagal.</p></section>
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
        <section class="panel" style="margin-top:18px"><h2>Sumber dan riwayat data</h2><p class="muted">Setiap indikator memiliki catatan sumber, waktu pengambilan, satuan dan frekuensi. Data yang gagal dimuat tidak menggantikan observasi terakhir yang valid.</p><p class="caption">Ringkasan dibuat {{ $dashboard['generated_at'] }} · <a href="{{ config('ii.fred.terms_url') }}" rel="noopener noreferrer">FRED Terms of Use</a></p></section>
    @endif
@endsection
