@extends('ii.layout')
@section('title','Market Data Workspace')
@section('content')
<p class="eyebrow">II / Market Data Operations</p>
<h1>Market Data Workspace</h1>
<p class="lead">Pantau cakupan observasi, kesegaran data, dan hasil akuisisi lokal.</p>
<div class="boundary">Status konfigurasi: {{ $overview['status'] }}. Data tetap USER_IMPORTED_UNVERIFIED; tidak ada otorisasi trading.</div>
<section class="panel">
<h2>Cakupan instrumen</h2>
<p class="muted">Usia menggunakan hari kalender, bukan kalender perdagangan. Jeda lebih dari tujuh hari adalah indikasi untuk diperiksa, bukan bukti sesi hilang.</p>
@forelse($overview['instruments'] as $item)
<article style="border-top:1px solid #94a3b8;padding:12px 0">
<strong>{{ $item['ticker'] }}</strong> · {{ $item['provider'] }} · {{ $item['status'] }}
<p>{{ $item['sessions'] }} / {{ $item['target'] }} observasi target · {{ $item['first'] ?? '—' }} sampai {{ $item['last'] ?? '—' }}</p>
<p>Usia: {{ $item['age_days'] ?? '—' }} hari · Jeda panjang: {{ $item['long_calendar_gaps'] }}</p>
</article>
@empty
<p>Belum ada instrumen yang dapat dipantau untuk akun ini.</p>
@endforelse
</section>
<section class="panel">
<h2>50 eksekusi terakhir</h2>
@forelse($overview['receipts'] as $receipt)
<article style="border-top:1px solid #94a3b8;padding:10px 0"><strong>{{ $receipt->ticker }}</strong> · {{ $receipt->status }}<p>{{ $receipt->started_at }} UTC — {{ $receipt->completed_at ?? 'belum selesai' }}</p></article>
@empty
<p>Belum ada receipt.</p>
@endforelse
</section>
<p class="muted">Konfigurasi universe dan lisensi masih dikelola operator lokal. Halaman ini tidak mengubah kredensial atau menjalankan akuisisi.</p>
@endsection
