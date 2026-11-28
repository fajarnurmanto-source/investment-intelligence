<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalWorkspaceV5Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    public function test_fundamental_workspace_separates_overview_sources_prices_and_reconciliation(): void
    {
        $this->actingAs($this->analyst());

        $this->get('/ii/fundamentals')
            ->assertOk()->assertSee('Ringkasan emiten')
            ->assertSee('Valuasi tersedia')
            ->assertSee('Data belum disahkan sebagai dasar keputusan investasi.')
            ->assertDontSee('id="fundamental_file"', false)
            ->assertDontSee('id="market_prices_csv"', false)
            ->assertDontSee('id="basis_ticker"', false);

        $this->get('/ii/fundamentals?workspace=sources')
            ->assertOk()->assertSee('Batch ingestion')
            ->assertSee('id="fundamental_file"', false)
            ->assertSee('id="manifest"', false)
            ->assertDontSee('id="market_prices_csv"', false)
            ->assertDontSee('id="basis_ticker"', false);

        $this->get('/ii/fundamentals?workspace=prices')
            ->assertOk()->assertSee('Harga pasar')
            ->assertSee('id="price_ticker"', false)
            ->assertSee('id="market_prices_csv"', false)
            ->assertDontSee('id="fundamental_file"', false)
            ->assertDontSee('id="basis_ticker"', false);

        $this->get('/ii/fundamentals?workspace=reconciliation')
            ->assertOk()->assertSee('Status keselarasan basis saham')
            ->assertSee('id="ca_ticker"', false)
            ->assertSee('id="basis_ticker"', false)
            ->assertDontSee('id="fundamental_file"', false)
            ->assertDontSee('id="market_prices_csv"', false);
    }

    public function test_invalid_workspace_falls_back_to_overview_and_screener_has_comparison_view(): void
    {
        $this->actingAs($this->analyst());

        $this->get('/ii/fundamentals?workspace=untrusted')
            ->assertOk()->assertSee('Ringkasan emiten')
            ->assertDontSee('id="ca_ticker"', false)
            ->assertDontSee('id="market_prices_csv"', false);

        $this->get('/ii/screener')
            ->assertOk()->assertSee('Temukan saham')
            ->assertSee('bukan rekomendasi beli atau jual')
            ->assertSee('0 perusahaan ditemukan')->assertViewHas('rows', []);
    }
}
