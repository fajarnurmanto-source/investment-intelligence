@extends('ii.layout')
@section('title','Analisis pasar')
@section('surface','ii-light-content')
@push('styles')
<style>
.mi{max-width:1120px;margin:auto;display:grid;gap:24px}.mi h1{font-size:clamp(1.65rem,3vw,2.35rem);margin:0 0 8px}.mi p{margin:0 0 12px}.mi .lead{margin:0}.mi .panel{padding:clamp(16px,2.5vw,26px)}.mi-steps{display:flex;gap:12px;flex-wrap:wrap;color:var(--muted);font-size:.85rem}.mi-steps b{color:var(--cyan)}.mi-upload{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start}.mi-check{display:flex;align-items:flex-start;gap:10px;font-weight:400}.mi-check input{width:auto;margin-top:5px;flex:none}.mi-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.mi-kpi{padding:16px;border:1px solid var(--line);border-radius:12px;background:var(--surface)}.mi-kpi strong{display:block;font-size:clamp(1.3rem,3vw,2rem);font-variant-numeric:tabular-nums}.mi-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.mi table{min-width:0;font-variant-numeric:tabular-nums}.mi th{letter-spacing:0;text-transform:none}.mi td:last-child,.mi th:last-child{text-align:right}.mi-bar{height:14px;display:flex;overflow:hidden;background:var(--surface);border-radius:6px;margin:14px 0}.mi-bar span{display:block}.mi summary{cursor:pointer;font-weight:650}.mi details p{margin:12px 0}.mi-hash{overflow-wrap:anywhere;font-family:monospace;font-size:.8rem}.mi-positive{color:var(--green)}.mi-negative{color:var(--red)}.mi a.button{margin-top:8px}.mi .note{font-size:.85rem;color:var(--muted)}@media(max-width:850px){.mi-upload,.mi-two{grid-template-columns:1fr}.mi-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:420px){.mi{gap:16px}.mi-kpi{padding:12px}}
.mi-steps{padding:10px 14px;background:#fff;border:1px solid var(--ii-border);border-radius:18px;box-shadow:var(--ii-shadow);width:fit-content}.mi-steps span{padding:4px 8px}.mi-kpi:first-child{background:linear-gradient(130deg,#142a40,#20575b);color:white;border:0}.mi-kpi:first-child .note{color:#d7e9ec}.mi-upload form{padding:20px;background:#f4f6f9;border:1px solid var(--ii-border);border-radius:14px}.mi summary{color:var(--ii-teal);min-height:36px}.mi .mi-bar{height:10px}.mi .mi-check{margin-bottom:16px}.mi .note{line-height:1.6}.mi h2{font-size:1.1rem;letter-spacing:-.015em}.mi .panel{min-width:0}.mi .mi-two>*{min-width:0}.mi td{padding:10px 6px}.mi td a{font-weight:650;text-decoration:none}.mi td a:hover{text-decoration:underline}.mi .mi-kpis{margin-bottom:16px}
@supports(backdrop-filter:blur(12px)){.mi-steps{background:rgba(255,255,255,.82);backdrop-filter:blur(12px)}}
@media(max-width:540px){.mi-upload form{padding:14px}.mi-steps{width:100%;gap:4px;padding:8px}.mi-steps span{padding:4px;font-size:.77rem}.mi-kpi strong{font-size:1.5rem}}
</style>
@endpush
@section('content')
<div class="mi">
<header>
<p class="eyebrow">Riset saham / Bursa Efek Indonesia</p>
<h1>Analisis pasar</h1>
<p class="lead">Lihat arah pasar dalam satu sesi atau bandingkan beberapa tanggal perdagangan.</p>
</header>
<div class="mi-steps"><span><b>01</b> Pilih data</span><span><b>02</b> Lihat ringkasan</span><span><b>03</b> Telusuri saham</span></div>
@if($notice)
<div class="error" role="alert">{{ $notice }}</div>
@endif
@if($comparison ?? null)
<section class="panel">
<div class="section-header"><h2>Perbandingan sesi perdagangan</h2><a class="button quiet" href="{{ route('ii.market-intelligence.index') }}">Analisis baru</a></div>
<div class="mi-kpis">
<div class="mi-kpi"><span class="note">Sesi terpilih</span><strong>{{ count($comparison['sessions']) }}</strong></div>
<div class="mi-kpi"><span class="note">Saham dengan data lengkap di semua sesi</span><strong>{{ $comparison['matched_count'] }}</strong></div>
<div class="mi-kpi"><span class="note">Median perubahan harga kelompok</span><strong>{{ $comparison['median_change_pct'] === null ? '—' : number_format($comparison['median_change_pct'],2,',','.') . '%' }}</strong></div>
</div>
<p class="note">Tanggal yang dipilih belum tentu berurutan. Perubahan harga penutupan mentah belum disesuaikan untuk aksi korporasi atau dividen; bukan return total, perubahan IHSG, atau rekomendasi transaksi.</p>
<div class="tablewrap"><table><thead><tr><th>Tanggal</th><th>Naik</th><th>Turun</th><th>Tetap</th><th>Memenuhi syarat / total</th></tr></thead><tbody>
@foreach($comparison['sessions'] as $session)
<tr><td>{{ $session['date'] }}</td><td>{{ $session['up'] }}</td><td>{{ $session['down'] }}</td><td>{{ $session['flat'] }}</td><td>{{ $session['eligible'] }} / {{ $session['universe'] }}</td></tr>
@endforeach
</tbody></table></div>
</section>
<div class="mi-two">
@foreach(['Perubahan harga tertinggi'=>'leaders','Perubahan harga terendah'=>'laggards'] as $label=>$key)
<section class="panel"><h2>{{ $label }}</h2><p class="note">Perbandingan penutupan tanggal awal dan akhir, untuk saham dengan data yang memenuhi syarat di semua tanggal terpilih.</p>
<div class="tablewrap"><table><thead><tr><th>Saham</th><th>Harga berubah</th><th>Selisih dari median (poin %)</th></tr></thead><tbody>
@forelse($comparison[$key] as $row)
<tr><td><a href="{{ route('ii.company',['ticker'=>$row['ticker']]) }}">{{ $row['ticker'] }}</a></td><td>{{ number_format($row['price_change_pct'],2,',','.') }}%</td><td>{{ number_format($row['relative_to_matched_median_pp'],2,',','.') }}</td></tr>
@empty<tr><td colspan="3">Belum ada saham yang dapat dibandingkan.</td></tr>@endforelse
</tbody></table></div></section>
@endforeach
</div>
<section class="panel"><h2>Aktivitas perdagangan relatif</h2><p class="note">Rasio angka volume terakhir terhadap rata-rata tanggal sebelumnya yang dipilih. Satuan, cakupan dan kesetaraan basis volume antarfile belum diverifikasi. Rasio ini bukan deteksi anomali statistik atau ukuran likuiditas yang terverifikasi.</p>
<div class="tablewrap"><table><thead><tr><th>Saham</th><th>Rasio volume mentah</th></tr></thead><tbody>@forelse($comparison['activity'] as $row)<tr><td>{{ $row['ticker'] }}</td><td>{{ number_format($row['raw_volume_ratio'],2,',','.') }}×</td></tr>@empty<tr><td colspan="2">Data belum mencukupi.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><details><summary>Sumber dan batas analisis</summary><p>File diproses lokal tanpa penyimpanan permanen. Hak penggunaan dikonfirmasi pengguna, bukan verifikasi lisensi atau keaslian independen. Tidak ada persetujuan transaksi.</p>@foreach($comparison['sessions'] as $session)<p class="mi-hash">{{ $session['date'] }} · {{ $session['sha256'] }}</p>@endforeach</details></section>
@elseif(!$result)
@if($archive ?? null)
<section class="panel mi-upload">
<div><h2>Arsip perdagangan</h2><p>Gunakan data yang telah tersimpan dengan izin retensi untuk membandingkan tanggal tanpa unggah ulang.</p>
<div class="mi-kpis">
<div class="mi-kpi"><span class="note">Sesi tersimpan</span><strong>{{ $archive['coverage']['sessions'] }}</strong></div>
<div class="mi-kpi"><span class="note">Observasi tersimpan</span><strong>{{ number_format($archive['coverage']['observations'],0,',','.') }}</strong></div>
</div>
@if($archive['coverage']['sessions'] > 0)
<p>Rentang arsip: {{ $archive['coverage']['first_date'] }} – {{ $archive['coverage']['last_date'] }}.</p>
@endif
<p class="note">{{ count($archive['batches']) }} sesi terakhir tersedia dalam daftar (maksimal 30). Rentang tanggal tidak membuktikan arsip lengkap. Tanggal yang tidak tersedia belum dibandingkan dengan kalender perdagangan resmi.</p>
@if(count($archive['batches']) < 2)<p class="note">Diperlukan sedikitnya dua sesi arsip. Penambahan arsip memerlukan konfirmasi hak penyimpanan lokal secara terpisah.</p>@endif
</div>
@if(count($archive['batches']) >= 2)
<form method="POST" action="{{ route('ii.market-intelligence.analyze') }}">
@csrf
<input type="hidden" name="mode" value="history">
<fieldset><legend>Pilih 2–5 tanggal</legend>
@foreach($archive['batches'] as $batch)
<label class="mi-check"><input type="checkbox" name="dates[]" value="{{ $batch['session_date'] }}"><span>{{ $batch['session_date'] }} · {{ $batch['rows'] }} baris</span></label>
@endforeach
</fieldset>
<button type="submit">Analisis arsip</button>
</form>
@endif
</section>
@endif
<section class="panel mi-upload">
<div>
<h2>Mulai analisis</h2>
<p>Unduh ringkasan saham harian dari BEI, kemudian pilih file Excel asli dari komputer Anda.</p>
<a href="{{ \App\Services\IdxOfficialExportIntake::SOURCE }}" target="_blank" rel="noopener noreferrer">Buka sumber data BEI</a>
<p class="note">File diproses di komputer Anda. Hasil tidak disimpan atau dikirim ke penyedia AI oleh fitur ini.</p>
</div>
<form method="POST" action="{{ route('ii.market-intelligence.analyze') }}" enctype="multipart/form-data">
@csrf
<div class="field"><label for="mi-file">File ringkasan saham (.xlsx)</label><input id="mi-file" type="file" name="workbook" accept=".xlsx" required></div>
<label class="mi-check"><input type="checkbox" name="rights" value="1" required><span>Saya telah memastikan data boleh digunakan untuk riset pribadi nonkomersial dan tidak akan mendistribusikannya.</span></label>
<button type="submit">Analisis pasar</button>
</form>
</section>
<section class="panel mi-upload"><div><h2>Bandingkan beberapa tanggal</h2><p>Pilih 2–5 ringkasan saham asli BEI dari tanggal berbeda. Lihat perubahan harga, arah pasar dan aktivitas relatif.</p><p class="note">Total file maksimal 19 MB. Hanya untuk penelitian pribadi dengan hak penggunaan yang telah Anda pastikan.</p></div>
<form method="POST" action="{{ route('ii.market-intelligence.analyze') }}" enctype="multipart/form-data">@csrf
<div class="field"><label for="mi-files">File ringkasan saham (.xlsx)</label><input id="mi-files" type="file" name="workbooks[]" accept=".xlsx" multiple required></div>
<label class="mi-check"><input type="checkbox" name="rights" value="1" required><span>Saya memiliki hak penggunaan untuk seluruh file dan tidak akan mendistribusikannya.</span></label>
<button type="submit">Bandingkan sesi</button></form></section>
<section class="panel"><h2>Apa yang akan Anda lihat?</h2><div class="mi-two"><div><h3>Arah pasar</h3><p class="muted">Berapa saham naik, turun, atau tidak berubah dibanding harga sebelumnya.</p></div><div><h3>Aktivitas perdagangan</h3><p class="muted">Saham dengan pergerakan harga dan aktivitas transaksi yang menonjol.</p></div></div></section>
@else
@php($b = $result['breadth'])
@php($eligible = $b['up'] + $b['down'] + $b['flat'])
<section class="panel">
<div class="section-header" style="margin:0 0 16px"><div><h2>Ringkasan perdagangan</h2><p class="note">{{ $result['session'] }} · Ringkasan saham BEI pilihan Anda</p></div><a class="button quiet" href="{{ route('ii.market-intelligence.index') }}">Analisis file lain</a></div>
<div class="mi-kpis">
@foreach(['Jumlah saham'=>$result['universe'],'Harga naik'=>$b['up'],'Harga turun'=>$b['down'],'Harga tetap'=>$b['flat']] as $label=>$n)
<div class="mi-kpi"><span class="note">{{ $label }}</span><strong>{{ number_format($n,0,',','.') }}</strong></div>
@endforeach
</div>
@if($eligible > 0)
<div class="mi-bar" aria-hidden="true">@foreach(['up'=>'#67c79a','down'=>'#e58b8b','flat'=>'#91a9c5'] as $key=>$color)<span style="width:{{ 100*$b[$key]/$eligible }}%;background:{{ $color }}"></span>@endforeach</div>
<p>{{ $b['up'] > $b['down'] ? 'Lebih banyak saham naik daripada turun.' : ($b['up'] < $b['down'] ? 'Lebih banyak saham turun daripada naik.' : 'Jumlah saham naik dan turun seimbang.') }} Ini berlaku untuk {{ $eligible }} saham yang memenuhi syarat perbandingan.</p>
@else
<p>Belum ada saham yang memenuhi syarat perbandingan harga.</p>
@endif
<p class="note">{{ $b['excluded'] }} saham tidak masuk perbandingan karena data harga atau volume tidak memenuhi syarat. Ringkasan satu sesi ini bukan perubahan IHSG atau rekomendasi beli/jual. File belum diverifikasi secara independen.</p>
</section>
<div class="mi-two">
@foreach(['Perubahan harga tertinggi'=>'top_price_moves','Perubahan harga terendah'=>'bottom_price_moves'] as $label=>$key)
<section class="panel"><h2>{{ $label }}</h2><p class="note">Dibanding harga sebelumnya yang tercatat dalam file.</p><div class="tablewrap"><table><thead><tr><th>Saham</th><th>Perubahan</th></tr></thead><tbody>
@forelse($result[$key] as $row)<tr><td><a href="{{ route('ii.company', ['ticker'=>$row['ticker']]) }}">{{ $row['ticker'] }}</a></td><td class="{{ $row['close_vs_previous_pct'] > 0 ? 'mi-positive' : ($row['close_vs_previous_pct'] < 0 ? 'mi-negative' : '') }}">{{ number_format($row['close_vs_previous_pct'],2,',','.') }}%</td></tr>@empty<tr><td colspan="2">Data belum mencukupi.</td></tr>@endforelse
</tbody></table></div></section>
@endforeach
</div>
<section class="panel"><h2>Aktivitas transaksi</h2><p class="note">Satuan dan cakupan transaksi belum dipastikan. Angka di bawah bukan nilai rupiah atau jumlah lembar yang telah terverifikasi.</p><details><summary>Lihat rincian transaksi</summary>
<div class="mi-two">
@foreach(['Selisih beli-jual asing tertinggi'=>['top_foreign_raw_net','raw_net'],'Selisih beli-jual asing terendah'=>['bottom_foreign_raw_net','raw_net'],'Nilai transaksi terbesar (angka sumber)'=>['top_raw_value','raw_value']] as $label=>$spec)
<div><h3>{{ $label }}</h3><div class="tablewrap"><table><thead><tr><th>Saham</th><th>Angka sumber</th></tr></thead><tbody>@forelse($result[$spec[0]] as $row)<tr><td>{{ $row['ticker'] }}</td><td>{{ number_format($row[$spec[1]],0,',','.') }}</td></tr>@empty<tr><td colspan="2">Data belum mencukupi.</td></tr>@endforelse</tbody></table></div></div>
@endforeach
</div><p class="note">Data beli-jual tersedia untuk {{ $result['foreign_field_coverage'] }} saham; data aktivitas lengkap untuk {{ $result['liquidity_field_coverage'] }} saham.</p></details></section>
<section class="panel"><details><summary>Kualitas dan sumber data</summary><p>Harga pembukaan nol: {{ $b['zero_open'] }}. Volume nol: {{ $b['zero_volume'] }}. Volume kosong atau tidak valid: {{ $b['missing_volume'] }}. Data harga tidak memenuhi syarat: {{ $b['invalid_ohlc'] }}. Kelompok ini dapat saling beririsan.</p><p>Perbandingan menggunakan volume positif, harga pembukaan dan penutupan positif, rentang harga yang konsisten serta harga sebelumnya lebih dari nol.</p><p class="mi-hash">Sidik digital file (SHA-256): {{ $result['sha256'] }}</p><p>File belum diperiksa oleh sumber independen. Analisis ini menggambarkan satu sesi, bukan rekomendasi beli atau jual.</p></details></section>
@endif
</div>
@endsection
