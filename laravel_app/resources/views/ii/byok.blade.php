<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>II — Sumber Data Pribadi</title>
    <style>
        body{font:16px/1.5 system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1.2rem;color:#17212b}
        article{border:1px solid #cbd5e1;border-radius:12px;padding:1.2rem;margin:1rem 0}
        label{display:block;font-weight:600;margin:.6rem 0 .2rem}
        input{box-sizing:border-box;width:100%;padding:.65rem;border:1px solid #94a3b8;border-radius:6px}
        button{background:#183153;color:white;border:0;padding:.7rem 1rem;border-radius:7px;margin-top:.8rem;cursor:pointer}
        .secondary{background:#475569}.muted{color:#475569}.quote{user-select:none;-webkit-user-select:none}
        .error{color:#9f1239}.note{font-size:.9rem}
    </style>
</head>
<body>
    <header><a href="{{ route('ii.dashboard') }}">← Dashboard II</a><h1>Sumber Data Pribadi</h1></header>
    <p>BYOK lokal: Anda berlangganan sendiri. Kunci tersimpan terenkripsi pada perangkat ini, dan data hanya diambil dari perangkat ini. Tidak ada server relay II.</p>
    @if (session('byok_status')) <p role="status">{{ session('byok_status') }}</p> @endif
    @if ($errors->any()) <p role="alert" class="error">{{ $errors->first() }}</p> @endif
    <article>
      <h2>MarketData.app</h2>
      <p>Status: <strong>{{ $connected ? 'Kunci tersedia di perangkat' : 'Belum terhubung' }}</strong></p>
      <p class="muted note">Kunci tersimpan bukan bukti hak real-time. Quote endpoint yang diaktifkan adalah delayed quotes; tidak ada ekspor, sinkronisasi, atau arsip otomatis.</p>
      <form method="post" action="{{ route('ii.byok.connect') }}" autocomplete="off">
        @csrf
        <input type="hidden" name="provider" value="marketdata">
        <label for="key">API key pribadi</label>
        <input id="key" name="api_key" type="password" required maxlength="512" autocomplete="off" placeholder="Key tidak akan ditampilkan kembali">
        <label><input style="width:auto" type="checkbox" required name="confirm_private_subscription" value="1"> Saya memiliki akun dan hak penggunaan pribadi dari penyedia.</label>
        <button type="submit">Simpan key secara lokal</button>
      </form>
      @if ($connected)
        <form method="post" action="{{ route('ii.byok.disconnect') }}">
          @csrf
          <button class="secondary" type="submit">Putuskan sambungan dan hapus key</button>
        </form>
      @endif
    </article>
    @if ($connected)
    <article>
      <h2>Lihat quote di aplikasi</h2>
      <form method="post" action="{{ route('ii.byok.quote') }}" autocomplete="off">
        @csrf
        <label for="symbol">Kode saham AS</label>
        <input id="symbol" name="symbol" maxlength="7" pattern="[A-Za-z]{1,5}(\.[A-Za-z])?" placeholder="AAPL" required>
        <button type="submit">Tampilkan quote</button>
      </form>
      @if ($quote)
        <section class="quote" aria-label="Quote in-app">
          <p><strong>{{ $quote['symbol'] }}</strong> — {{ $quote['feed_class'] }}</p>
          <p>Bid: {{ $quote['bid'] }} · Ask: {{ $quote['ask'] }} · Last: {{ $quote['last'] }}</p>
          <p class="note">Waktu sumber: {{ $quote['provider_timestamp'] }} · Data tidak dinyatakan real-time · NOT_ADMITTED untuk keputusan trading.</p>
        </section>
      @endif
    </article>
    @endif
    <p class="muted note">MarketData BYOK melarang ekspor, clipboard, redistribusi dan sinkronisasi data berlisensi. Jangan menyalin quote ke program lain. Pengaturan ini tidak mengubah data riset atau dokumen milik Anda.</p>
</body>
</html>
