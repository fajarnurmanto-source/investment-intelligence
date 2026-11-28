@extends('ii.layout')
@section('title','Dashboard')
@section('surface','ii-light-content')
@push('styles')
<style>
.dash{display:grid;gap:22px;max-width:1180px;margin:auto}.dash h1{font-size:clamp(1.65rem,3vw,2.4rem);margin-bottom:8px}.dash p{margin:0 0 12px}.dash .hero{padding:clamp(22px,4vw,40px);border-radius:22px;background:radial-gradient(ellipse at 95% 0%,#357d7a,transparent 55%),linear-gradient(125deg,#142a40,#204956);color:#fff;box-shadow:var(--ii-shadow)}.dash .hero h1{color:white}.dash .hero p{color:#e0eeee;max-width:620px}.dash .hero .button{background:white;color:#125d58;margin-top:8px}.dash-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.dash-metrics a{color:var(--ii-navy);text-decoration:none;display:block}.dash-metrics strong{display:block;font-size:2rem;font-variant-numeric:tabular-nums;letter-spacing:-.04em}.dash-metrics a:hover{border-color:var(--ii-teal)}.dash-tasks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.dash-task{display:flex;flex-direction:column;align-items:flex-start;gap:8px}.dash-task p{flex:1;color:var(--muted);font-size:.9rem}.dash-task a{font-weight:650}.dash .panel{min-width:0}.dash .section-header{margin:0 0 14px}.dash table{min-width:400px}.dash .tag{font-size:.75rem;color:var(--muted)}@media(max-width:1050px){.dash-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-tasks{grid-template-columns:1fr}}@media(max-width:420px){.dash{gap:16px}.dash-metrics{gap:8px}.dash-metrics .panel{padding:12px}.dash-metrics strong{font-size:1.65rem}}
</style>
@endpush
@section('content')
<div class="dash">
<header class="hero">
    <p>Ruang riset investasi</p>
    <h1>Dashboard</h1>
    <p>Kenali kondisi pasar, temukan saham untuk diteliti, lalu uji strategi Anda.</p>
    <a class="button" href="{{ route('ii.market-intelligence.index') }}">Analisis pasar</a>
</header>
<section aria-labelledby="dash-coverage">
    <div class="section-header"><h2 id="dash-coverage">Data penelitian Anda</h2><a href="{{ route('ii.market-data.index') }}">Kelola data</a></div>
    <div class="dash-metrics">
        <a class="panel" href="{{ route('ii.signals.index') }}"><span class="caption">Seri harga</span><strong>{{ $signalCount }}</strong><span class="tag">Lihat indikator</span></a>
        <a class="panel" href="{{ route('ii.fundamentals.index') }}"><span class="caption">Data perusahaan</span><strong>{{ $issuerCount }}</strong><span class="tag">Lihat laporan</span></a>
        <a class="panel" href="{{ route('ii.macro.index') }}"><span class="caption">Seri ekonomi makro</span><strong>{{ $macroSeriesCount }}</strong><span class="tag">Lihat ekonomi</span></a>
        <a class="panel" href="{{ route('ii.cases.index') }}"><span class="caption">Catatan riset</span><strong>{{ $researchCases }}</strong><span class="tag">Buka penelitian</span></a>
    </div>
</section>
<section aria-labelledby="dash-tasks">
    <div class="section-header"><h2 id="dash-tasks">Apa yang ingin Anda lakukan?</h2></div>
    <div class="dash-tasks">
        <article class="panel dash-task"><h3>Temukan saham</h3><p>Saring perusahaan berdasarkan laba, pertumbuhan, valuasi dan arus kas yang tersedia.</p><a href="{{ route('ii.screener.index') }}">Cari saham →</a></article>
        <article class="panel dash-task"><h3>Uji strategi</h3><p>Pelajari bagaimana aturan investasi bekerja pada data historis yang tersedia.</p><a href="{{ route('ii.strategy.index') }}">Buka Backtesting →</a></article>
        <article class="panel dash-task"><h3>Kelola risiko</h3><p>Simulasikan portofolio dan pelajari potensi kerugian sebelum mengambil keputusan.</p><a href="{{ route('ii.portfolio.index') }}">Simulasi portofolio →</a><a href="{{ route('ii.risk-budget.index') }}">Hitung batas risiko →</a></article>
    </div>
</section>
<section class="panel" aria-labelledby="dash-recent">
    <div class="section-header"><h2 id="dash-recent">Harga terakhir dalam penelitian</h2><a href="{{ route('ii.signals.index') }}">Lihat semua</a></div>
    @if(count($signals) === 0)
    <p class="muted">Belum ada seri harga. Tambahkan riwayat perdagangan untuk melihat tren dan indikator risiko.</p>
    <a class="button" href="{{ route('ii.signals.index') }}">Tambahkan data harga</a>
    @else
    <div class="tablewrap"><table><thead><tr><th>Saham</th><th>Tanggal</th><th>Harga penutupan</th><th>Jenis data</th></tr></thead><tbody>
    @foreach($signals as $signal)
    <tr><td><strong>{{ $signal['ticker'] }}</strong></td><td>{{ $signal['last_date'] }} @if($signal['stale'])<span class="tag">· Perlu diperbarui</span>@endif</td><td>{{ number_format($signal['price'],2,',','.') }}</td><td>{{ $signal['kind'] === 'synthetic' ? 'Simulasi' : 'Data impor, belum terverifikasi' }}</td></tr>
    @endforeach
    </tbody></table></div>
    @endif
    <p class="caption" style="margin-top:12px">Harga di atas bukan kutipan pasar langsung. Indikator penelitian bukan rekomendasi investasi.</p>
</section>
<details class="panel"><summary>Informasi penggunaan</summary><p class="muted" style="margin-top:12px">Data yang dimasukkan pengguna belum otomatis terverifikasi. II membantu penelitian dan simulasi; aplikasi tidak memberikan otorisasi transaksi.</p></details>
</div>
@endsection
