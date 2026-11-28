@extends('ii.layout')
@section('title','Backtesting')
@section('surface','ii-light-content')
@push('styles')
<style>
    .lab-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,.85fr);gap:18px;align-items:start}
    .result-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px;margin:16px 0}
    .result-metric{background:#0e1c30;border-radius:11px;padding:15px}.result-metric span{display:block;color:var(--muted);font-size:.77rem}.result-metric strong{font-size:1.5rem;font-weight:780;display:block;margin-top:4px}
    .curve{width:100%;height:auto;display:block;background:#0b192b;border-radius:11px;margin:15px 0}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:11px}.radio-line{display:flex;gap:8px;align-items:center;margin:10px 0}
    .explain{border-left:3px solid var(--cyan);padding-left:14px;margin:20px 0;color:#bed4e8;font-size:.87rem}
    .legend{display:flex;gap:20px;align-items:center;flex-wrap:wrap;font-size:.81rem;color:var(--muted)}
    .key{width:18px;height:3px;display:inline-block;vertical-align:middle;margin-right:6px}
    @media(max-width:1150px){.lab-layout{display:block}.result-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.config{margin-bottom:18px}}
.result-metric{background:#f1f5f7;color:#172535}.curve{background:#fff;border:1px solid #dce2e9}.curve line{stroke:#dce2e9}.curve polyline[stroke='#61d5e8']{stroke:#147d78}.curve polyline[stroke='#f5b96f']{stroke:#94651a}.explain{color:#526577}.legend .key[style*='#61d5e8']{background:#147d78!important}.legend .key[style*='#f5b96f']{background:#94651a!important}
</style>
@endpush
@section('content')
    <p class="eyebrow">Portofolio &amp; strategi</p>
    <h1>Backtesting</h1>
    <p class="lead">Uji historis strategi persilangan moving average pada data milik akun Anda. Simulasi menghitung keputusan berdasarkan penutupan hari sebelumnya, lalu memperhitungkan harga pembukaan sesi berikutnya, biaya, dan slippage.</p>
    <p><a class="button" href="{{ route('ii.strategy.validate', ['ticker' => $ticker, 'fee_bps' => $fee, 'slippage_bps' => $slippage]) }}">Evaluasi strategi dan sensitivitas biaya →</a></p>
    <div class="boundary"><strong>Backtesting historis.</strong> Hasil masa lalu bukan prediksi profit. Tidak ada rekomendasi investasi, koneksi broker, transaksi nyata, atau optimasi otomatis parameter.</div>
    <div class="lab-layout">
        <section>
            @if($warning)<div class="error" role="alert">{{ $warning }}</div>@endif
            @if($result)
                <div class="panel" style="margin-bottom:15px"><div style="display:flex;justify-content:space-between;gap:12px;align-items:start;flex-wrap:wrap"><div>
                    <span class="badge {{ $result['kind'] === 'synthetic' ? 'amber' : 'blue' }}">{{ $result['kind'] === 'synthetic' ? 'Data simulasi' : 'Data impor, belum terverifikasi' }}</span>
                    <h2 style="font-size:1.9rem;margin:13px 0 3px">{{ $result['ticker'] }} · SMA{{ $result['fast'] }}/SMA{{ $result['slow'] }}</h2>
                    <p class="caption">{{ $result['period_start'] }} s.d. {{ $result['period_end'] }} · {{ $result['observations'] }} sesi simulasi</p>
                </div><span class="badge amber">Khusus penelitian</span></div>
                <div class="result-grid">
                    <div class="result-metric"><span>Return strategi (net biaya simulasi)</span><strong>{{ $result['strategy_return_pct'] }}%</strong></div>
                    <div class="result-metric"><span>Buy-and-hold pembanding</span><strong>{{ $result['buy_hold_return_pct'] }}%</strong></div>
                    <div class="result-metric"><span>Selisih vs pembanding</span><strong>{{ $result['excess_return_pp'] }} pp</strong></div>
                    <div class="result-metric"><span>Max drawdown strategi</span><strong>{{ $result['max_drawdown_pct'] }}%</strong></div>
                    <div class="result-metric"><span>Transaksi simulasi / selesai</span><strong>{{ $result['trade_orders'] }} / {{ $result['closed_trades'] }}</strong></div>
                    <div class="result-metric"><span>Win rate transaksi selesai</span><strong>{{ $result['win_rate_pct'] === null ? 'N/A' : $result['win_rate_pct'].'%' }}</strong></div>
                </div>
                <div class="legend"><span><i class="key" style="background:#61d5e8"></i>Strategi SMA</span><span><i class="key" style="background:#f5b96f"></i>Buy-and-hold</span></div>
                <svg class="curve" viewBox="0 0 700 160" role="img" aria-label="Perbandingan nilai ekuitas strategi SMA dan buy-and-hold pada tanggal yang sama">
                    <line x1="10" y1="140" x2="690" y2="140" stroke="#344961" />
                    <line x1="10" y1="80" x2="690" y2="80" stroke="#253d54" />
                    <polyline fill="none" stroke="#f5b96f" stroke-width="2" stroke-linejoin="round" points="{{ $result['chart_buy_hold'] }}" />
                    <polyline fill="none" stroke="#61d5e8" stroke-width="2.6" stroke-linejoin="round" points="{{ $result['chart_strategy'] }}" />
                </svg>
                <p class="caption">Modal awal hipotetis: {{ number_format($result['starting_capital'], 0) }} unit mata uang CSV. Nilai akhir strategi {{ number_format($result['strategy_ending'], 0) }}, buy-and-hold {{ number_format($result['buy_hold_ending'], 0) }}. Posisi strategi terakhir: {{ $result['open_position_at_end'] ? 'MASIH TERBUKA (mark-to-market)' : 'TUNAI' }}.</p></div>
                <div class="panel" style="margin-top:15px"><h2>Jejak transaksi simulasi</h2><div class="tablewrap"><table><thead><tr><th>Tanggal</th><th>Aksi simulasi</th><th>Harga eksekusi</th><th>Alasan</th></tr></thead><tbody>
                    @forelse($result['trades'] as $trade)<tr><td>{{ $trade['date'] }}</td><td>{{ $trade['side'] }}</td><td>{{ number_format($trade['price'], 4) }}</td><td>{{ $trade['reason'] }}</td></tr>
                    @empty<tr><td colspan="4">Tidak ada perubahan posisi pada jendela historis yang dipilih.</td></tr>@endforelse
                </tbody></table></div><p class="caption">Daftar dibatasi 15 transaksi terakhir. SIM BUY/EXIT adalah catatan simulasi, bukan aksi trading.</p></div>
                <div class="panel" style="margin-top:15px"><h2>Asumsi, biaya dan asal data</h2>
                    <p class="muted">Biaya transaksi {{ $result['fee_bps'] }} bps/sisi dan slippage {{ $result['slippage_bps'] }} bps/sisi. Modal dialokasikan 100% saat sinyal tren naik dan 100% tunai saat tren tidak naik, tanpa leverage maupun short selling.</p>
                    <p class="muted">Benchmark buy-and-hold membeli pada pembukaan sesi awal yang sama dengan biaya yang sama. Nilai akhir menggunakan penutupan terakhir; posisi terbuka tidak dikenakan biaya likuidasi tambahan pada akhir sampel. Dividen, corporate actions, pajak, spread dinamis dan keterbatasan likuiditas belum dimodelkan.</p>
                    <p class="caption">Sumber: {{ $result['source_name'] }} · impor {{ $result['last_imported'] }} · Data impor belum terverifikasi.</p>
                </div>
            @elseif(count($available) === 0)
                <div class="panel"><h2>Belum ada riwayat harga</h2><p class="muted">Buka Trading Signal Center dan impor minimal 60 sesi CSV. Contoh sintetis DEMO7 sudah tersedia untuk mencoba Strategy Lab.</p><a class="button" href="{{ route('ii.signals.index') }}">Lihat data harga →</a></div>
            @endif
        </section>
        <aside class="panel config"><span class="badge">Pengaturan strategi</span><h2 style="margin-top:13px">Konfigurasi strategi</h2>
            <form method="GET" action="{{ route('ii.strategy.index') }}">
                <div class="field"><label for="ticker">Instrumen</label><select name="ticker" id="ticker" required>
                    @foreach($available as $item)<option value="{{ $item['ticker'] }}" @selected($ticker === $item['ticker'])>{{ $item['ticker'] }} · {{ $item['kind'] === 'synthetic' ? 'DEMO' : 'IMPORTED' }}</option>@endforeach
                </select></div>
                <div class="field"><label for="preset">Strategi</label><select name="preset" id="preset"><option value="10-30" @selected($preset === '10-30')>SMA10 / SMA30</option><option value="20-50" @selected($preset === '20-50')>SMA20 / SMA50</option></select></div>
                <div class="split">
                    <div class="field"><label for="fee_bps">Biaya (bps/sisi)</label><input id="fee_bps" name="fee_bps" type="number" min="0" max="100" step="1" value="{{ $fee }}" required></div>
                    <div class="field"><label for="slippage_bps">Slippage (bps/sisi)</label><input id="slippage_bps" name="slippage_bps" type="number" min="0" max="100" step="1" value="{{ $slippage }}" required></div>
                </div>
                <button type="submit" style="width:100%" @disabled(count($available) === 0)>Jalankan Backtesting</button>
            </form>
            <div class="explain"><strong>Tanpa menggunakan informasi masa depan:</strong> rata-rata dihitung dari harga penutupan yang sudah tersedia sampai sesi sebelumnya. Perubahan posisi baru disimulasikan di pembukaan sesi berikutnya.</div>
            <p class="hint">1 bps = 0,01%. Dua preset dibatasi agar percobaan ini tidak mengklaim menemukan parameter paling menguntungkan.</p>
            <p class="hint"><a href="{{ route('ii.signals.index') }}">Kembali ke data harga →</a></p>
        </aside>
    </div>
@endsection
