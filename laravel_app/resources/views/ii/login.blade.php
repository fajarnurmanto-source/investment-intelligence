<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · Investment Intelligence</title>
    <style>
        * { box-sizing:border-box }
        body { min-height:100vh; display:grid; place-items:center; margin:0; padding:24px; background:linear-gradient(150deg,#e8eefb,#f6f8fc 58%); color:#17243a; font:15px/1.5 system-ui,sans-serif }
        main { width:min(100%,460px); background:#fff; border:1px solid #d7e0ee; border-radius:18px; padding:32px; box-shadow:0 18px 50px #14253d14 }
        h1 { font-size:1.7rem; margin:8px 0 } .muted { color:#617187 }
        label { display:block; font-weight:650; margin:16px 0 }
        input { margin-top:6px; width:100%; font:inherit; padding:12px; border:1px solid #b7c4d8; border-radius:9px }
        button { width:100%; font:inherit; padding:12px; background:#174ea6; color:#fff; font-weight:750; border:0; border-radius:9px; cursor:pointer }
        .alert { background:#fff0ee; color:#9a2626; border-radius:9px; padding:10px 12px }
        .notice { background:#fff8e9; border-left:4px solid #bc8520; padding:11px 13px; margin:20px 0; border-radius:6px }
    </style>
</head>
<body>
<main>
    <div class="muted">Investment Intelligence · VS6</div>
    <h1>Masuk ke ruang riset</h1>
    <p class="muted">Akun lokal dengan peran ANALYST, REVIEWER, atau ADMIN.</p>
    <div class="notice"><strong>Demo sintetis saja.</strong> NOT_ADMITTED · HOLD · Authority NONE.
        Akses akun tidak memberi izin transaksi.</div>
    <form method="POST" action="{{ route('ii.login.submit') }}">
        @csrf
        <label>Email
            <input type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="username" required autofocus>
        </label>
        <label>Kata sandi
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Masuk</button>
    </form>
    @if($errors->any())<p class="alert" role="alert">{{ $errors->first() }}</p>@endif
</main>
</body>
</html>
