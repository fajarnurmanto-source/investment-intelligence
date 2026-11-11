<?php

namespace Tests\Feature;

use App\Models\IssuerMarketPrice;
use App\Models\User;
use App\Services\MarketPriceQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketPriceQualityReviewV7Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function addPrice(User $user, string $ticker, string $price, string $source, string $date = '2025-01-31', string $basis = 'UNADJUSTED_CLOSE'): void
    {
        IssuerMarketPrice::create([
            'user_id' => $user->id, 'issuer_code' => $ticker, 'observed_on' => $date,
            'currency' => 'IDR', 'price' => $price, 'unit' => 'per_share',
            'adjustment_status' => $basis, 'source_name' => $source, 'publisher' => 'Fixture',
            'source_url' => 'https://example.test/'.rawurlencode($source),
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'LICENSED_PROVIDER',
            'provenance_confidence' => 'HIGH', 'redistribution_allowed' => false,
            'sha256' => hash('sha256', $user->id.'|'.$ticker.'|'.$source.'|'.$price),
            'imported_at' => now(),
        ]);
    }

    public function test_market_quality_review_exposes_conflicting_sources_only_to_owner(): void
    {
        $owner = $this->analyst();
        $other = $this->analyst();

        $this->addPrice($owner, 'QA.JK', '100', 'Licensed Vendor A');
        $this->addPrice($owner, 'QA.JK', '101', 'Licensed Vendor B');
        $this->addPrice($other, 'QA.JK', '777', 'Other user secret');
        $this->addPrice($owner, 'QA.JK', '1', 'Older licensed source', '2024-12-31');

        $quality = app(MarketPriceQualityService::class)->latestByTicker((int) $owner->id);
        $this->assertTrue($quality['QA.JK']['conflict']);
        $this->assertSame(2, $quality['QA.JK']['source_count']);
        $this->assertSame('N/A_MARKET_PRICE_CONFLICT', $quality['QA.JK']['status']);
        $this->assertNotContains('Older licensed source', array_column($quality['QA.JK']['sources'], 'name'));

        $this->actingAs($owner)->get('/ii/fundamentals?workspace=prices')
            ->assertOk()
            ->assertSee('Review kualitas harga terbaru')
            ->assertSee('N/A_MARKET_PRICE_CONFLICT')
            ->assertSee('Licensed Vendor A')
            ->assertSee('Licensed Vendor B')
            ->assertDontSee('Other user secret')
            ->assertSee('HOLD');

        $this->actingAs($other)->get('/ii/fundamentals?workspace=prices')
            ->assertOk()->assertSee('Other user secret')
            ->assertDontSee('Licensed Vendor A')->assertDontSee('Licensed Vendor B');
    }

    public function test_latest_date_matching_vendors_remain_consistent_while_adjusted_and_future_rows_are_flagged(): void
    {
        $owner = $this->analyst();

        $this->addPrice($owner, 'MATCH.JK', '100', 'Vendor One');
        $this->addPrice($owner, 'MATCH.JK', '100.000000', 'Vendor Two', basis: 'UNADJUSTED');
        $this->addPrice($owner, 'ADJUSTED.JK', '90', 'Adjusted Vendor', basis: 'SPLIT_ADJUSTED');
        $this->addPrice($owner, 'FUTURE.JK', '80', 'Future Vendor', date: now()->addDay()->toDateString());

        $quality = app(MarketPriceQualityService::class)->latestByTicker((int) $owner->id);

        $this->assertFalse($quality['MATCH.JK']['conflict']);
        $this->assertSame('CONSISTENT_REVIEW_REQUIRED', $quality['MATCH.JK']['status']);
        $this->assertSame('N/A_SPLIT_ADJUSTMENT_UNALIGNED', $quality['ADJUSTED.JK']['status']);
        $this->assertSame('N/A_FUTURE_MARKET_PRICE_DATE', $quality['FUTURE.JK']['status']);

        $this->actingAs($owner)->get('/ii/fundamentals?workspace=prices')
            ->assertOk()
            ->assertSee('CONSISTENT_REVIEW_REQUIRED')
            ->assertSee('N/A_SPLIT_ADJUSTMENT_UNALIGNED')
            ->assertSee('N/A_FUTURE_MARKET_PRICE_DATE');
    }
}
