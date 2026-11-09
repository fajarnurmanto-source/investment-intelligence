@extends('ii.layout')
@section('title','Portfolio Sandbox')
@push('styles')
<style>
    /* VS11 R4: controls beside summary; detailed analytical panels full width. */
    .portfolio-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(285px,.58fr);gap:18px;align-items:start}
    .portfolio-primary{display:contents}
    .portfolio-primary>.error,.portfolio-primary>.portfolio-warning,.portfolio-primary>.portfolio-summary{grid-column:1}
    .portfolio-primary>.portfolio-warning{margin:0}
    .portfolio-primary>.portfolio-wide{grid-column:1/-1;margin-top:0!important}
    .portfolio-side{grid-column:2;grid-row:1;min-width:0}
    .portfolio-layout.portfolio-has-synthetic .portfolio-side{grid-row:1/span 2}
    .portfolio-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
    .portfolio-metric{background:#0d1d31;border:1px solid #2b4057;border-radius:11px;padding:17px}
    .portfolio-metric span{display:block;font-size:.78rem;color:var(--muted);line-height:1.4}
    .portfolio-metric strong{display:block;font-size:1.5rem;line-height:1.25;margin-top:7px}
    .portfolio-metric.negative strong{color:#ffb7b7}
    .portfolio-warning{background:#3b291a;border:1px solid #86653c;padding:15px 17px;color:#f3dcad;border-radius:10px;margin:0 0 17px}
    .portfolio-form-grid{display:grid;grid-template-columns:minmax(0,1fr) 90px;gap:8px}
    .portfolio-form-grid .field{min-width:0}.portfolio-form-grid label{font-size:.8rem}.portfolio-instrument-select{min-width:0;text-overflow:ellipsis}
    .portfolio-chart{background:linear-gradient(170deg,#0c2032,#0c182a);border:1px solid #30465c;border-radius:12px;padding:14px;margin-top:15px}
    .portfolio-legend{display:flex;justify-content:space-between;gap:15px;flex-wrap:wrap;font-size:.81rem;color:var(--muted)}
    .portfolio-legend b{color:#bbd8ee}.portfolio-legend .cyan{color:#62d8e9}.portfolio-legend .gold{color:#f4c47d}
    .portfolio-chart svg{display:block;width:100%;height:auto;max-height:330px;margin-top:10px}
    .portfolio-note{border-left:3px solid var(--cyan);padding-left:13px;color:#bed6e6;font-size:.84rem;margin:14px 0 0}
    .portfolio-kpi{color:#7cbfd2;font-weight:760;font-size:.78rem;letter-spacing:.07em}
    .assurance-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0}
    .assurance-tile{border:1px solid #37516c;background:#0e2134;padding:14px;border-radius:10px}
    .assurance-tile span{display:block;color:var(--muted);font-size:.77rem}
    .assurance-tile strong{display:block;margin-top:6px;font-size:1.21rem}
    .assurance-assets{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:13px;margin:17px 0}
    .assurance-asset{border:1px solid #36526b;background:#0c1d30;border-radius:12px;padding:17px;min-width:0}
    .assurance-asset-head{display:flex;justify-content:space-between;align-items:start;gap:11px;flex-wrap:wrap;margin-bottom:13px}
    .assurance-asset-head h3{font-size:1.14rem;margin:0}.assurance-asset-head .caption{font-size:.76rem}
    .assurance-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:0}
    .assurance-facts div{border-top:1px solid #2b435c;padding-top:9px;min-width:0}
    .assurance-facts dt{color:var(--muted);font-size:.75rem;margin-bottom:4px}
    .assurance-facts dd{margin:0;font-size:.88rem;font-weight:730;overflow-wrap:anywhere}
    .assurance-facts dd.small{font-size:.76rem;font-weight:650}
    .assurance-list{margin:12px 0 0;padding-left:21px;color:#e6d3af;font-size:.85rem}
    .assurance-list li{margin:9px 0}
    .portfolio-wide .tablewrap table{min-width:0}
    .portfolio-wide .tablewrap th,.portfolio-wide .tablewrap td{padding:11px 9px;overflow-wrap:anywhere}
    @media(max-width:1170px){.portfolio-layout{display:flex;flex-direction:column}.portfolio-primary{display:contents}.portfolio-side{order:0;width:100%}.portfolio-primary>.error,.portfolio-primary>.portfolio-warning,.portfolio-primary>.portfolio-summary{order:1;width:100%}.portfolio-primary>.portfolio-wide{order:2;width:100%;margin-top:0!important}.portfolio-primary>.portfolio-warning{margin-bottom:0}}
    @media(max-width:700px){.portfolio-wide .tablewrap table{min-width:620px}}
    @media(max-width:680px){.portfolio-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:490px){.portfolio-metrics,.assurance-grid{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
    <p class="eyebrow">II / Portfolio Intelligence / VS11 R4 <span class="badge blue">Responsive Analytics</span></p>
    <h1>Portfolio Sandbox</h1>
    <p class="lead">Jelajahi interaksi dua atau tiga instrumen dengan bobot hipotetis. Bandingkan pertumbuhan historis, penurunan nilai, konsentrasi, dan korelasi pada tanggal pengamatan yang sama.</p>
    <div class="boundary"><strong>HISTORICAL RESEARCH ONLY — NOT_ADMITTED · HOLD · AUTHORITY NONE.</strong> Perbandingan ini bukan keputusan alokasi investasi, bukan prediksi, dan tidak mengirim order. Harga, dividen, aksi korporasi, pajak, serta kondisi eksekusi nyata belum diverifikasi.</div>
    <div class="portfolio-layout {{ $result && $result['synthetic_state'] !== 'USER_IMPORTED' ? 'portfolio-has-synthetic' : 'portfolio-plain' }}">
        <div class="portfolio-primary">
            @if($warning)<div class="error" role="alert">{{ $warning }}</div>@endif
            @if($result)
                @if($result['synthetic_state'] !== 'USER_IMPORTED')
                    <div class="portfolio-warning"><strong>DATA DEMO TERLIBAT.</strong> {{ $result['synthetic_state']==='ALL_SYNTHETIC' ? 'Seluruh instrumen merupakan data sintetis, bukan harga pasar.' : 'Sebagian instrumen adalah data sintetis; hasil tidak merepresentasikan portofolio pasar nyata.' }}</div>
                @endif
                <section class="panel portfolio-summary">
                    <div style="display:flex;justify-content:space-between;align-items:start;gap:14px;flex-wrap:wrap">
                        <div><span class="badge">FIXED-SHARE · HISTORICAL</span><h2 style="font-size:1.7rem;margin:12px 0 7px">Skenario {{ count($result['tickers']) }} instrumen</h2>
                            <p class="caption">{{ $result['start_date'] }} hingga {{ $result['end_date'] }} · {{ $result['common_sessions'] }} sesi beririsan · {{ $result['return_observations'] }} perubahan harga</p></div>
                        <span class="badge amber">RESEARCH ONLY</span>
                    </div>
                    <div class="portfolio-metrics">
                        <div class="portfolio-metric {{ $result['portfolio_return_pct'] < 0 ? 'negative' : '' }}"><span>Perubahan nilai portofolio*</span><strong>{{ $result['portfolio_return_pct'] }}%</strong></div>
                        <div class="portfolio-metric"><span>Pembanding bobot sama*</span><strong>{{ $result['equal_weight_return_pct'] }}%</strong></div>
                        <div class="portfolio-metric"><span>Selisih terhadap bobot sama</span><strong>{{ $result['relative_excess_pp'] }} pp</strong></div>
                        <div class="portfolio-metric"><span>Drawdown maksimum model*</span><strong>{{ $result['worst_drawdown_pct'] }}%</strong></div>
                        <div class="portfolio-metric"><span>Volatilitas per sesi beririsan*</span><strong>{{ $result['session_volatility_pct'] }}%</strong></div>
                        <div class="portfolio-metric"><span>Historical VaR 95% per sesi*</span><strong>{{ $result['historical_var_95_pct'] === null ? 'N/A' : $result['historical_var_95_pct'].'%' }}</strong></div>
                        <div class="portfolio-metric"><span>Historical CVaR 95% per sesi*</span><strong>{{ $result['historical_cvar_95_pct'] === null ? 'N/A' : $result['historical_cvar_95_pct'].'%' }}</strong></div>
                        <div class="portfolio-metric"><span>Jumlah aset efektif (konsentrasi bobot)</span><strong>{{ $result['effective_asset_count'] }}</strong></div>
                    </div>
                    <p class="caption">*Portofolio membeli porsi awal menurut bobot dan menahan jumlah unit tetap sampai akhir. VaR/CVaR adalah estimasi historis non-parametrik dari perubahan sesi beririsan, bukan batas kerugian. Model mengabaikan biaya, likuiditas, pajak, dividen, perubahan saham, dan rebalancing.</p>
                </section>

                <section class="panel portfolio-wide" style="margin-top:18px">
                    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:start"><div><span class="portfolio-kpi">DATA QUALITY ASSURANCE · R3</span><h2 style="margin-top:10px">Kesiapan dan kesetaraan data</h2></div><span class="badge amber">{{ $result['quality']['state'] }} · NOT ADMITTED</span></div>
                    <p class="muted">Pemeriksaan sumber hanya membantu menemukan ketidaksesuaian sebelum membandingkan portofolio. Tidak ada klaim bahwa data sudah diverifikasi oleh bursa atau penyedia pasar.</p>
                    <div class="assurance-grid">
                        <div class="assurance-tile"><span>Cakupan tanggal bersama / gabungan</span><strong>{{ $result['quality']['overall_overlap_pct'] }}%</strong><span>{{ $result['quality']['common_dates'] }} dari {{ $result['quality']['union_dates'] }} tanggal unik</span></div>
                        <div class="assurance-tile"><span>Status mata uang</span><strong>{{ $result['quality']['currency_status'] }}</strong><span>Konversi FX tidak tersedia</span></div>
                        <div class="assurance-tile"><span>Status zona waktu sesi</span><strong>{{ $result['quality']['timezone_status'] }}</strong><span>Keselarasan penutupan belum diverifikasi</span></div>
                    </div>
                    <p class="caption">{{ count($result['quality']['warnings']) }} temuan pemeriksaan otomatis. Deklarasi pengguna, jika tersedia, tidak menjadikan data terverifikasi.</p>
                    <div class="assurance-assets" role="list" aria-label="Ringkasan pemeriksaan sumber per instrumen">
                        @foreach($result['quality']['assets'] as $asset)
                            <article class="assurance-asset" role="listitem" aria-label="Pemeriksaan {{ $asset['ticker'] }}">
                                <div class="assurance-asset-head">
                                    <h3>{{ $asset['ticker'] }}</h3>
                                    <span class="caption">{{ $asset['stale'] ? 'PERLU CEK KESEGARAN' : 'Terakhir '.$asset['age_days'].' hari lalu' }}</span>
                                </div>
                                <dl class="assurance-facts">
                                    <div><dt>Tanggal terakhir</dt><dd>{{ $asset['last_date'] }}</dd></div>
                                    <div><dt>Cakupan tanggal bersama</dt><dd>{{ $asset['common_coverage_pct'] }}%</dd></div>
                                    <div><dt>Mata uang</dt><dd>{{ $asset['currency'] }}</dd></div>
                                    <div><dt>Basis harga</dt><dd>{{ $asset['price_adjustment'] }}</dd></div>
                                    <div><dt>Zona waktu sesi</dt><dd class="small">{{ $asset['session_timezone'] }}</dd></div>
                                    <div><dt>Asal metadata</dt><dd class="small">{{ $asset['metadata_provenance'] }}</dd></div>
                                    <div><dt>Lonjakan close &gt;30%</dt><dd>{{ $asset['large_jumps'] }}</dd></div>
                                    <div><dt>Jeda kalender maksimum</dt><dd>{{ $asset['max_calendar_gap_days'] }} hari</dd></div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                    <p class="caption">Cakupan tiap seri adalah proporsi tanggalnya yang ikut dihitung. Lonjakan harga merupakan indikasi untuk diperiksa, bukan konfirmasi stock split atau kesalahan data. Pemeriksaan jeda memakai hari kalender, bukan kalender bursa. Metadata lama tetap UNKNOWN, tanpa migrasi atau pengisian diam-diam.</p>
                    @if(count($result['quality']['warnings']) > 0)
                        <div class="portfolio-warning" style="margin-top:18px"><strong>Perlu peninjauan sumber:</strong><ul class="assurance-list">@foreach($result['quality']['warnings'] as $issue)<li>{{ $issue }}</li>@endforeach</ul></div>
                    @endif
                    <p class="portfolio-note">{{ $result['quality']['disclaimer'] }}</p>
                </section>

                <section class="panel portfolio-wide" style="margin-top:18px">
                    <h2>Jalur nilai relatif</h2>
                    <p class="muted">Keduanya dimulai dari indeks 100 pada tanggal pertama yang sama. Grafik menunjukkan sejarah, bukan estimasi ke depan.</p>
                    <div class="portfolio-chart">
                        <div class="portfolio-legend"><span><b class="cyan">●</b> Bobot pilihan: <b>{{ $result['portfolio_return_pct'] }}%</b></span><span><b class="gold">●</b> Bobot sama: <b>{{ $result['equal_weight_return_pct'] }}%</b></span></div>
                        <svg viewBox="0 0 680 200" role="img" aria-label="Grafik indeks historis bobot pilihan dibanding bobot sama">
                            <path d="M14 18H666 M14 101H666 M14 184H666" fill="none" stroke="#284358" stroke-width="1" stroke-dasharray="5 6"/>
                            <polyline points="{{ $result['chart_equal'] }}" fill="none" stroke="#f3bf75" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="{{ $result['chart_portfolio'] }}" fill="none" stroke="#66dbe7" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="portfolio-legend"><span>{{ $result['start_date'] }}</span><span>{{ $result['end_date'] }}</span></div>
                    </div>
                    <p class="portfolio-note">Hanya tanggal yang ada pada <strong>semua</strong> instrumen digunakan. Bila seri memiliki hari hilang atau interval berbeda, jarak antar titik tidak selalu satu hari perdagangan.</p>
                    @if($result['equal_weights_selected'])
                        <p class="caption">Kedua jalur grafik berimpit karena skenario bobot pilihan menghasilkan nilai yang sama dengan pembanding bobot sama sepanjang periode.</p>
                    @endif
                </section>

                <section class="panel portfolio-wide" style="margin-top:18px">
                    <h2>Komposisi dan perkembangan instrumen</h2>
                    <div class="tablewrap"><table><thead><tr><th>Instrumen</th><th>Bobot</th><th>Close awal</th><th>Close akhir</th><th>Return*</th><th>Sumber</th></tr></thead><tbody>
                        @foreach($result['instruments'] as $instrument)
                            <tr><td><strong>{{ $instrument['ticker'] }}</strong></td><td>{{ $instrument['weight_pct'] }}%</td><td>{{ number_format($instrument['start_close'], 4) }}</td><td>{{ number_format($instrument['end_close'], 4) }}</td><td>{{ $instrument['return_pct'] }}%</td><td>{{ $instrument['kind']==='synthetic' ? 'DEMO' : 'IMPORTED / UNVERIFIED' }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                    <p class="caption">*Hanya perubahan close dari tanggal awal/akhir beririsan, tanpa menyesuaikan total return sebenarnya.</p>
                    <div class="portfolio-note"><strong>Konsentrasi bobot:</strong> HHI {{ number_format($result['hhi_10000'], 0) }}/10.000 · Bobot terbesar {{ $result['max_weight_pct'] }}%. Jumlah aset efektif dihitung sebagai 1/∑(bobot²); ini ukuran konsentrasi, bukan jumlah risiko independen.</div>
                </section>

                <section class="panel portfolio-wide" style="margin-top:18px">
                    <h2>Korelasi perubahan harga</h2>
                    <div class="tablewrap"><table><thead><tr><th>Pasangan instrumen</th><th>Korelasi sampel</th><th>Observasi</th></tr></thead><tbody>
                        @foreach($result['pairwise_correlations'] as $pair)
                            <tr><td>{{ $pair['pair'] }}</td><td><strong>{{ $pair['correlation'] === null ? 'N/A (seri konstan)' : $pair['correlation'] }}</strong></td><td>{{ $pair['observations'] }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                    <p class="portfolio-note">Korelasi mendekati +1 menunjukkan gerak historis searah; mendekati −1 berlawanan; mendekati 0 hubungan linear lemah. Nilainya dapat berubah dan tidak menjamin perlindungan portofolio.</p>
                </section>

                <section class="panel portfolio-wide" style="margin-top:18px"><h2>Provenance dan batas interpretasi</h2>
                    <p class="muted">Model menggunakan close yang sama tanggalnya dari seri masing-masing pengguna. Tidak ada harga real time, estimasi pembayaran dividen, koreksi stock split, konversi mata uang, biaya transaksi, pajak, eksposur bersih, atau optimasi portofolio. Return historis tidak menunjukkan kinerja masa depan.</p>
                    <p class="caption">{{ $result['synthetic_state'] }} · {{ $result['evidence'] }} · {{ $result['decision'] }} · broker disabled.</p>
                    <p><a class="button" href="{{ route('ii.risk-budget.index') }}">Kembali ke Risk Budget →</a></p>
                </section>
            @elseif(count($available) < 2)
                <section class="panel"><h2>Butuh minimal dua instrumen</h2><p class="muted">Portfolio Sandbox perlu paling sedikit dua seri OHLCV yang tersimpan pada akun Anda dan mempunyai tanggal beririsan. Muat DEMO7 dan DEMO9, atau impor seri CSV yang lain.</p><p><a class="button" href="{{ route('ii.strategy.rolling') }}">Buka Rolling Validation →</a></p></section>
            @endif
        </div>
        <aside class="panel portfolio-side">
            <span class="badge">PORTFOLIO SCENARIO · R1</span><h2 style="margin-top:12px">Rancang komposisi</h2>
            <p class="muted" style="font-size:.87rem">Pilih dua instrumen wajib dan satu opsional. Setiap instrumen hanya boleh dipilih sekali, dengan total bobot 100%.</p>
            <form method="GET" action="{{ route('ii.portfolio.index') }}">
                <div class="portfolio-form-grid"><div class="field"><label for="pa">Instrumen A</label><select class="portfolio-instrument-select" id="pa" name="a" aria-describedby="portfolio-series-hint" required>@foreach($available as $instrument)<option value="{{ $instrument['ticker'] }}" @selected($a===$instrument['ticker'])>{{ $instrument['ticker'] }}</option>@endforeach</select></div><div class="field"><label for="pwa">Bobot %</label><input id="pwa" type="number" name="wa" value="{{ $wa }}" min="0" max="100" step="0.1" required></div></div>
                <div class="portfolio-form-grid"><div class="field"><label for="pb">Instrumen B</label><select class="portfolio-instrument-select" id="pb" name="b" aria-describedby="portfolio-series-hint" required>@foreach($available as $instrument)<option value="{{ $instrument['ticker'] }}" @selected($b===$instrument['ticker'])>{{ $instrument['ticker'] }}</option>@endforeach</select></div><div class="field"><label for="pwb">Bobot %</label><input id="pwb" type="number" name="wb" value="{{ $wb }}" min="0" max="100" step="0.1" required></div></div>
                <div class="portfolio-form-grid"><div class="field"><label for="pc">Instrumen C (opsional)</label><select class="portfolio-instrument-select" id="pc" name="c" aria-describedby="portfolio-series-hint"><option value="">— Tidak dipilih —</option>@foreach($available as $instrument)<option value="{{ $instrument['ticker'] }}" @selected($c===$instrument['ticker'])>{{ $instrument['ticker'] }}</option>@endforeach</select></div><div class="field"><label for="pwc">Bobot %</label><input id="pwc" type="number" name="wc" value="{{ $wc }}" min="0" max="100" step="0.1" required></div></div>
                <button type="submit" style="width:100%" @disabled(count($available) < 2)>Hitung skenario portofolio</button>
            </form>
            <p class="hint" id="portfolio-series-hint">Pilihan memakai kode instrumen yang ringkas; status DEMO/IMPORTED dan sumbernya tersedia di daftar seri harga.</p>
            <p class="portfolio-note">Contoh: A 50% dan B 50%, C tidak dipilih dan 0%. Tidak perlu mengimpor ulang DEMO7/DEMO9.</p>
            <p class="hint" style="margin-top:15px"><a href="{{ route('ii.signals.index') }}">Lihat daftar seri harga →</a></p>
        </aside>
    </div>
@endsection
