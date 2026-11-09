@extends('ii.layout')

@section('title', $case->title)

@push('styles')
<style>
    .research-detail-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 20px;font-size:.87rem}
    .research-case-id{overflow-wrap:anywhere;font-size:.85rem;color:var(--muted);margin:4px 0 10px}
    .research-detail-panel{margin:18px 0}
    .research-detail-panel h2:not(:first-child){margin-top:23px}
    .research-detail-panel code{overflow-wrap:anywhere}
    .research-detail-table{min-width:440px}
    .research-findings{width:100%;min-height:110px;padding:11px 13px;border:1px solid #405570;border-radius:8px;background:#0b192a;color:var(--text);font:inherit;resize:vertical}
    .research-review-fields{max-width:450px}
    .research-event{border-top:1px solid var(--line);padding:14px 0;overflow-wrap:anywhere}
    .research-event:first-child{border-top:0}
    .research-event-time{color:var(--muted);font-size:.8rem}
    .research-event-finding{white-space:pre-wrap;margin-top:6px}
    @media(max-width:600px){.research-detail-actions{gap:8px}.research-detail-actions .button{width:100%;text-align:center}.research-detail-panel{padding:16px}}
</style>
@endpush

@section('content')
<p class="eyebrow">II / Investment Research / Case Detail</p>
<nav class="research-detail-actions" aria-label="Aksi kasus riset">
    <a href="{{ route('ii.dashboard') }}">Dashboard</a>
    <a href="{{ route('ii.signals.index') }}">Trading Signal Center</a>
    <a href="{{ route('ii.cases.index') }}">← Semua kasus</a>
    <a class="button quiet" href="{{ route('ii.cases.report', ['id' => $case->id]) }}">Unduh laporan Markdown</a>
</nav>

<h1>{{ $case->title }}</h1>
<p class="research-case-id">ID kasus: <code>{{ $case->id }}</code></p>

<div class="boundary" role="note">
    <strong>DEMO SINTETIS</strong> · NOT_ADMITTED · HOLD · Authority NONE · Trading FALSE.
    Status <span class="badge blue">{{ $case->review_status }}</span> tidak berarti bukti sudah diterima.
</div>

<section class="panel research-detail-panel" aria-labelledby="research-company-detail">
    <h2 id="research-company-detail">{{ $case->ticker }} — {{ $case->company_name }}</h2>
    <p class="muted">Sumber: <code>{{ $data['provenance']['source_id'] }}</code> · {{ $data['provenance']['source_type'] }}</p>

    <h2>Metrik historis sintetis</h2>
    <div class="tablewrap" role="region" aria-label="Metrik historis sintetis" tabindex="0">
        <table class="research-detail-table">
            <thead><tr><th scope="col">Periode</th><th scope="col">Net margin (%)</th><th scope="col">Revenue index</th></tr></thead>
            <tbody>
                @foreach(($data['history'] ?? []) as $row)
                    <tr><td>{{ $row['period'] }}</td><td>{{ $row['net_margin_pct'] }}</td><td>{{ $row['revenue_index'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2>Kekurangan bukti</h2>
    <ul>
        @forelse(($data['quality']['blocking_reasons'] ?? []) as $reason)
            <li>{{ $reason }}</li>
        @empty
            <li>Tidak ada blocker yang dilaporkan mesin.</li>
        @endforelse
    </ul>
</section>

@if(in_array((string) auth()->user()->ii_research_role, ['reviewer', 'admin'], true))
    <section class="panel research-detail-panel" aria-labelledby="research-review-note">
        <h2 id="research-review-note">Catatan reviewer</h2>
        <form method="POST" action="{{ route('ii.cases.note', ['id' => $case->id]) }}">
            @csrf
            <div class="field research-review-fields">
                <label for="research-review-status">Status</label>
                <select id="research-review-status" name="review_status" required>
                    <option value="OPEN" @selected(old('review_status') === 'OPEN')>OPEN</option>
                    <option value="NEEDS_EVIDENCE" @selected(old('review_status') === 'NEEDS_EVIDENCE')>NEEDS_EVIDENCE</option>
                    <option value="REVIEWED_SYNTHETIC" @selected(old('review_status') === 'REVIEWED_SYNTHETIC')>REVIEWED_SYNTHETIC</option>
                </select>
            </div>
            <div class="field">
                <label for="research-review-finding">Temuan atau kekurangan bukti</label>
                <textarea class="research-findings" id="research-review-finding" name="finding" maxlength="1000" required placeholder="Catatan atau kekurangan bukti">{{ old('finding') }}</textarea>
            </div>
            <button type="submit">Simpan catatan reviewer</button>
        </form>
        @if($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
    </section>
@else
    <div class="boundary" role="note">Akun ANALYST dapat melihat kasus dan laporan. Penyimpanan catatan reviewer hanya tersedia untuk REVIEWER dan ADMIN.</div>
@endif

<section class="panel research-detail-panel" aria-labelledby="research-audit-history">
    <h2 id="research-audit-history">Riwayat tindakan</h2>
    @forelse($events as $event)
        <div class="research-event">
            <strong>{{ $event->kind }} · {{ $event->review_status }}</strong>
            <span class="research-event-time">— {{ $event->created_at }} oleh {{ $event->actor_email ?? 'pengguna lama/tidak diketahui' }}</span>
            <div class="research-event-finding">{{ $event->finding }}</div>
        </div>
    @empty
        <p class="muted">Belum ada tindakan yang tercatat.</p>
    @endforelse
</section>
@endsection
