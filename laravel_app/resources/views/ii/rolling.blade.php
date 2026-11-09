@extends('ii.layout')
@section('title','Rolling Validation')
@push('styles')
<style>
    .rolling-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(275px,.8fr);gap:18px;align-items:start}
    .rolling-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
    .rolling-metric{background:#0e1c30;border:1px solid #263b52;border-radius:11px;padding:16px}
    .rolling-metric span{display:block;color:var(--muted);font-size:.8rem}
    .rolling-metric strong{display:block;margin-top:7px;font-size:1.48rem;line-height:1.3}
    .rolling-alert{background:#3d2d17;border:1px solid #936d35;border-radius:10px;padding:14px;color:#f3dfb5;margin-bottom:15px}
    .rolling-step{border-left:3px solid var(--cyan);padding-left:13px;margin:15px 0;color:#bdd5e8;font-size:.88rem}
    .rolling-fold{color:var(--muted);font-size:.81rem;display:block;margin-top:5px}
    .rolling-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    @media(max-width:1110px){.rolling-grid{display:block}.rolling-side{margin-top:18px}}
    @media(max-width:710px){.rolling-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:490px){.rolling-metrics{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
    <p class="eyebrow">II / Trading Signal Intelligence / VS9 R1</p>
    <h1>Rolling Validation</h1>
    <p class="lead">Uji ketahanan strategi melalui beberapa siklus: pilih pendekatan dari riwayat masa lalu, uji pada 30 sesi berikutnya, perluas riwayat, dan ulangi. Tiap periode uji berdiri sendiri dan tidak tumpang tindih.</p>
    <p><a class="button" href="{{ route('ii.risk-budget.index', ['ticker' => $ticker]) }}">Hitung batas risiko posisi hipotetis →</a></p>
    <div class="boundary"><strong>HISTORICAL RESEARCH ONLY — NOT_ADMITTED · HOLD · AUTHORITY NONE.</strong> Riset beberapa periode lebih informatif daripada satu potongan historis, tetapi tetap tidak membuktikan keberhasilan pada transaksi mendatang.</div>
    <div class="rolling-grid">
        <div>
            @if(session('rolling_notice'))<div class="panel soft" role="status" style="margin-bottom:16px">{{ session('rolling_notice') }}</div>@endif
            @if($warning)<div class="error" role="alert">{{ $warning }}</div>@endif
            @if($result)
                @if($result['weak_sample'])
                    <div class="rolling-alert"><strong>KEKUATAN BUKTI TERBATAS.</strong> Fold pertama hanya memiliki {{ $result['folds'][0]['training_sessions'] }} sesi training efektif, dengan {{ $result['fold_count'] }} fold pengujian. Gunakan seri lebih panjang dan beberapa rezim pasar sebelum menarik kesimpulan.</div>
                @endif
                <section class="panel">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
                        <div><span class="badge {{ $result['kind']==='synthetic' ? 'amber' : 'blue' }}">{{ $result['kind']==='synthetic' ? 'SYNTHETIC DEMO' : 'USER DATA / UNVERIFIED' }}</span>
                            <h2 style="font-size:1.8rem;margin:12px 0 5px">{{ $result['ticker'] }} · {{ $result['fold_count'] }} fold</h2>
                            <p class="caption">{{ $result['first_test_date'] }} s.d. {{ $result['last_test_date'] }} · {{ $result['test_sessions_total'] }} sesi uji non-overlap</p>
                        </div><span class="badge amber">RESEARCH ONLY</span>
                    </div>
                    <div class="rolling-metrics">
                        <div class="rolling-metric"><span>Pertumbuhan gabungan strategi*</span><strong>{{ $result['strategy_growth_pct'] }}%</strong></div>
                        <div class="rolling-metric"><span>Pertumbuhan gabungan buy-and-hold*</span><strong>{{ $result['benchmark_growth_pct'] }}%</strong></div>
                        <div class="rolling-metric"><span>Selisih gabungan*</span><strong>{{ $result['aggregate_excess_pp'] }} pp</strong></div>
                        <div class="rolling-metric"><span>Fold di atas benchmark</span><strong>{{ $result['positive_folds'] }}/{{ $result['fold_count'] }}</strong></div>
                        <div class="rolling-metric"><span>Drawdown intraperiode terburuk</span><strong>{{ $result['worst_fold_intraperiod_drawdown_pct'] }}%</strong></div>
                        <div class="rolling-metric"><span>Sesi sejarah tersedia</span><strong>{{ $result['history_sessions'] }}</strong></div>
                    </div>
                    <p class="caption">*Komposit hipotetis: persentase hasil tiap fold dikalikan berurutan, dengan likuidasi asumtif pada penutupan akhir setiap fold. Ini bukan return portofolio live; biaya dan likuiditas nyata dapat berbeda.</p>
                </section>

                <section class="panel" style="margin-top:17px">
                    <h2>Hasil per periode pengujian</h2>
                    <div class="tablewrap"><table><thead><tr><th>Fold</th><th>Strategi dipilih</th><th>Return test*</th><th>Buy & hold*</th><th>Excess</th><th>DD sementara</th></tr></thead><tbody>
                        @foreach($result['folds'] as $fold)
                        <tr>
                            <td><strong>{{ $fold['number'] }}</strong><span class="rolling-fold">{{ $fold['test_start'] }} → {{ $fold['test_end'] }}</span></td>
                            <td>{{ $fold['selection'] }}<span class="rolling-fold">Train excess: {{ $fold['training_excess_pp'] }} pp</span></td>
                            <td>{{ $fold['strategy_return_pct'] }}%</td><td>{{ $fold['benchmark_return_pct'] }}%</td><td>{{ $fold['excess_pp'] }} pp</td>
                            <td>{{ $fold['drawdown_pct_before_exit'] }}%</td>
                        </tr>
                        @endforeach
                    </tbody></table></div>
                    <p class="caption">*Termasuk asumsi likuidasi akhir fold jika posisi strategi masih terbuka, juga pada pembanding. DD sementara berasal dari jejak ekuitas intraperiode sebelum biaya likuidasi akhir.</p>
                </section>

                <section class="panel" style="margin-top:17px">
                    <h2>Perubahan pilihan strategi</h2>
                    <div class="tablewrap"><table><thead><tr><th>Preset</th><th>Dipilih pada fold</th></tr></thead><tbody>
                        @foreach($result['selected_counts'] as $name => $count)<tr><td>{{ $name }}</td><td>{{ $count }} / {{ $result['fold_count'] }}</td></tr>@endforeach
                    </tbody></table></div>
                    <p class="muted">Preset dapat berubah dari satu fold ke fold berikutnya karena data training semakin panjang. Pergantian ini mencerminkan hasil metode pemilihan historis, bukan sinyal untuk membeli atau menjual hari ini.</p>
                    <div class="rolling-step"><strong>Disiplin waktu:</strong> pada setiap fold, keputusan preset ditetapkan menggunakan training hingga sehari sebelum blok test; sinyal harian memakai penutupan sebelum pembukaan eksekusi. Bagian test baru dievaluasi setelah pilihan dikunci.</div>
                </section>

                <section class="panel" style="margin-top:17px">
                    <h2>Batas dan provenance</h2>
                    <p class="muted">Maksimum empat fold terbaru, masing-masing 30 sesi. Training dimulai setelah 50 sesi indikator pemanasan dan diperluas pada setiap fold. Tidak ada penyetelan parameter berdasarkan fold uji yang sama. Penelitian belum mencakup market impact, pajak, dividen, corporate actions, optimasi portofolio, atau perubahan likuiditas.</p>
                    <p class="caption">Fee {{ $result['fee_bps'] }} bps/sisi · Slippage {{ $result['slippage_bps'] }} bps/sisi · Sumber {{ $result['source_name'] }} · impor {{ $result['imported_at'] }} · {{ $result['evidence'] }} · {{ $result['decision'] }}.</p>
                    <p><a class="button" href="{{ route('ii.strategy.validate', ['ticker' => $ticker]) }}">Kembali ke single holdout →</a></p>
                </section>
            @elseif(count($available)===0)
                <section class="panel"><h2>Belum ada data harga</h2><p class="muted">Impor data harga ke Trading Signal Center untuk memulai riset strategi.</p><a href="{{ route('ii.signals.index') }}">Buka Signal Center →</a></section>
            @endif
        </div>
        <aside class="panel rolling-side"><span class="badge">ROLLING RESEARCH · R1</span><h2 style="margin-top:12px">Pengaturan simulasi</h2>
            <form method="GET" action="{{ route('ii.strategy.rolling') }}">
                <div class="field"><label for="ticker">Instrumen</label><select name="ticker" id="ticker" required>
                    @foreach($available as $item)<option value="{{ $item['ticker'] }}" @selected($ticker===$item['ticker'])>{{ $item['ticker'] }} · {{ $item['kind']==='synthetic' ? 'DEMO' : 'IMPORTED' }}</option>@endforeach
                </select></div>
                <div class="rolling-fields">
                    <div class="field"><label for="fee_bps">Fee (bps/sisi)</label><input id="fee_bps" name="fee_bps" type="number" min="0" max="100" value="{{ $fee }}" required></div>
                    <div class="field"><label for="slippage_bps">Slippage (bps/sisi)</label><input id="slippage_bps" name="slippage_bps" type="number" min="0" max="100" value="{{ $slippage }}" required></div>
                </div>
                <button type="submit" style="width:100%" @disabled(count($available)===0)>Hitung rolling validation</button>
            </form>
            <div class="rolling-step"><strong>Minimal 150 sesi OHLCV.</strong> DEMO7 yang berisi 90 sesi dapat digunakan pada Strategy Lab dan Strategy Validation VS8 R2, tetapi belum cukup panjang untuk rolling validation ini.</div>
            <form method="POST" action="{{ route('ii.strategy.rolling.load-demo') }}">
                @csrf
                <button type="submit" style="width:100%">Muat DEMO9 sintetis (260 sesi)</button>
            </form>
            <p class="hint">Tombol membuat satu impor sintetis baru khusus akun Anda. Tidak mengubah data DEMO7 ataupun data pasar yang sudah ada. Contoh ini hanya untuk mempelajari fitur.</p>
            <p class="hint"><a href="{{ route('ii.signals.index') }}">Impor seri lebih panjang →</a></p>
        </aside>
    </div>
@endsection
