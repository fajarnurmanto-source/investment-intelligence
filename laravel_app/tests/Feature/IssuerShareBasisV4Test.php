<?php

namespace Tests\Feature;

use App\Models\IssuerCorporateAction;
use App\Models\IssuerShareBasisReview;
use App\Models\User;
use App\Services\FundamentalSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IssuerShareBasisV4Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function importIssuer(User $user, string $ticker = 'BASIS.JK'): void
    {
        $csv = "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n"
            ."2023,2023-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n"
            ."2024,2024-12-31,false,120,12,550,320,270,60,1000,110,25,45,,\n";
        $this->actingAs($user)->post('/ii/fundamentals/import', [
            'ticker' => $ticker, 'issuer_name' => 'Share Basis Example', 'sector' => 'Industrials',
            'kind' => 'user_supplied', 'report_period' => 'FY2024', 'fiscal_period_type' => 'annual',
            'report_date' => '2025-03-01', 'currency' => 'IDR', 'multiplier' => '1', 'consolidated' => '1',
            'source_name' => 'Synthetic annual report', 'publisher' => 'Example',
            'source_url' => 'https://example.test/annual', 'license_status' => 'licensed',
            'usage_rights' => 'licensed_internal', 'source_authenticity_status' => 'licensed_provider',
            'numeric_reconciliation_status' => 'verified_against_source', 'provenance_confidence' => 'high',
            'rights_confirmed' => '1',
            'fundamental_file' => UploadedFile::fake()->createWithContent('basis.csv', $csv),
        ])->assertRedirect('/ii/fundamentals');
    }

    private function importPrice(User $user, string $status = 'unadjusted_close'): void
    {
        $this->actingAs($user)->post('/ii/fundamentals/market-prices', [
            'ticker' => 'BASIS.JK', 'observed_on' => '2025-01-31', 'currency' => 'IDR',
            'price' => '100', 'unit' => 'per_share', 'adjustment_status' => $status,
            'source_name' => 'Licensed raw price', 'publisher' => 'Example',
            'source_url' => 'https://example.test/price', 'license_status' => 'licensed',
            'usage_rights' => 'licensed_internal', 'source_authenticity_status' => 'licensed_provider',
            'provenance_confidence' => 'high', 'rights_confirmed' => '1',
        ])->assertRedirect('/ii/fundamentals');
    }

    private function action(string $date = '2025-01-15', string $type = 'split'): array
    {
        return [
            'ticker' => 'BASIS.JK', 'effective_on' => $date, 'action_type' => $type,
            'new_shares' => '2', 'old_shares' => '1', 'source_name' => 'Issuer CA notice',
            'source_url' => 'https://example.test/corporate-action',
            'usage_rights' => 'licensed_internal', 'provenance_confidence' => 'high',
            'rights_confirmed' => '1',
        ];
    }

    private function review(): array
    {
        return [
            'ticker' => 'BASIS.JK', 'period_end' => '2024-12-31',
            'price_observed_on' => '2025-01-31', 'source_name' => 'Complete issuer CA register review',
            'source_url' => 'https://example.test/complete-ca-register',
            'usage_rights' => 'licensed_internal', 'provenance_confidence' => 'high',
            'coverage_confirmed' => '1', 'rights_confirmed' => '1',
        ];
    }

    public function test_raw_price_requires_review_and_backdated_event_invalidates_prior_review(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = $this->analyst();
        $this->importIssuer($owner);
        $this->importPrice($owner);

        $snapshots = app(FundamentalSnapshotService::class);
        $unreviewed = $snapshots->latestByTicker((int) $owner->id)['BASIS.JK'];
        $this->assertSame('N/A_SHARE_BASIS_REVIEW_MISSING', $unreviewed['valuation_status']);
        $this->assertNull($unreviewed['pe_ratio']);

        $this->actingAs($owner)->post('/ii/fundamentals/corporate-actions', $this->action())
            ->assertRedirect('/ii/fundamentals');
        $this->actingAs($owner)->post('/ii/fundamentals/share-basis/reviews', $this->review())
            ->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseCount('ii_issuer_corporate_actions', 1);
        $this->assertDatabaseCount('ii_issuer_share_basis_reviews', 1);

        $aligned = $snapshots->latestByTicker((int) $owner->id)['BASIS.JK'];
        $this->assertSame('ALIGNED_USER_ATTESTED', $aligned['share_basis_status']);
        $this->assertSame(2.0, $aligned['share_basis_factor']);
        $this->assertSame('AVAILABLE_USER_RECONCILED_AS_OF_2025-01-31', $aligned['valuation_status']);
        $this->assertSame(2000.0, $aligned['valuation_shares_outstanding']);
        $this->assertNotNull($aligned['pe_ratio']);
        $this->assertSame('HOLD', $aligned['decision']);
        $this->assertSame('NONE', $aligned['trading_authority']);

        $this->actingAs($owner)->post('/ii/fundamentals/corporate-actions', $this->action('2025-01-20', 'bonus_issue'))
            ->assertRedirect('/ii/fundamentals');
        $stale = $snapshots->latestByTicker((int) $owner->id)['BASIS.JK'];
        $this->assertSame('N/A_SHARE_BASIS_REVIEW_STALE', $stale['valuation_status']);
        $this->assertNull($stale['pe_ratio']);
        $this->assertNull($stale['ev_ebitda_ratio']);

        $duplicate = $this->actingAs($owner)->post('/ii/fundamentals/corporate-actions', $this->action());
        $duplicate->assertSessionHasErrors('corporate_action');
        $this->assertSame(2, IssuerCorporateAction::where('user_id', $owner->id)->count());
    }

    public function test_user_scoping_and_split_adjusted_price_hold_even_with_review(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = $this->analyst();
        $other = $this->analyst();
        $this->importIssuer($owner);
        $this->importPrice($owner, 'split_adjusted');

        $this->actingAs($other)->post('/ii/fundamentals/share-basis/reviews', $this->review())
            ->assertSessionHasErrors('share_basis');
        $this->assertDatabaseCount('ii_issuer_share_basis_reviews', 0);

        $this->actingAs($owner)->post('/ii/fundamentals/corporate-actions', $this->action())
            ->assertRedirect('/ii/fundamentals');
        $this->actingAs($owner)->post('/ii/fundamentals/share-basis/reviews', $this->review())
            ->assertRedirect('/ii/fundamentals');
        $held = app(FundamentalSnapshotService::class)->latestByTicker((int) $owner->id)['BASIS.JK'];
        $this->assertSame('N/A_SPLIT_ADJUSTMENT_UNALIGNED', $held['valuation_status']);
        $this->assertNull($held['pbv_ratio']);

        $this->actingAs($other)->get('/ii/fundamentals')->assertOk()
            ->assertDontSee('Complete issuer CA register review');
        $this->actingAs($owner)->get('/ii/fundamentals')->assertOk()
            ->assertSee('Complete issuer CA register review');
        $this->assertSame(1, IssuerShareBasisReview::where('user_id', $owner->id)->count());
    }
}
