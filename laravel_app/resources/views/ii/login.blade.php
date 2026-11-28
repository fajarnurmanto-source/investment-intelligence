<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="theme-color" content="#f4f6f9">
<link rel="icon" type="image/svg+xml" href="{{ asset('ii/brand.svg') }}">
<link rel="stylesheet" href="{{ asset('ii/institutional.css') }}">
<title>Masuk · Investment Intelligence</title>
<style>
*{box-sizing:border-box}
body{min-height:100vh;min-height:100dvh;display:grid;place-items:center;margin:0;padding:28px;background:radial-gradient(ellipse at 85% 8%,#cde6e1,transparent 50%),radial-gradient(ellipse at 5% 90%,#dce5ef,transparent 55%),var(--ii-canvas);color:var(--ii-ink);font:15px/1.55 Inter,system-ui,-apple-system,Segoe UI,sans-serif;color-scheme:light}
.login-island{width:min(100%,1040px);display:grid;grid-template-columns:1.08fr 1fr;gap:12px;padding:12px;border:1px solid #fff;border-radius:30px;background:#fff;box-shadow:0 28px 80px #142a4014}
@supports(backdrop-filter:blur(18px)){.login-island{background:rgba(255,255,255,.72);backdrop-filter:blur(18px)}}
.brand-panel{position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;gap:56px;padding:38px;border-radius:22px;background:radial-gradient(ellipse at 95% 12%,#357d7a,transparent 62%),linear-gradient(135deg,#142a40,#204956);color:#fff;min-height:520px}
.brand-panel:after{content:"";position:absolute;right:-160px;bottom:-180px;width:440px;height:440px;border-radius:50%;border:1px solid #ffffff29;box-shadow:0 0 0 32px #ffffff09,0 0 0 64px #ffffff08;pointer-events:none}
.brand{display:flex;align-items:center;gap:14px;position:relative;z-index:1}.brand img{width:48px;height:48px;flex:none}.brand strong{display:block;font-size:1.04rem;letter-spacing:-.02em}.brand small{display:block;color:#d0e2e6;font-size:.78rem}
.brand-copy{position:relative;z-index:1}.brand-copy h2{font-size:clamp(1.9rem,3.8vw,3rem);line-height:1.13;letter-spacing:-.045em;margin:0 0 18px;font-weight:650}.brand-copy p{color:#dcecec;max-width:320px}.brand-footer{color:#e5c58c;font-size:.77rem;letter-spacing:.08em;position:relative;z-index:1}
.login-form{padding:clamp(20px,4vw,48px);display:flex;flex-direction:column;justify-content:center;min-width:0}
.login-form h1{font-size:1.95rem;line-height:1.2;letter-spacing:-.035em;margin:0 0 10px;color:var(--ii-navy)}.muted{color:var(--ii-muted);margin:0 0 26px}
label{display:block;font-weight:600;margin:0 0 18px;color:#34475a}input{display:block;margin-top:7px;width:100%;min-height:48px;font:inherit;padding:12px;border:1px solid #b8c5d0;border-radius:11px;background:#fff;color:var(--ii-ink)}
button{width:100%;min-height:48px;font:inherit;padding:12px;background:#147d78;color:#fff;font-weight:650;border:0;border-radius:11px;cursor:pointer;transition:background .16s,box-shadow .16s}button:hover{background:#10635e;box-shadow:0 5px 18px #147d7829}button:focus-visible,input:focus-visible{outline:3px solid #147d78;outline-offset:3px}
.alert{background:#fff0ef;color:#8e2833;border:1px solid #e8b9ba;border-radius:11px;padding:12px;margin:0 0 18px;overflow-wrap:anywhere}
.privacy{margin:24px 0 0;padding-top:18px;border-top:1px solid var(--ii-border);color:var(--ii-muted);font-size:.83rem}
@media(max-width:760px){body{padding:12px}.login-island{grid-template-columns:1fr;border-radius:22px;padding:8px;gap:4px}.brand-panel{min-height:0;padding:22px;gap:18px;border-radius:16px}.brand-copy h2{font-size:1.55rem;margin:0 0 8px}.brand-copy p{margin:0}.brand-footer{display:none}.login-form{padding:24px 18px}.login-form h1{font-size:1.6rem}.muted{margin-bottom:20px}}
@media(prefers-reduced-motion:reduce){button{transition:none}}
</style>
</head>
<body>
<main class="login-island">
<section class="brand-panel" aria-label="Investment Intelligence">
<div class="brand"><img src="{{ asset('ii/brand.svg') }}" alt="" width="48" height="48"><div><strong>Investment Intelligence</strong><small>Personal research workspace</small></div></div>
<div class="brand-copy"><h2>Riset lebih dalam.<br>Keputusan lebih terukur.</h2><p>Kenali pasar, pelajari perusahaan, dan pahami risiko dalam satu ruang penelitian.</p></div>
<div class="brand-footer">INTELLIGENT. ELEGANT. INTUITIVE.</div>
</section>
<section class="login-form" aria-labelledby="login-title">
<h1 id="login-title">Selamat datang</h1>
<p class="muted">Masuk ke akun Anda untuk melanjutkan penelitian.</p>
@if($errors->any())<div class="alert" role="alert">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('ii.login.submit') }}">
@csrf
<label for="ii-email">Email<input id="ii-email" type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="username" required></label>
<label for="ii-password">Kata sandi<input id="ii-password" type="password" name="password" autocomplete="current-password" required></label>
<button type="submit">Masuk</button>
</form>
<p class="privacy">Data penelitian hanya tersedia bagi akun yang berwenang. II digunakan untuk penelitian dan simulasi, bukan untuk melakukan transaksi.</p>
</section>
</main>
</body>
</html>
