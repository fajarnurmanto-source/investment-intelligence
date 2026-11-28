@extends('ii.layout')
@section('title','Sumber data pribadi')
@section('surface','ii-light-content')
@push('styles')
<style>.byok-page{max-width:820px;margin:auto;display:grid;gap:18px}.byok-page article{margin:0}.byok-page label{display:block;margin:12px 0 6px}.byok-page button{margin-top:12px}.byok-page .secondary{background:#fff;color:#142a40;border:1px solid #b8c5d0}.byok-page .note{font-size:.86rem}.byok-page .quote{user-select:none;-webkit-user-select:none;background:#f1f5f7;border:1px solid #dce2e9;border-radius:12px;padding:16px;margin-top:16px;overflow-wrap:anywhere}</style>
@endpush
@section('content')
<div class="byok-page">
    <header><p class="eyebrow">Pengaturan</p><h1>Sumber data pribadi</h1></header>
    <p>Gunakan akun penyedia data Anda sendiri. Kunci akses disimpan terenkripsi di perangkat ini; permintaan data tidak melewati server perantara II.</p>
    @if (session('byok_status')) <p role="status">{{ session('byok_status') }}</p> @endif
    @if ($errors->any()) <p role="alert" class="error">{{ $errors->first() }}</p> @endif
    <article class="panel">
      <h2>MarketData.app</h2>
      <p>Status: <strong>{{ $connected ? 'Kunci tersedia di perangkat' : 'Belum terhubung' }}</strong></p>
      <p class="muted note">Kunci tersimpan bukan bukti hak real-time. Quote endpoint yang diaktifkan adalah delayed quotes; tidak ada ekspor, sinkronisasi, atau arsip otomatis.</p>
      <form method="post" action="{{ route('ii.byok.connect') }}" autocomplete="off">
        @csrf
        <input type="hidden" name="provider" value="marketdata">
        <label for="key">API key pribadi</label>
        <input id="key" name="api_key" type="password" required maxlength="512" autocomplete="off" placeholder="Key tidak akan ditampilkan kembali">
        <label><input style="width:auto" type="checkbox" required name="confirm_private_subscription" value="1"> Saya memiliki akun dan hak penggunaan pribadi dari penyedia.</label>
        <button type="submit">Simpan kunci akses</button>
      </form>
      @if ($connected)
        <form method="post" action="{{ route('ii.byok.disconnect') }}">
          @csrf
          <button class="secondary" type="submit">Putuskan sambungan dan hapus kunci</button>
        </form>
      @endif
    </article>
    @if ($connected)
    <article class="panel">
      <h2>Lihat kutipan harga</h2>
      <form method="post" action="{{ route('ii.byok.quote') }}" autocomplete="off">
        @csrf
        <label for="symbol">Kode saham AS</label>
        <input id="symbol" name="symbol" maxlength="7" pattern="[A-Za-z]{1,5}(\.[A-Za-z])?" placeholder="AAPL" required>
        <button type="submit">Tampilkan harga</button>
      </form>
      @if ($quote)
        <section class="quote" aria-label="Quote in-app">
          <p><strong>{{ $quote['symbol'] }}</strong> — {{ $quote['feed_class'] }}</p>
          <p>Bid: {{ $quote['bid'] }} · Ask: {{ $quote['ask'] }} · Last: {{ $quote['last'] }}</p>
          <p class="note">Waktu sumber: {{ $quote['provider_timestamp'] }} · Data tidak dinyatakan real-time · Belum disahkan sebagai dasar keputusan transaksi.</p>
        </section>
      @endif
    </article>
    @endif
    <p class="muted note">MarketData BYOK melarang ekspor, clipboard, redistribusi dan sinkronisasi data berlisensi. Jangan menyalin quote ke program lain. Pengaturan ini tidak mengubah data riset atau dokumen milik Anda.</p>
</div>
@endsection
