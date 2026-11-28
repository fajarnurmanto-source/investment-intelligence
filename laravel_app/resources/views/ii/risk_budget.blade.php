@extends('ii.layout')
@section('title','Batas risiko')
@section('surface','ii-light-content')
@push('styles')
<style>
    .risk-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(290px,.83fr);gap:18px;align-items:start}
    .risk-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
    .risk-metric{background:#0e1c30;border:1px solid #253a53;border-radius:11px;padding:16px}
    .risk-metric span{display:block;color:var(--muted);font-size:.8rem;line-height:1.4}
    .risk-metric strong{display:block;margin-top:6px;font-size:1.46rem;line-height:1.25}
    .risk-bridge{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}
    .risk-node{background:#132b3e;border-radius:9px;padding:15px;text-align:center}
    .risk-node span{display:block;font-size:.77rem;color:var(--muted)}
    .risk-node strong{display:block;font-size:1.23rem;margin-top:5px}
    .risk-note{border-left:3px solid var(--cyan);padding:2px 0 2px 13px;color:#c2d7e9;margin-top:16px;font-size:.86rem}
    .risk-warning{background:#3c2b18;border:1px solid #8d6b37;color:#f6dcb1;border-radius:9px;padding:14px;margin-bottom:15px}
    .risk-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}
    @media(max-width:1120px){.risk-layout{display:block}.risk-side{margin-top:18px}}
    @media(max-width:710px){.risk-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:510px){.risk-metrics,.risk-bridge{grid-template-columns:1fr}.risk-form-grid{grid-template-columns:1fr}}
.risk-metric,.risk-node{background:#f1f5f7;border-color:#dce2e9;color:#172535}.risk-note{color:#526577}.risk-warning{background:#fff6e5;color:#755016;border-color:#e9c991}
</style>
@endpush
@section('content')
    <p class="eyebrow">Portofolio &amp; strategi</p>
    <h1>Batas risiko</h1>
    <p class="lead">Hitung batas ukuran posisi hipotetis dari modal, alokasi risiko, batas eksposur, dan volatilitas ATR14. Semua angka menggunakan sumber harga milik akun Anda—tanpa koneksi order broker.</p>
    <div class="boundary"><strong>Simulasi risiko.</strong> Angka posisi dan target bukan rekomendasi transaksi. Stop dapat terlewati akibat gap, volatilitas, likuiditas, atau eksekusi nyata; kerugian dapat melebihi anggaran simulasi.</div>
    <div class="risk-layout">
        <div>
            @if($warning)<div class="error" role="alert">{{ $warning }}</div>@endif
            @if($result)
                @if($result['kind']==='synthetic' || ($signalContext && $signalContext['stale']))
                    <div class="risk-warning"><strong>JANGAN GUNAKAN SEBAGAI HARGA EKSEKUSI.</strong> @if($result['kind']==='synthetic') Ini adalah data sintetis DEMO, bukan harga pasar. @endif @if($signalContext && $signalContext['stale']) Harga bertanggal lama atau tidak memenuhi batas kesegaran untuk sinyal terkini. @endif</div>
                @endif
                <section class="panel">
                    <div style="display:flex;align-items:start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <div><span class="badge {{ $result['kind']==='synthetic' ? 'amber' : 'blue' }}">{{ $result['kind']==='synthetic' ? 'Data simulasi' : 'Data impor, belum terverifikasi' }}</span>
                            <h2 style="font-size:1.8rem;margin:12px 0 6px">{{ $result['ticker'] }} · Batas posisi</h2>
                            <p class="caption">Harga referensi pada {{ $result['last_date'] }} · {{ $result['observations'] }} sesi · Status observasi {{ $signalContext['state'] ?? 'UNKNOWN' }}</p>
                        </div><span class="badge amber">Khusus penelitian</span>
                    </div>
                    <div class="risk-metrics">
                        <div class="risk-metric"><span>Ukuran unit hipotetis</span><strong>{{ number_format($result['units'], 0) }}</strong></div>
                        <div class="risk-metric"><span>Anggaran risiko model</span><strong>{{ number_format($result['risk_budget'], 2) }}</strong></div>
                        <div class="risk-metric"><span>Batas eksposur model</span><strong>{{ number_format($result['exposure_budget'], 2) }}</strong></div>
                        <div class="risk-metric"><span>Perkiraan rugi pada stop*</span><strong>{{ number_format($result['modelled_loss_at_stop'], 2) }}</strong></div>
                        <div class="risk-metric"><span>Perkiraan hasil pada target*</span><strong>{{ number_format($result['modelled_gain_at_target'], 2) }}</strong></div>
                        <div class="risk-metric"><span>Risk–Reward Ratio*</span><strong>{{ $result['net_reward_to_risk'] === null ? 'N/A' : $result['net_reward_to_risk'].'×' }}</strong></div>
                    </div>
                    @if($result['units'] === 0)
                        <div class="risk-warning">Simulasi menghasilkan 0 unit: anggaran risiko atau batas eksposur terlalu kecil untuk satu unit instrumen berdasarkan asumsi ini.</div>
                    @endif
                    <p class="caption">*Termasuk asumsi fee dan slippage per sisi. Nilai ini bukan batas kerugian yang dijamin, terutama jika harga melompat melewati stop atau terjadi keterbatasan likuiditas.</p>
                </section>

                <section class="panel" style="margin-top:17px">
                    <h2>Referensi harga dan rentang model</h2>
                    <div class="risk-bridge">
                        <div class="risk-node"><span>Stop referensi (simulasi)</span><strong>{{ number_format($result['stop_reference'], 4) }}</strong></div>
                        <div class="risk-node"><span>Harga penutupan terakhir</span><strong>{{ number_format($result['reference_close'], 4) }}</strong></div>
                        <div class="risk-node"><span>Target referensi (simulasi)</span><strong>{{ number_format($result['target_reference'], 4) }}</strong></div>
                    </div>
                    <div class="tablewrap"><table><tbody>
                        <tr><th>ATR14 dari data</th><td>{{ number_format($result['atr14'], 4) }} ({{ $result['atr14_pct'] }}% dari close)</td></tr>
                        <tr><th>Jarak stop model</th><td>{{ $result['atr_multiple'] }} × ATR14</td></tr>
                        <tr><th>Jarak target model</th><td>{{ $result['reward_multiple'] }} × jarak stop</td></tr>
                        <tr><th>Batas paling ketat</th><td>{{ $result['binding_constraint'] }}</td></tr>
                        <tr><th>Alokasi termasuk biaya masuk</th><td>{{ number_format($result['notional_with_entry_costs'], 2) }} ({{ $result['effective_exposure_pct'] }}% modal)</td></tr>
                        <tr><th>Pemakaian anggaran rugi model</th><td>{{ $result['budget_utilization_pct'] }}%</td></tr>
                    </tbody></table></div>
                    <p class="risk-note">Perhitungan membatasi unit dengan dua syarat sekaligus: batas rugi hipotetis pada stop dan maksimum nilai modal untuk satu instrumen. Unit dibulatkan ke bawah sebagai satuan penuh; aturan lot/bursa nyata tidak dimodelkan.</p>
                </section>

                <section class="panel" style="margin-top:17px"><h2>Sumber data dan keterbatasan</h2>
                    <p class="muted">Harga sumber tidak diverifikasi sebagai kuotasi real time. Angka stop dan target adalah skenario matematis, bukan prediksi tercapainya harga atau peluang keberhasilan. Volatilitas masa lalu, biaya statis, dan penghitungan unit penuh belum memperhitungkan gangguan bursa, pajak, likuiditas, spread dinamis, atau perubahan pasar.</p>
                    <p class="caption">Sumber: {{ $result['source_name'] }} · impor {{ $result['imported_at'] }} · {{ $result['evidence'] }} · {{ $result['decision'] }} · trading disabled.</p>
                    <p><a class="button" href="{{ route('ii.signals.index') }}">Lihat indikator harga →</a> <a class="button" href="{{ route('ii.portfolio.index') }}" style="margin-top:8px;background:#1b4859;color:#c7f7ff">Simulasi portofolio →</a></p>
                </section>
            @elseif(count($available)===0)
                <section class="panel"><h2>Belum ada seri harga</h2><p class="muted">Tambahkan data CSV ke Signal Center atau muat DEMO9 sintetis pada Rolling Validation, lalu buka kembali Risk Budget.</p><p><a class="button" href="{{ route('ii.strategy.rolling') }}">Uji lintas periode →</a></p></section>
            @endif
        </div>
        <aside class="panel risk-side"><span class="badge">Simulasi risiko</span><h2 style="margin-top:12px">Atur batas modal</h2>
            <form method="GET" action="{{ route('ii.risk-budget.index') }}">
                <div class="field"><label for="ticker">Instrumen</label><select name="ticker" id="ticker" required>
                    @foreach($available as $item)<option value="{{ $item['ticker'] }}" @selected($ticker===$item['ticker'])>{{ $item['ticker'] }} · {{ $item['kind']==='synthetic' ? 'DEMO' : 'IMPORTED' }}</option>@endforeach
                </select></div>
                <div class="field"><label for="equity">Modal hipotetis (unit mata uang data)</label><input id="equity" name="equity" type="number" min="1000" max="10000000000" step="100" value="{{ $equity }}" required></div>
                <div class="risk-form-grid">
                    <div class="field"><label for="risk_pct">Risiko model (% modal)</label><input id="risk_pct" name="risk_pct" type="number" min="0.1" max="3" step="0.1" value="{{ $riskPct }}" required></div>
                    <div class="field"><label for="exposure_pct">Batas eksposur (%)</label><input id="exposure_pct" name="exposure_pct" type="number" min="5" max="100" step="1" value="{{ $exposurePct }}" required></div>
                    <div class="field"><label for="atr_multiple">Jarak stop × ATR14</label><input id="atr_multiple" name="atr_multiple" type="number" min="1" max="5" step="0.5" value="{{ $atrMultiple }}" required></div>
                    <div class="field"><label for="reward_multiple">Target × jarak stop</label><input id="reward_multiple" name="reward_multiple" type="number" min="1" max="5" step="0.5" value="{{ $rewardMultiple }}" required></div>
                    <div class="field"><label for="fee_bps">Biaya per transaksi (bps)</label><input id="fee_bps" name="fee_bps" type="number" min="0" max="100" value="{{ $fee }}" required></div>
                    <div class="field"><label for="slippage_bps">Slippage per transaksi (bps)</label><input id="slippage_bps" name="slippage_bps" type="number" min="0" max="100" value="{{ $slippage }}" required></div>
                </div>
                <button type="submit" style="width:100%" @disabled(count($available)===0)>Hitung skenario risiko</button>
            </form>
            <p class="risk-note">Default riset: 1% anggaran risiko, 20% batas eksposur, stop 2×ATR, target 2×jarak stop. Ini parameter ilustratif yang dapat Anda ubah, bukan anjuran untuk pasar atau portofolio tertentu.</p>
        </aside>
    </div>
@endsection
