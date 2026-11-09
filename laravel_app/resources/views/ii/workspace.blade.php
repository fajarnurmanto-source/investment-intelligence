@extends('ii.layout')

@section('title', 'Reviewer Workspace')

@push('styles')
<style>
    .research-workspace-links{display:flex;gap:14px;flex-wrap:wrap;margin:0 0 18px;font-size:.87rem}
    .research-explore-form{display:grid;grid-template-columns:minmax(170px,1fr) auto auto;gap:13px;align-items:end}
    .research-explore-form .field{margin:0}
    .research-explore-form .button{white-space:nowrap}
    .research-workspace-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.8fr);gap:18px;margin-top:19px;align-items:start}
    .research-workspace-grid .panel{margin-bottom:18px}
    .research-workspace-grid code{overflow-wrap:anywhere}
    .research-workspace-table{min-width:440px}
    .research-workspace-status{color:var(--amber);font-weight:700;font-size:.81rem}
    .research-workspace-textarea{width:100%;min-height:95px;resize:vertical;border-radius:8px;border:1px solid #405570;background:#0b192a;color:var(--text);padding:11px 13px;font:inherit}
    .research-workspace-note{border-top:1px solid var(--line);padding:12px 0;overflow-wrap:anywhere}
    .research-workspace-note:first-of-type{border-top:0}
    .research-workspace-note p{white-space:pre-wrap;margin:4px 0}
    @media(max-width:1220px){.research-workspace-grid{grid-template-columns:1fr}}
    @media(max-width:760px){.research-explore-form{grid-template-columns:1fr}.research-explore-form button,.research-explore-form .button{width:100%;text-align:center}}
</style>
@endpush

@section('content')
<p class="eyebrow">II / Investment Research / VS11 R3</p>
<h1>Reviewer Workspace</h1>
<p class="lead">Telusuri perusahaan demo, periksa provenance, dan catat temuan sesi tanpa memberikan persetujuan investasi atau bukti.</p>

<nav class="research-workspace-links" aria-label="Tautan terkait workspace reviewer">
    <a href="{{ route('ii.dashboard') }}">← Dashboard</a>
    <a href="{{ route('ii.signals.index') }}">Trading Signal Center</a>
    <a href="{{ route('ii.cases.index') }}">Research Cases</a>
</nav>

<div class="boundary" role="note">
    <strong>NOT_ADMITTED · HOLD · AUTHORITY NONE.</strong> No real issuer data, valuation authority, or trading permission.
    Reviewer statuses are not evidence admission decisions. Session notes are not persistent case records.
</div>

@if(session('ii_vs4_notice'))
    <p class="success" role="status">{{ session('ii_vs4_notice') }}</p>
@endif

<section class="panel" aria-labelledby="research-explore-heading">
    <h2 id="research-explore-heading">Pilih perusahaan riset</h2>
    <form action="{{ url('/ii/workspace') }}" method="GET" class="research-explore-form">
        <div class="field">
            <label for="research-workspace-ticker">Perusahaan</label>
            <select name="ticker" id="research-workspace-ticker">
                @foreach($universe as $item)
                    @if(in_array($item['ticker'] ?? '', ['DEMO2','DEMO3']))
                        <option value="{{ $item['ticker'] }}" @selected($ticker === $item['ticker'])>{{ $item['ticker'] }} — {{ $item['name'] }}</option>
                    @endif
                @endforeach
            </select>
        </div>
        <button type="submit">Jelajahi</button>
        <a class="button" href="{{ url('/ii/workspace/report') }}?ticker={{ $ticker }}">Unduh laporan Markdown</a>
    </form>
</section>

<div class="research-workspace-grid">
    <div>
        <section class="panel" aria-labelledby="research-company-metrics">
            <h2 id="research-company-metrics">{{ $ticker }} · {{ $data['name'] }}</h2>
            <p>{{ $data['sector'] }} · Thesis: {{ $data['thesis'] }}</p>
            <h3>Metrik historis</h3>
            <div class="tablewrap" role="region" aria-label="Metrik historis perusahaan" tabindex="0">
                <table class="research-workspace-table">
                    <thead><tr><th scope="col">Periode</th><th scope="col">Net margin (%)</th><th scope="col">Revenue index</th></tr></thead>
                    <tbody>
                        @foreach($data['history'] as $r)
                            <tr><td>{{ $r['period'] }}</td><td>{{ $r['net_margin_pct'] }}</td><td>{{ $r['revenue_index'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="hint">Semua metrik berasal dari fixture sintetis; revenue index BUKAN pendapatan finansial.</p>
        </section>

        <section class="panel" aria-labelledby="research-provenance-heading">
            <h2 id="research-provenance-heading">Evidence dan provenance</h2>
            <p>Sumber: <code>{{ $data['provenance']['source_id'] }}</code></p>
            <p>Tipe: <strong>{{ $data['provenance']['source_type'] }}</strong> · Rights: {{ $data['provenance']['rights'] }}</p>
            <p>{{ $data['provenance']['lineage'] }}</p>
            <p>Quality: <span class="badge amber">{{ $data['quality']['state'] }}</span></p>
            <h3>Blocking reasons</h3>
            <ul>
                @foreach($data['quality']['blocking_reasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="panel" aria-labelledby="research-session-notes-heading">
        <h2 id="research-session-notes-heading">Reviewer workbench</h2>
        <p class="research-workspace-status">Session notes only · NOT_ADMITTED</p>
        <form method="POST" action="{{ url('/ii/workspace/note') }}">
            @csrf
            <input type="hidden" name="ticker" value="{{ $ticker }}">
            <div class="field">
                <label for="research-workspace-finding">Finding</label>
                <textarea class="research-workspace-textarea" id="research-workspace-finding" name="finding" required maxlength="1000">{{ old('finding') }}</textarea>
            </div>
            <div class="field">
                <label for="research-workspace-review-status">Review label</label>
                <select name="review_status" id="research-workspace-review-status">
                    <option value="OPEN">OPEN</option>
                    <option value="NEEDS_EVIDENCE">NEEDS_EVIDENCE</option>
                    <option value="REVIEWED_SYNTHETIC">REVIEWED_SYNTHETIC</option>
                </select>
            </div>
            <button type="submit">Simpan catatan sesi</button>
        </form>
        @if($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <h3 style="margin-top:24px">Catatan sesi berjalan</h3>
        @forelse($notes as $n)
            <div class="research-workspace-note">
                <strong>{{ $n['review_status'] }}</strong>
                <p>{{ $n['finding'] }}</p>
                <span class="hint">{{ $n['created_at'] }}</span>
            </div>
        @empty
            <p class="muted">Belum ada catatan.</p>
        @endforelse
    </section>
</div>
@endsection
