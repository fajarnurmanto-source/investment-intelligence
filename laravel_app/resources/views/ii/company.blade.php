@extends('ii.layout')
@section('title','Company Research')
@section('content')
    <p class="eyebrow">II / Company Research</p>
    <h1>Company Research</h1>
    <p class="lead">Analisis fundamental sintetis dengan asal data dan catatan risiko. Data ini tidak terhubung ke harga pasar yang diimpor ke Signal Center.</p>
    <div class="boundary"><strong>GENERATED DEMO · NOT_ADMITTED · HOLD · AUTHORITY NONE.</strong> Tidak ada valuasi yang disahkan atau transaksi.</div>
    <p><a class="button" href="{{ route('ii.company', ['ticker'=>'DEMO2']) }}">DEMO2</a> <a class="button" href="{{ route('ii.company', ['ticker'=>'DEMO3']) }}">DEMO3</a> <a class="button" href="{{ route('ii.signals.index') }}">Buka Signal Center →</a></p>
    @if(isset($research['error']))
        <div class="error">{{ $research['error'] }}</div>
    @else
        <div class="grid" style="margin-top:20px">
            <div class="panel"><span class="badge blue">RESEARCH FIXTURE</span><h2 style="margin-top:15px">{{ $research['ticker'] ?? $ticker }}</h2><p>{{ $research['name'] ?? '' }}</p><p class="muted">{{ $research['thesis'] ?? '' }}</p></div>
            <div class="panel"><span class="badge amber">DECISION</span><div class="metric" style="margin-top:14px">HOLD</div><p class="caption">Evidence: NOT_ADMITTED</p></div>
            <div class="panel"><span class="badge">REVIEW</span><h2 style="margin-top:15px">Sumber data</h2><p class="caption">{{ $research['provenance']['source_id'] ?? 'N/A' }} · {{ $research['provenance']['rights'] ?? 'N/A' }}</p><p class="muted">Quality: {{ $research['quality']['state'] ?? 'N/A' }}</p></div>
        </div>
        <div class="section-header"><h2>Metrik historis sintetis</h2></div>
        <div class="panel tablewrap"><table><thead><tr><th>Periode</th><th>Net margin (%)</th><th>Revenue index</th></tr></thead><tbody>
            @foreach(($research['history'] ?? []) as $point)
                <tr><td>{{ $point['period'] ?? '' }}</td><td>{{ $point['net_margin_pct'] ?? '' }}</td><td>{{ $point['revenue_index'] ?? '' }}</td></tr>
            @endforeach
        </tbody></table><p class="caption">Revenue index bukan angka pendapatan emiten.</p></div>
        <div class="panel" style="margin-top:15px"><h2>Provenance</h2><p class="muted">{{ $research['provenance']['lineage'] ?? '' }}</p></div>
    @endif
    <div class="panel" style="margin-top:20px;max-width:780px"><h2>Skenario hipotesis (Laravel B1)</h2>
        <p class="caption">Evaluator B1 telah dipindahkan ke PHP/Laravel. Hasil tidak pernah memberikan kewenangan investasi.</p>
        <form method="POST" action="{{ route('ii.scenario') }}">@csrf
            <input type="hidden" name="ticker" value="{{ $ticker }}">
            <div class="grid"><div class="field"><label for="observed_value">Margin asumsi</label><input id="observed_value" type="number" step="any" min="-100" max="100" name="observed_value" value="{{ old('observed_value',8) }}" required></div>
            <div class="field"><label for="minimum_value">Ambang dukungan</label><input id="minimum_value" type="number" step="any" min="-100" max="100" name="minimum_value" value="{{ old('minimum_value',15) }}" required></div>
            <div class="field"><label for="falsification_below">Batas falsifikasi</label><input id="falsification_below" type="number" step="any" min="-100" max="100" name="falsification_below" value="{{ old('falsification_below',10) }}" required></div></div>
            <button type="submit">Hitung skenario Laravel B1</button>
        </form>
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
        @if(session()->has('scenario_result'))
            <h3 style="margin-top:20px">Jejak skenario · HOLD</h3><pre style="overflow-wrap:anywhere;white-space:pre-wrap;color:#b9d8ee;font-size:.8rem">{{ json_encode(session('scenario_result'),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
    </div>
@endsection
