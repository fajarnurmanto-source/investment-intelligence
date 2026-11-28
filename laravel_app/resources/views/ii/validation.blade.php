@extends('ii.layout')
@section('title','Evaluasi strategi')
@section('surface','ii-light-content')
@push('styles')
<style>
    .v-columns{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(275px,.85fr);gap:18px;align-items:start}
    .v-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
    .v-metric{background:#0e1c30;border:1px solid #213851;padding:16px;border-radius:11px;min-width:0}
    .v-metric span{color:var(--muted);font-size:.8rem;display:block}
    .v-metric strong{display:block;font-size:1.48rem;line-height:1.3;margin-top:6px}
    .v-chart{display:block;width:100%;height:auto;background:#0b192b;border-radius:11px;margin:16px 0}
    .v-label{font-size:.77rem;letter-spacing:.08em;color:var(--muted);text-transform:uppercase}
    .v-legend{display:flex;gap:20px;align-items:center;flex-wrap:wrap;color:var(--muted);font-size:.82rem}
    .v-key{display:inline-block;width:17px;height:3px;vertical-align:middle;margin-right:6px}
    .v-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .v-warning{background:#3d2d17;border:1px solid #936d35;color:#f1d8a9;border-radius:10px;padding:13px 15px;margin-bottom:15px;font-size:.9rem}
    .v-evidence{border-left:3px solid var(--cyan);padding-left:14px;color:#b7d1e5;margin:16px 0;font-size:.86rem}
    @media(max-width:1120px){.v-columns{display:block}.v-fields{grid-template-columns:1fr 1fr}.v-side{margin:0 0 20px}.v-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:560px){.v-metrics{grid-template-columns:1fr}}
.v-metric{background:#f1f5f7;border-color:#dce2e9}.v-chart{background:#fff;border:1px solid #dce2e9}.v-chart line{stroke:#dce2e9}.v-chart polyline[stroke='#61d5e8']{stroke:#147d78}.v-chart polyline[stroke='#f5b96f']{stroke:#94651a}.v-key[style*='#61d5e8']{background:#147d78!important}.v-key[style*='#f5b96f']{background:#94651a!important}.v-warning{background:#fff6e5;color:#755016;border-color:#e9c991}.v-evidence{color:#526577}@media(max-width:490px){.v-fields{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
    <p class="eyebrow">Portofolio &amp; strategi</p>
    <h1>Evaluasi strategi</h1>
    <p class="lead">Uji strategi dengan pemisahan waktu: pilih preset dari data lebih awal, lalu ukur hasilnya pada bagian historis terakhir yang tidak ikut menentukan pilihan. Perhatikan seberapa besar biaya menggerus kinerja.</p>
    <p><a class="button" href="{{ route('ii.strategy.rolling', ['ticker' => $ticker, 'fee_bps' => $fee, 'slippage_bps' => $slippage]) }}">Bandingkan beberapa periode dengan Rolling Validation →</a></p>
    <div class="boundary"><strong>Pengujian historis.</strong> Ini bukan walk-forward multi-fold, bukan data live, dan bukan instruksi transaksi. Kinerja pada satu periode historis tidak menjamin keuntungan mendatang.</div>

    <div class="v-columns">
        <div>
            @if($warning)<div class="error" role="alert">{{ $warning }}</div>@endif
            @if($result)
                @if($result['weak_sample'])
                    <div class="v-warning"><strong>PERINGATAN: SAMPEL TERBATAS.</strong> Jendela pelatihan efektif {{ $result['train_sessions'] }} sesi dan pengujian {{ $result['test_sessions'] }} sesi. Hasil ini hanya demonstrasi metode; tambahan sejarah harga diperlukan sebelum menilai kestabilan strategi.</div>
                @endif
                <section class="panel">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <div><span class="badge {{ $result['kind']==='synthetic' ? 'amber' : 'blue' }}">{{ $result['kind']==='synthetic' ? 'Data simulasi' : 'Data impor, belum terverifikasi' }}</span>
                            <h2 style="font-size:1.75rem;margin:12px 0 4px">{{ $result['ticker'] }} · {{ $result['chosen']['name'] }}</h2>
                            <p class="caption">Preset dipilih dari performa training saja · {{ $result['total_sessions'] }} sesi tersedia</p>
                        </div><span class="badge amber">{{ $result['status'] }}</span>
                    </div>
                    <div class="v-metrics">
                        <div class="v-metric"><span>Return periode uji (setelah biaya)</span><strong>{{ $result['chosen']['test']['strategy_return_pct'] }}%</strong></div>
                        <div class="v-metric"><span>Buy-and-hold periode uji</span><strong>{{ $result['chosen']['test']['buy_hold_return_pct'] }}%</strong></div>
                        <div class="v-metric"><span>Selisih terhadap benchmark</span><strong>{{ $result['chosen']['test']['excess_return_pp'] }} pp</strong></div>
                        <div class="v-metric"><span>Maximum Drawdown periode uji</span><strong>{{ $result['chosen']['test']['max_drawdown_pct'] }}%</strong></div>
                        <div class="v-metric"><span>Transaksi simulasi periode uji</span><strong>{{ $result['chosen']['test']['trade_orders'] }}</strong></div>
                        <div class="v-metric"><span>Win rate trade selesai</span><strong>{{ $result['chosen']['test']['win_rate_pct'] === null ? 'N/A' : $result['chosen']['test']['win_rate_pct'].'%' }}</strong></div>
                    </div>
                    <div class="v-legend"><span><i class="v-key" style="background:#61d5e8"></i>Preset terpilih</span><span><i class="v-key" style="background:#f5b96f"></i>Buy-and-hold</span></div>
                    <svg class="v-chart" viewBox="0 0 700 160" role="img" aria-label="Kurva ekuitas out-of-sample strategi yang dipilih dari periode pelatihan, dibanding buy-and-hold">
                        <line x1="10" y1="140" x2="690" y2="140" stroke="#344961" />
                        <line x1="10" y1="80" x2="690" y2="80" stroke="#253d54" />
                        <polyline fill="none" stroke="#f5b96f" stroke-width="2" stroke-linejoin="round" points="{{ $result['chosen']['test']['chart_buy_hold'] }}" />
                        <polyline fill="none" stroke="#61d5e8" stroke-width="2.6" stroke-linejoin="round" points="{{ $result['chosen']['test']['chart_strategy'] }}" />
                    </svg>
                    <p class="caption">Kurva hanya mencakup 30% data terakhir, dari {{ $result['test_start'] }} sampai {{ $result['test_end'] }}. Modal simulasi direset menjadi tunai di awal pengujian; tidak ada carry-over modal atau posisi dari training.</p>
                </section>

                <section class="panel" style="margin-top:17px">
                    <span class="v-label">Pemilihan hanya dari training</span><h2>Bagaimana preset dipilih?</h2>
                    <div class="tablewrap"><table><thead><tr><th>Preset</th><th>Train return</th><th>Train excess</th><th>Test return</th><th>Test excess</th></tr></thead><tbody>
                        @foreach($result['candidates'] as $candidate)
                        <tr><td><strong>{{ $candidate['name'] }}</strong> @if($candidate['name']===$result['chosen']['name'])<span class="badge blue">TERPILIH</span>@endif</td>
                            <td>{{ $candidate['train']['strategy_return_pct'] }}%</td><td>{{ $candidate['train']['excess_return_pp'] }} pp</td>
                            <td>{{ $candidate['test']['strategy_return_pct'] }}%</td><td>{{ $candidate['test']['excess_return_pp'] }} pp</td></tr>
                        @endforeach
                    </tbody></table></div>
                    <p class="caption">Kedua preset memulai training pada tanggal yang sama ({{ $result['train_start'] }}) dan berakhir {{ $result['train_end'] }}. Pemilihan didasarkan pada selisih return training terhadap buy-and-hold setelah biaya. Hasil test tidak digunakan dalam pemilihan. Nilai test kedua preset ditampilkan untuk transparansi, bukan untuk mengganti pilihan setelah melihat hasil.</p>
                </section>

                <section class="panel" style="margin-top:17px">
                    <span class="v-label">Pengaruh biaya</span><h2>Uji pengaruh biaya transaksi</h2>
                    <div class="tablewrap"><table><thead><tr><th>Skenario</th><th>Fee</th><th>Slippage</th><th>Return OOS</th><th>Excess OOS</th></tr></thead><tbody>
                        @foreach($result['stress'] as $scenario)
                        <tr><td>{{ $scenario['name'] }}</td><td>{{ $scenario['fee_bps'] }} bps</td><td>{{ $scenario['slippage_bps'] }} bps</td><td>{{ $scenario['strategy_return_pct'] }}%</td><td>{{ $scenario['excess_return_pp'] }} pp</td></tr>
                        @endforeach
                    </tbody></table></div>
                    <p class="caption">Pengujian biaya tidak memilih ulang preset. Biaya dihitung per sisi transaksi dengan slippage pada harga pembukaan. Skenario “tanpa biaya” sengaja optimistis dan tidak mewakili kondisi riil.</p>
                </section>

                <section class="panel" style="margin-top:17px">
                    <h2>Sumber data dan batas evaluasi</h2>
                    <p class="muted">Pemisahan 70%/30% berdasarkan urutan waktu merupakan satu holdout kronologis, bukan validasi berulang atau penilaian stabilitas lintas rezim. Dua preset telah ditetapkan sebelumnya; pemilihan berdasar data training juga dapat overfit. Hasil dari DEMO sintetis tidak menunjukkan peluang riil.</p>
                    <p class="muted">Return adalah nilai hipotetis, tidak mengandung dividen, dampak corporate action, pajak, dampak pasar atas order, keterbatasan likuiditas, serta biaya likuidasi akhir pada posisi terbuka. Bandingkan pula risiko dan kondisi pasar sebelum penelitian lanjutan.</p>
                    <p class="caption">Sumber: {{ $result['source_name'] }} · impor {{ $result['imported_at'] }} · {{ $result['evidence'] }} · {{ $result['decision'] }} · tidak ada akses trading.</p>
                    <p><a class="button" href="{{ route('ii.strategy.index', ['ticker' => $ticker]) }}">Kembali ke Backtesting →</a></p>
                </section>
            @elseif(count($available)===0)
                <section class="panel"><h2>Belum ada data harga</h2><p class="muted">Impor minimal 90 observasi harian ke Signal Center untuk membandingkan pelatihan dan periode uji.</p><a href="{{ route('ii.signals.index') }}">Lihat data harga →</a></section>
            @endif
        </div>

        <aside class="panel v-side"><span class="badge">Pengaturan pengujian</span><h2 style="margin-top:12px">Konfigurasi penelitian</h2>
            <form method="GET" action="{{ route('ii.strategy.validate') }}">
                <div class="field"><label for="ticker">Instrumen</label><select id="ticker" name="ticker" required>
                    @foreach($available as $item)<option value="{{ $item['ticker'] }}" @selected($ticker===$item['ticker'])>{{ $item['ticker'] }} · {{ $item['kind']==='synthetic' ? 'DEMO' : 'IMPORTED' }}</option>@endforeach
                </select></div>
                <div class="v-fields">
                    <div class="field"><label for="fee_bps">Biaya (bps per sisi)</label><input id="fee_bps" name="fee_bps" type="number" min="0" max="100" value="{{ $fee }}" required></div>
                    <div class="field"><label for="slippage_bps">Slippage (bps per sisi)</label><input id="slippage_bps" name="slippage_bps" type="number" min="0" max="100" value="{{ $slippage }}" required></div>
                </div>
                <button type="submit" style="width:100%" @disabled(count($available)===0)>Evaluasi strategi</button>
            </form>
            <div class="v-evidence"><strong>Aturan yang terkunci:</strong> 70% lebih awal sebagai training, 30% terakhir sebagai test. Dua preset SMA10/30 dan SMA20/50 dievaluasi pada rentang training yang sama, dengan harga pembukaan sesi berikutnya sebagai eksekusi simulasi.</div>
            <p class="hint">Minimum 90 sesi untuk fungsi ini. DEMO7 dapat menunjukkan cara kerja, tetapi sampelnya terlalu pendek untuk kesimpulan stabilitas.</p>
            <p class="hint"><a href="{{ route('ii.signals.index') }}">Impor seri harga →</a></p>
        </aside>
    </div>
@endsection
