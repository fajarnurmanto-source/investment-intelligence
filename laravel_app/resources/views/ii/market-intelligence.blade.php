@extends('ii.layout')
@section('title','Analisis pasar')
@push('styles')
<style>
.mi{max-width:1120px;margin:auto;display:grid;gap:24px}.mi h1{font-size:clamp(1.65rem,3vw,2.35rem);margin:0 0 8px}.mi p{margin:0 0 12px}.mi .lead{margin:0}.mi .panel{padding:clamp(16px,2.5vw,26px)}.mi-steps{display:flex;gap:12px;flex-wrap:wrap;color:var(--muted);font-size:.85rem}.mi-steps b{color:var(--cyan)}.mi-upload{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start}.mi-check{display:flex;align-items:flex-start;gap:10px;font-weight:400}.mi-check input{width:auto;margin-top:5px;flex:none}.mi-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.mi-kpi{padding:16px;border:1px solid var(--line);border-radius:12px;background:var(--surface)}.mi-kpi strong{display:block;font-size:clamp(1.3rem,3vw,2rem);font-variant-numeric:tabular-nums}.mi-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.mi table{min-width:0;font-variant-numeric:tabular-nums}.mi th{letter-spacing:0;text-transform:none}.mi td:last-child,.mi th:last-child{text-align:right}.mi-bar{height:14px;display:flex;overflow:hidden;background:var(--surface);border-radius:6px;margin:14px 0}.mi-bar span{display:block}.mi summary{cursor:pointer;font-weight:650}.mi details p{margin:12px 0}.mi-hash{overflow-wrap:anywhere;font-family:monospace;font-size:.8rem}.mi-positive{color:var(--green)}.mi-negative{color:var(--red)}.mi a.button{margin-top:8px}.mi .note{font-size:.85rem;color:var(--muted)}@media(max-width:850px){.mi-upload,.mi-two{grid-template-columns:1fr}.mi-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:420px){.mi{gap:16px}.mi-kpi{padding:12px}}
</style>
@endpush
@section('content')
<div class="mi">
<header>
<p class="eyebrow">Riset saham / Bursa Efek Indonesia</p>
<h1>Analisis pasar</h1>
<p class="lead">Pahami arah pergerakan saham, aktivitas transaksi, dan data yang perlu diperiksa dalam satu hari perdagangan.</p>
</header>
<div class="mi-steps"><span><b>01</b> Pilih data</span><span><b>02</b> Lihat ringkasan</span><span><b>03</b> Telusuri saham</span></div>
@if($notice)
<div class="error" role="alert">{{ $notice }}</div>
@endif
@if(!$result)
<section class="panel mi-upload">
<div>
<h2>Mulai analisis</h2>
<p>Unduh ringkasan saham harian dari BEI, kemudian pilih file Excel asli dari komputer Anda.</p>
<a href="{{ \App\Services\IdxOfficialExportIntake::SOURCE }}" target="_blank" rel="noopener noreferrer">Buka sumber data BEI</a>
<p class="note">File diproses di aplikasi lokal. Hasil tidak disimpan oleh fitur ini atau dikirim ke penyedia AI.</p>
</div>
<form method="POST" action="{{ route('ii.market-intelligence.analyze') }}" enctype="multipart/form-data">
@csrf
<div class="field"><label for="mi-file">File ringkasan saham (.xlsx)</label><input id="mi-file" type="file" name="workbook" accept=".xlsx" required></div>
<label class="mi-check"><input type="checkbox" name="rights" value="1" required><span>Saya telah memastikan data boleh digunakan untuk riset pribadi nonkomersial dan tidak akan mendistribusikannya.</span></label>
<button type="submit">Analisis pasar</button>
</form>
</section>
<section class="panel"><h2>Apa yang akan Anda lihat?</h2><div class="mi-two"><div><h3>Arah pasar</h3><p class="muted">Berapa saham naik, turun, atau tidak berubah dibanding harga sebelumnya.</p></div><div><h3>Aktivitas perdagangan</h3><p class="muted">Saham dengan pergerakan harga dan aktivitas transaksi yang menonjol.</p></div></div></section>
@else
@php($b = $result['breadth'])
@php($eligible = $b['up'] + $b['down'] + $b['flat'])
<section class="panel">
<div class="section-header" style="margin:0 0 16px"><div><h2>Ringkasan perdagangan</h2><p class="note">{{ $result['session'] }} · Data dari file yang Anda pilih</p></div><a class="button quiet" href="{{ route('ii.market-intelligence.index') }}">Analisis file lain</a></div>
<div class="mi-kpis">
@foreach(['Saham dibaca'=>$result['universe'],'Harga naik'=>$b['up'],'Harga turun'=>$b['down'],'Harga tetap'=>$b['flat']] as $label=>$n)
<div class="mi-kpi"><span class="note">{{ $label }}</span><strong>{{ number_format($n,0,',','.') }}</strong></div>
@endforeach
</div>
@if($eligible > 0)
<div class="mi-bar" aria-hidden="true">@foreach(['up'=>'#67c79a','down'=>'#e58b8b','flat'=>'#91a9c5'] as $key=>$color)<span style="width:{{ 100*$b[$key]/$eligible }}%;background:{{ $color }}"></span>@endforeach</div>
<p>{{ $b['up'] > $b['down'] ? 'Lebih banyak saham naik daripada turun.' : ($b['up'] < $b['down'] ? 'Lebih banyak saham turun daripada naik.' : 'Jumlah saham naik dan turun seimbang.') }} Ini berlaku untuk {{ $eligible }} saham yang memenuhi syarat perbandingan.</p>
@else
<p>Belum ada saham yang memenuhi syarat perbandingan harga.</p>
@endif
<p class="note">{{ $b['excluded'] }} saham tidak dimasukkan dalam perbandingan karena data harga atau volume tidak memenuhi syarat. Ini bukan ukuran perubahan IHSG.</p>
</section>
<div class="mi-two">
@foreach(['Perubahan harga tertinggi'=>'top_price_moves','Perubahan harga terendah'=>'bottom_price_moves'] as $label=>$key)
<section class="panel"><h2>{{ $label }}</h2><p class="note">Dibanding harga Previous pada file, bukan hasil prediksi.</p><div class="tablewrap"><table><thead><tr><th>Saham</th><th>Perubahan</th></tr></thead><tbody>
@forelse($result[$key] as $row)<tr><td><a href="{{ route('ii.company', ['ticker'=>$row['ticker']]) }}">{{ $row['ticker'] }}</a></td><td class="{{ $row['close_vs_previous_pct'] > 0 ? 'mi-positive' : ($row['close_vs_previous_pct'] < 0 ? 'mi-negative' : '') }}">{{ number_format($row['close_vs_previous_pct'],2,',','.') }}%</td></tr>@empty<tr><td colspan="2">Data belum mencukupi.</td></tr>@endforelse
</tbody></table></div></section>
@endforeach
</div>
<section class="panel"><h2>Aktivitas transaksi</h2><p class="note">Angka berikut mengikuti kolom asli file. Satuan dan cakupan pasar belum diperiksa; jangan gunakan sebagai nilai rupiah atau jumlah lembar terverifikasi.</p>
<div class="mi-two">
@foreach(['Selisih beli-jual asing tertinggi'=>['top_foreign_raw_net','raw_net'],'Selisih beli-jual asing terendah'=>['bottom_foreign_raw_net','raw_net'],'Nilai transaksi terbesar'=>['top_raw_value','raw_value']] as $label=>$spec)
<div><h3>{{ $label }}</h3><div class="tablewrap"><table><thead><tr><th>Saham</th><th>Angka sumber</th></tr></thead><tbody>@forelse($result[$spec[0]] as $row)<tr><td>{{ $row['ticker'] }}</td><td>{{ number_format($row[$spec[1]],0,',','.') }}</td></tr>@empty<tr><td colspan="2">Data belum mencukupi.</td></tr>@endforelse</tbody></table></div></div>
@endforeach
</div><p class="note">Data beli-jual tersedia untuk {{ $result['foreign_field_coverage'] }} saham; data aktivitas lengkap untuk {{ $result['liquidity_field_coverage'] }} saham.</p></section>
<section class="panel"><details><summary>Kualitas dan sumber data</summary><p>Harga pembukaan nol: {{ $b['zero_open'] }}. Volume nol: {{ $b['zero_volume'] }}. Volume kosong atau tidak valid: {{ $b['missing_volume'] }}. Data harga tidak memenuhi syarat: {{ $b['invalid_ohlc'] }}. Kelompok ini dapat saling beririsan.</p><p>Perbandingan menggunakan volume positif, harga pembukaan dan penutupan positif, rentang harga yang konsisten serta harga Previous positif.</p><p class="mi-hash">Identitas file (SHA-256): {{ $result['sha256'] }}</p><p>File belum diverifikasi secara independen. Analisis hanya menggambarkan satu sesi, bukan rekomendasi beli atau jual.</p></details></section>
@endif
</div>
@endsection
