<section class="panel form-card" aria-labelledby="v4-share-title">
    <span class="badge blue">V4 · CORPORATE ACTION BASIS</span>
    <h2 id="v4-share-title" style="margin-top:12px">Rekonsiliasi basis saham</h2>
    <p class="hint">Harga mentah setelah periode laporan ditahan dari valuasi sampai seluruh aksi korporasi dalam rentang tanggal tersebut ditelaah. Review adalah atestasi pengguna dengan tautan sumber, bukan verifikasi otomatis bursa.</p>

    <h3>1. Catat aksi korporasi</h3>
    <form method="POST" action="{{ route('ii.fundamentals.corporate-actions.store') }}">@csrf
        <div class="split">
            <div class="field"><label for="ca_ticker">Emiten</label><input id="ca_ticker" name="ticker" required maxlength="16" placeholder="TLKM.JK"></div>
            <div class="field"><label for="ca_date">Tanggal efektif</label><input id="ca_date" type="date" name="effective_on" required></div>
        </div>
        <div class="field"><label for="ca_type">Jenis aksi</label><select id="ca_type" name="action_type"><option value="split">Stock split</option><option value="reverse_split">Reverse split</option><option value="bonus_issue">Bonus issue</option></select></div>
        <div class="split">
            <div class="field"><label for="ca_new">Saham baru (rasio)</label><input id="ca_new" type="number" name="new_shares" min="1" max="1000000000" step="1" required placeholder="2"></div>
            <div class="field"><label for="ca_old">Saham lama (rasio)</label><input id="ca_old" type="number" name="old_shares" min="1" max="1000000000" step="1" required placeholder="1"></div>
        </div>
        <div class="field"><label for="ca_source">Nama sumber</label><input id="ca_source" name="source_name" maxlength="160" required></div>
        <div class="field"><label for="ca_url">URL pengumuman sumber</label><input id="ca_url" name="source_url" type="url" required></div>
        <div class="split">
            <div class="field"><label for="ca_rights">Hak penggunaan</label><select id="ca_rights" name="usage_rights"><option value="internal_research">Internal research</option><option value="licensed_internal">Licensed internal</option><option value="redistribution_permitted">Redistribution permitted</option></select></div>
            <div class="field"><label for="ca_confidence">Confidence</label><select id="ca_confidence" name="provenance_confidence"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>
        </div>
        <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required>Saya memiliki hak penggunaan sumber yang dicatat.</label></div>
        <button type="submit" style="width:100%">Catat aksi secara immutable</button>
    </form>

    <h3 style="margin-top:20px">2. Review cakupan aksi</h3>
    <p class="hint">Gunakan tanggal akhir periode dan tanggal harga yang sudah ada di fundamental/register EOD. Periksa juga bahwa tidak ada aksi lain yang terlewat sepanjang interval. Jika tidak ada aksi, faktor otomatis 1.</p>
    <form method="POST" action="{{ route('ii.fundamentals.share-basis.review') }}">@csrf
        <div class="field"><label for="basis_ticker">Emiten</label><input id="basis_ticker" name="ticker" required maxlength="16" placeholder="TLKM.JK"></div>
        <div class="split">
            <div class="field"><label for="basis_period_end">Akhir periode laporan</label><input id="basis_period_end" type="date" name="period_end" required></div>
            <div class="field"><label for="basis_price_date">Tanggal harga</label><input id="basis_price_date" type="date" name="price_observed_on" required></div>
        </div>
        <div class="field"><label for="basis_source">Sumber pemeriksaan seluruh aksi</label><input id="basis_source" name="source_name" maxlength="160" required></div>
        <div class="field"><label for="basis_url">URL referensi pemeriksaan</label><input id="basis_url" name="source_url" type="url" required></div>
        <div class="split">
            <div class="field"><label for="basis_rights">Hak penggunaan</label><select id="basis_rights" name="usage_rights"><option value="internal_research">Internal research</option><option value="licensed_internal">Licensed internal</option><option value="redistribution_permitted">Redistribution permitted</option></select></div>
            <div class="field"><label for="basis_confidence">Confidence</label><select id="basis_confidence" name="provenance_confidence"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>
        </div>
        <div class="field"><label class="checkline"><input type="checkbox" name="coverage_confirmed" value="1" required>Saya telah memeriksa kelengkapan aksi dalam interval tersebut, termasuk bila tidak ada aksi.</label></div>
        <div class="field"><label class="checkline"><input type="checkbox" name="rights_confirmed" value="1" required>Saya berhak memakai informasi sumber untuk riset internal.</label></div>
        <button type="submit" style="width:100%">Simpan review & faktor basis saham</button>
    </form>

    @if($corporateActions->isNotEmpty() || $shareBasisReviews->isNotEmpty())
        <div class="tablewrap" style="margin-top:14px"><table><thead><tr><th>Emiten / tanggal</th><th>Status</th><th>Bukti</th></tr></thead><tbody>
            @foreach($shareBasisReviews as $review)
                <tr><td>{{ $review->issuer_code }}<br><span class="caption">{{ $review->period_end->toDateString() }} → {{ $review->price_observed_on->toDateString() }}</span></td><td>REVIEW TERCATAT<br><span class="caption">Faktor {{ $review->cumulative_share_factor }} · cek ulang jika ada aksi tambahan</span></td><td><a href="{{ $review->source_url }}" rel="noopener noreferrer">{{ $review->source_name }}</a><br><span class="mono">{{ substr($review->events_sha256,0,12) }}…</span></td></tr>
            @endforeach
            @foreach($corporateActions as $action)
                <tr><td>{{ $action->issuer_code }}<br><span class="caption">{{ $action->effective_on->toDateString() }}</span></td><td>{{ strtoupper($action->action_type) }}<br><span class="caption">{{ $action->new_shares }} : {{ $action->old_shares }}</span></td><td><a href="{{ $action->source_url }}" rel="noopener noreferrer">{{ $action->source_name }}</a><br><span class="mono">{{ substr($action->sha256,0,12) }}…</span></td></tr>
            @endforeach
        </tbody></table></div>
    @endif
</section>
