@extends('ii.layout')

@section('title', 'Research Cases')

@push('styles')
<style>
    .research-links{display:flex;gap:13px;flex-wrap:wrap;margin:0 0 19px;font-size:.87rem}
    .research-fields{display:grid;grid-template-columns:minmax(145px,190px) minmax(0,1fr);gap:14px;margin-bottom:14px}
    .research-fields .field{margin:0}
    .research-status{white-space:nowrap}
    .research-cases-table td:first-child{overflow-wrap:anywhere;max-width:290px}
    .research-cases-table td{vertical-align:top}
    .research-cases-table{min-width:680px}
    .research-panel{margin-top:18px}
    @media(max-width:660px){.research-fields{grid-template-columns:1fr}.research-cases-table{min-width:680px}}
</style>
@endpush

@section('content')
<p class="eyebrow">II / Investment Research / VS11 R3</p>
<h1>Kasus riset perusahaan</h1>
<p class="lead">Simpan kasus riset sintetis, telusuri catatan reviewer, dan unduh laporan penelitian dalam satu workspace.</p>

<nav class="research-links" aria-label="Tautan terkait kasus riset">
    <a href="{{ route('ii.dashboard') }}">Dashboard Produk</a>
    <a href="{{ route('ii.signals.index') }}">Trading Signal Center</a>
    <a href="{{ route('ii.company') }}">Company Research</a>
    <a href="{{ route('ii.workspace') }}">Reviewer Workspace</a>
</nav>

<div class="boundary" role="note">
    <strong>MODE DEMO SINTETIS.</strong> Data hanya DEMO2/DEMO3. NOT_ADMITTED · HOLD · Authority NONE.
    Status reviewer bukan persetujuan bukti, valuasi, ataupun transaksi.
</div>

<section class="panel research-panel" aria-labelledby="research-new-case">
    <h2 id="research-new-case">Buat kasus riset</h2>
    <form method="POST" action="{{ route('ii.cases.store') }}">
        @csrf
        <div class="research-fields">
            <div class="field">
                <label for="research-case-ticker">Perusahaan</label>
                <select id="research-case-ticker" name="ticker" required>
                    <option value="DEMO2" @selected(old('ticker') === 'DEMO2')>DEMO2</option>
                    <option value="DEMO3" @selected(old('ticker') === 'DEMO3')>DEMO3</option>
                </select>
            </div>
            <div class="field">
                <label for="research-case-title">Judul kasus</label>
                <input id="research-case-title" name="title" value="{{ old('title') }}" maxlength="120" required placeholder="Contoh: Tinjauan margin DEMO2">
            </div>
        </div>
        <button type="submit">Simpan kasus</button>
    </form>
    @if($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif
</section>

<section class="panel research-panel" aria-labelledby="research-saved-cases">
    <h2 id="research-saved-cases">Kasus tersimpan</h2>
    <div class="tablewrap" role="region" aria-label="Daftar kasus tersimpan" tabindex="0">
        <table class="research-cases-table">
            <thead>
                <tr><th scope="col">Judul</th><th scope="col">Perusahaan</th><th scope="col">Progres reviewer</th><th scope="col">Dibuat oleh</th><th scope="col">Dibuat</th></tr>
            </thead>
            <tbody>
                @forelse($cases as $case)
                    <tr>
                        <td><a href="{{ route('ii.cases.show', ['id' => $case->id]) }}">{{ $case->title }}</a></td>
                        <td>{{ $case->ticker }}</td>
                        <td><span class="badge blue research-status">{{ $case->review_status }}</span></td>
                        <td>{{ $case->creator_email ?? 'Sebelum VS6' }}</td>
                        <td>{{ $case->created_at }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Belum ada kasus. Buat kasus riset pertama menggunakan formulir di atas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
