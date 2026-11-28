<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#f4f6f9">
    <link rel="icon" type="image/svg+xml" href="{{ asset('ii/brand.svg') }}">
    <title>@yield('title','Investment Intelligence') · II</title>
    <style>
        :root{color-scheme:dark;--bg:#070f1c;--panel:#111e30;--surface:#15253b;--text:#edf5ff;--muted:#91a9c5;--line:#26394f;--cyan:#61d5e8;--green:#97e4bf;--amber:#fac77a;--red:#fca5a5}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 Inter,system-ui,-apple-system,Segoe UI,sans-serif}
        :focus-visible{outline:3px solid var(--cyan);outline-offset:3px}a,button,input,select{scroll-margin-top:18px}
        .skip-link{position:absolute;top:-80px;left:16px;z-index:1000;background:var(--cyan);color:#082132;padding:10px 16px;border-radius:0 0 8px 8px;font-weight:800}.skip-link:focus{top:0}
        a{color:var(--cyan)}h1{font-size:clamp(1.9rem,3.4vw,2.8rem);letter-spacing:-.035em;line-height:1.16;margin:0 0 12px}h2{font-size:1.2rem;margin:0 0 14px}h3{margin:0 0 8px;font-size:1rem}
        .shell{display:grid;grid-template-columns:245px minmax(0,1fr);min-height:100vh}.sidebar{border-right:1px solid var(--line);padding:28px 16px;position:sticky;top:0;height:100vh;background:#0a1524;overflow:auto}
        .logo{display:flex;gap:12px;align-items:center;margin:4px 8px 30px;text-decoration:none;color:white;font-weight:780;font-size:1rem;line-height:1.2}
        .mark{width:39px;height:39px;border-radius:12px;background:linear-gradient(140deg,#2e7692,#73d3da);color:#061826;display:grid;place-items:center;font-weight:900;letter-spacing:-.08em}
        .logo small{display:block;color:var(--muted);font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;margin-top:2px}
        .group{padding:15px 12px 9px;color:#7993b0;text-transform:uppercase;letter-spacing:.12em;font-size:.66rem;font-weight:800}
        .menu a,.menu span{display:flex;align-items:center;gap:11px;margin:3px 0;padding:11px 13px;border-radius:10px;text-decoration:none;color:#c6d8ec;font-weight:580;min-height:42px}
        .menu a:hover,.menu a.active{background:#1b3546;color:#b5f5ff}.menu .glyph{width:23px;text-align:center;font-size:1.1rem}.menu span.disabled{color:#688097;cursor:not-allowed}
        .main{min-width:0}.top{padding:21px 36px;display:flex;align-items:center;justify-content:space-between;gap:15px;border-bottom:1px solid var(--line);background:#0a1524}
        .top .subtle{color:var(--muted);font-size:.78rem}.idbox{display:flex;align-items:center;gap:12px;flex-wrap:wrap;color:#cfdded;font-size:.81rem;overflow-wrap:anywhere}
        .idbox strong{font-size:.71rem;color:var(--green);letter-spacing:.08em}.idbox form{margin:0}button,.button{cursor:pointer;display:inline-block;border:0;border-radius:9px;padding:11px 16px;background:var(--cyan);color:#082132;font:inherit;font-weight:750;text-decoration:none}
        button:hover,.button:hover{filter:brightness(1.1)}.quiet{border:1px solid #3a536c;background:transparent;color:#d5e4f3;padding:7px 11px}.content{max-width:1360px;margin:auto;padding:35px 36px 90px}
        .eyebrow{color:var(--cyan);font-size:.73rem;font-weight:760;letter-spacing:.15em;text-transform:uppercase;margin:0 0 12px}
        .lead{color:var(--muted);font-size:1rem;margin:0 0 26px;max-width:780px}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}
        .panel{border:1px solid var(--line);background:var(--panel);border-radius:15px;padding:22px;min-width:0}.panel.soft{background:var(--surface)}
        .muted{color:var(--muted)}.badge{display:inline-block;border-radius:999px;background:#173b42;color:#a8f4ef;font-size:.7rem;font-weight:800;letter-spacing:.055em;padding:4px 9px}
        .badge.amber{background:#49321c;color:var(--amber)}.badge.blue{background:#253c57;color:#bdd7ff}.badge.red{background:#522c35;color:#ffbac4}
        .boundary{border:1px solid #795939;background:#2a241e;border-radius:13px;padding:15px 18px;margin:22px 0;color:#f5dcaf;font-size:.84rem}
        .section-header{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:30px 0 14px;flex-wrap:wrap}.section-header h2{margin:0}
        .metric{font-size:2rem;font-weight:800;letter-spacing:-.04em}.caption{font-size:.83rem;color:var(--muted)}
        input,select{width:100%;padding:11px 13px;border:1px solid #405570;background:#0b192a;border-radius:8px;color:var(--text);font:inherit}input[type=file]{padding:8px}
        label{display:block;font-weight:650;font-size:.85rem;color:#cfe0f2;margin:0 0 6px}.field{margin-bottom:15px}.hint{color:var(--muted);font-size:.77rem;margin:6px 0 0}
        .error{border-radius:9px;padding:12px 15px;color:#ffd4d4;background:#402730;border:1px solid #8e4358;margin:15px 0}
        .success{border-radius:9px;padding:12px 15px;color:#cafadd;background:#163d36;border:1px solid #31766a;margin:15px 0}
        .tablewrap{overflow-x:auto;max-width:100%;scrollbar-color:#4d8ca0 #0c192a}table{width:100%;border-collapse:collapse;min-width:620px}th,td{border-bottom:1px solid var(--line);text-align:left;padding:11px 8px}th{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em}
        /* VS11 R2: reclaim space in common 1200-1360px desktop windows while
           retaining the existing mobile navigation and all workspace routes. */
        @media(min-width:981px) and (max-width:1360px){.shell{grid-template-columns:215px minmax(0,1fr)}.sidebar{padding:20px 11px}.logo{gap:9px;font-size:.91rem;margin:4px 4px 18px}.logo small{font-size:.61rem}.group{padding:10px 10px 6px}.menu a,.menu span{gap:8px;padding:8px 9px;margin:1px 0;min-height:36px;font-size:.88rem}.menu .glyph{width:20px}.top{padding:17px 28px}.content{padding:28px 28px 75px}}
        @media(max-width:1120px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:980px){.shell{display:block}.sidebar{height:auto;position:static;padding:13px 16px;border-right:0;border-bottom:1px solid var(--line)}.logo{margin:0 0 11px}.group,.menu .disabled{display:none}.menu{display:flex;overflow-x:auto;gap:5px;scrollbar-color:#4d8ca0 #0a1524}.menu a{white-space:nowrap;font-size:.8rem;padding:9px}.top{padding:15px 20px}.content{padding:26px 20px 70px}.grid{grid-template-columns:1fr}}
        @media(max-width:540px){.top{flex-wrap:wrap;align-items:flex-start}.top .idbox{width:100%;justify-content:space-between}.content{padding:24px 14px 60px}.panel{padding:17px}.boundary{padding:12px 14px}.menu{margin:0 -6px}.logo{font-size:.93rem}}
    </style>
    <link rel="stylesheet" href="{{ asset('ii/institutional.css') }}">
    @stack('styles')
</head>
<body class="ii-institutional @yield('surface', 'ii-legacy-content')">
<a class="skip-link" href="#ii-main">Lewati navigasi ke konten utama</a>
<div class="shell">
    <aside class="sidebar">
        <a class="logo" href="{{ route('ii.dashboard') }}"><img src="{{ asset('ii/brand.svg') }}" width="42" height="42" alt=""><span>Investment Intelligence<small>Ruang riset investasi</small></span></a>
        <nav class="menu ii-primary-nav" aria-label="Navigasi utama">
<a class="ii-nav-heading {{ request()->routeIs('ii.dashboard') ? 'active' : '' }}" href="{{ route('ii.dashboard') }}" @if(request()->routeIs('ii.dashboard')) aria-current="page" @endif><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></svg><span>Dashboard</span></a>
<details class="ii-nav-group" @if(request()->routeIs('ii.market-intelligence.*', 'ii.signals.*', 'ii.macro.*')) open @endif><summary @class(['ii-nav-heading','active'=>request()->routeIs('ii.market-intelligence.*', 'ii.signals.*', 'ii.macro.*')])><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3v18h18M7 15l4-5 4 3 6-8"/></svg><span>Pasar</span><svg class="ii-nav-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></summary><div class="ii-subnav"><a href="{{ route('ii.market-intelligence.index') }}" @class(['active'=>request()->routeIs('ii.market-intelligence.*')]) @if(request()->routeIs('ii.market-intelligence.*')) aria-current="page" @endif>Analisis pasar</a>
<a href="{{ route('ii.signals.index') }}" @class(['active'=>request()->routeIs('ii.signals.*')]) @if(request()->routeIs('ii.signals.*')) aria-current="page" @endif>Sinyal pasar</a>
<a href="{{ route('ii.macro.index') }}" @class(['active'=>request()->routeIs('ii.macro.*')]) @if(request()->routeIs('ii.macro.*')) aria-current="page" @endif>Ekonomi makro</a></div></details>
<a class="ii-nav-heading {{ request()->routeIs('ii.screener.*') ? 'active' : '' }}" href="{{ route('ii.screener.index') }}" @if(request()->routeIs('ii.screener.*')) aria-current="page" @endif><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 15l6 6M10 4a6 6 0 1 0 0 12a6 6 0 1 0 0-12"/></svg><span>Cari saham</span></a>
<details class="ii-nav-group" @if(request()->routeIs('ii.company', 'ii.fundamentals.*')) open @endif><summary @class(['ii-nav-heading','active'=>request()->routeIs('ii.company', 'ii.fundamentals.*')])><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 21V7l8-4v18M12 10h8v11M2 21h20M7 10v2M7 15v2M16 14v2"/></svg><span>Perusahaan</span><svg class="ii-nav-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></summary><div class="ii-subnav"><a href="{{ route('ii.company') }}" @class(['active'=>request()->routeIs('ii.company')]) @if(request()->routeIs('ii.company')) aria-current="page" @endif>Analisis perusahaan</a>
<a href="{{ route('ii.fundamentals.index') }}" @class(['active'=>request()->routeIs('ii.fundamentals.*')]) @if(request()->routeIs('ii.fundamentals.*')) aria-current="page" @endif>Laporan keuangan</a></div></details>
<details class="ii-nav-group" @if(request()->routeIs('ii.portfolio.*', 'ii.strategy.*', 'ii.risk-budget.*')) open @endif><summary @class(['ii-nav-heading','active'=>request()->routeIs('ii.portfolio.*', 'ii.strategy.*', 'ii.risk-budget.*')])><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 5h18v15H3zM16 11h5v5h-5a2.5 2.5 0 0 1 0-5z"/></svg><span>Portofolio</span><svg class="ii-nav-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></summary><div class="ii-subnav"><a href="{{ route('ii.portfolio.index') }}" @class(['active'=>request()->routeIs('ii.portfolio.*')]) @if(request()->routeIs('ii.portfolio.*')) aria-current="page" @endif>Simulasi portofolio</a>
<a href="{{ route('ii.strategy.index') }}" @class(['active'=>request()->routeIs('ii.strategy.index')]) @if(request()->routeIs('ii.strategy.index')) aria-current="page" @endif>Backtesting</a>
<a href="{{ route('ii.strategy.validate') }}" @class(['active'=>request()->routeIs('ii.strategy.validate')]) @if(request()->routeIs('ii.strategy.validate')) aria-current="page" @endif>Evaluasi strategi</a>
<a href="{{ route('ii.strategy.rolling') }}" @class(['active'=>request()->routeIs('ii.strategy.rolling')]) @if(request()->routeIs('ii.strategy.rolling')) aria-current="page" @endif>Uji lintas periode</a>
<a href="{{ route('ii.risk-budget.index') }}" @class(['active'=>request()->routeIs('ii.risk-budget.*')]) @if(request()->routeIs('ii.risk-budget.*')) aria-current="page" @endif>Batas risiko</a></div></details>
<details class="ii-nav-group" @if(request()->routeIs('ii.cases.*', 'ii.workspace')) open @endif><summary @class(['ii-nav-heading','active'=>request()->routeIs('ii.cases.*', 'ii.workspace')])><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 3h14v18H5zM8 8h8M8 12h8M8 16h5"/></svg><span>Riset saya</span><svg class="ii-nav-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></summary><div class="ii-subnav"><a href="{{ route('ii.cases.index') }}" @class(['active'=>request()->routeIs('ii.cases.*')]) @if(request()->routeIs('ii.cases.*')) aria-current="page" @endif>Catatan riset</a>
<a href="{{ route('ii.workspace') }}" @class(['active'=>request()->routeIs('ii.workspace')]) @if(request()->routeIs('ii.workspace')) aria-current="page" @endif>Tinjau riset</a></div></details>
<details class="ii-nav-group" @if(request()->routeIs('ii.market-data.*', 'ii.byok.*')) open @endif><summary @class(['ii-nav-heading','active'=>request()->routeIs('ii.market-data.*', 'ii.byok.*')])><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16M8 3v6M16 9v6M10 15v6"/></svg><span>Pengaturan</span><svg class="ii-nav-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></summary><div class="ii-subnav"><a href="{{ route('ii.market-data.index') }}" @class(['active'=>request()->routeIs('ii.market-data.*')]) @if(request()->routeIs('ii.market-data.*')) aria-current="page" @endif>Kelola data pasar</a>
<a href="{{ route('ii.byok.index') }}" @class(['active'=>request()->routeIs('ii.byok.*')]) @if(request()->routeIs('ii.byok.*')) aria-current="page" @endif>Sumber data</a></div></details>
</nav>
    </aside>
    <div class="main">
        <header class="top">
            <div><div style="font-weight:730">Ruang kerja investasi</div><div class="subtle">Riset, analisis, dan simulasi</div></div>
            <div class="idbox"><span>{{ auth()->user()->email }}<br><strong>{{ ['analyst'=>'Analis','reviewer'=>'Peninjau','admin'=>'Administrator'][auth()->user()->ii_research_role] ?? 'Pengguna' }}</strong></span>
                <form method="POST" action="{{ route('ii.logout') }}">@csrf<button type="submit" class="quiet">Keluar</button></form>
            </div>
        </header>
        <main class="content" id="ii-main" tabindex="-1">@yield('content')</main>
    </div>
</div>
</body>
</html>
