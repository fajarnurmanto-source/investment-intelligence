@extends('ii.layout')
@section('title','Product Dashboard')
@section('content')
    <p class="eyebrow">II / Product Overview</p>
    <h1>Satu tempat untuk riset, sinyal, dan keputusan yang terukur.</h1>
    <p class="lead">Signal Center mengolah data harga CSV; Issuer Fundamentals kini mengolah laporan keuangan tahunan beserta asal sumbernya. Keduanya tertaut melalui ticker, tanpa mengaburkan status bukti.</p>
    <div class="grid">
        <div class="panel"><span class="badge">FITUR AKTIF</span><p class="caption" style="margin-top:16px">Seri harga di Signal Center</p><div class="metric">{{ $signalCount }}</div><a href="{{ route('ii.signals.index') }}">Lihat dan impor data →</a></div>
        <div class="panel"><span class="badge blue">RISET TERSIMPAN</span><p class="caption" style="margin-top:16px">Research cases</p><div class="metric">{{ $researchCases }}</div><a href="{{ route('ii.cases.index') }}">Buka kasus riset →</a></div>
        <div class="panel"><span class="badge blue">FUNDAMENTAL HUB</span><p class="caption" style="margin-top:16px">Emiten dengan laporan pengguna</p><div class="metric">{{ $issuerCount }}</div><a href="{{ route('ii.fundamentals.index') }}">Buka fundamental →</a></div>
        <div class="panel"><span class="badge blue">REAL DATA</span><p class="caption" style="margin-top:16px">Seri makro FRED tersambung</p><div class="metric">{{ $macroSeriesCount }}</div><a href="{{ route('ii.macro.index') }}">Buka Macro Intelligence →</a></div>
    </div>
    <div class="boundary"><strong>Batas sistem saat ini:</strong> IMPORTED DATA = UNVERIFIED · RESEARCH = HOLD · TRADING AUTHORITY = NONE. Indikator pasar adalah observasi kuantitatif, bukan rekomendasi atau janji keuntungan.</div>
    <div class="panel"><h2>Market Data Workspace</h2><p class="muted">Pantau cakupan instrumen dan hasil sinkronisasi lokal.</p><a href="{{ route('ii.market-data.index') }}">Buka Market Data Workspace →</a></div>
    <div class="section-header"><h2>Signal Center · snapshot terakhir</h2><a class="button" href="{{ route('ii.signals.index') }}">Buka Signal Center</a></div>
    @if(count($signals) === 0)
        <section class="panel soft"><h2>Siap mengolah seri harga pertama</h2><p class="muted">Belum ada data harga yang diimpor. Unggah minimal 60 sesi OHLCV agar tren SMA20/SMA50, RSI14, dan risiko ATR14 dapat dihitung otomatis.</p><a href="{{ route('ii.signals.index') }}">Mulai impor CSV →</a></section>
    @else
        <div class="grid">
            @foreach($signals as $signal)
                <div class="panel"><span class="badge {{ $signal['stale'] ? 'amber' : 'blue' }}">{{ $signal['state'] }}</span><h2 style="font-size:1.55rem;margin:15px 0 0">{{ $signal['ticker'] }}</h2><p class="caption">Penutupan {{ $signal['last_date'] }} · {{ $signal['kind'] === 'synthetic' ? 'DEMO' : 'IMPORTED' }}</p><div class="metric">{{ number_format($signal['price'], 2) }}</div><p class="caption">RSI14: {{ $signal['rsi14'] }} · ATR14: {{ $signal['atr14_pct'] }}%</p><a href="{{ route('ii.signals.index') }}">Lihat indikator →</a></div>
            @endforeach
        </div>
    @endif
    <div class="section-header"><h2>Kemampuan dalam satu produk</h2></div>
    <div class="grid">
        <div class="panel"><h3>01 · Research Intelligence</h3><p class="muted">Kasus riset, fundamental tahunan, dan screener transparan dengan status bukti yang selalu terlihat.</p><a href="{{ route('ii.screener.index') }}">Buka Fundamental Screener →</a></div>
        <div class="panel"><h3>02 · Trading Signal Intelligence</h3><p class="muted">Strategy Lab, rolling walk-forward, dan Risk Budget untuk menghubungkan pengamatan tren dengan estimasi ukuran posisi serta batas kerugian hipotetis.</p><a href="{{ route('ii.risk-budget.index') }}">Buka Risk Budget →</a></div>
        <div class="panel"><h3>03 · Portfolio Intelligence</h3><p class="muted">Portfolio Sandbox menguji bobot, konsentrasi, korelasi, dan drawdown, kini disertai pemeriksaan cakupan tanggal, kesegaran seri, mata uang, serta lonjakan harga yang perlu ditinjau.</p><a href="{{ route('ii.portfolio.index') }}">Buka Portfolio Sandbox →</a></div>
    </div>
@endsection
