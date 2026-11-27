<?php

namespace Tests\Feature;

use App\Services\MarketSeriesReconciliation;
use App\Services\TwelveDataDailyAdapter;
use Tests\TestCase;

final class MarketSeriesReconciliationTest extends TestCase
{
    private function document(): array
    {
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = ['date' => (new \DateTimeImmutable('2024-01-01'))->modify('+'.$i.' days')->format('Y-m-d'),
                'open' => 100, 'high' => 102, 'low' => 98, 'close' => 101, 'volume' => 1000];
        }
        return ['ticker' => 'AAPL', 'kind' => 'user_supplied', 'provider_identity' => 'AAPL@XNAS',
            'currency' => 'USD', 'session_timezone' => 'America/New_York', 'price_adjustment' => 'unadjusted',
            'lineage_source_status' => 'REGISTERED', 'acquisition_mode' => 'LICENSED_PROVIDER_API',
            'source_name' => 'Twelve Data', 'source_url' => TwelveDataDailyAdapter::URL, 'rows' => $rows];
    }

    public function test_agreement_is_not_independent_and_conflict_is_not_averaged(): void
    {
        $service = app(MarketSeriesReconciliation::class);
        $a = $this->document();
        $b = $a;
        $result = $service->compare($a, $b);
        $this->assertSame('AGREEMENT_NOT_INDEPENDENTLY_VERIFIED', $result['status']);
        $this->assertSame('SAME_SOURCE', $result['independence']);
        $this->assertSame(60, $result['overlap_sessions']);
        $b['rows'][59]['close'] = 100;
        $result = $service->compare($a, $b);
        $this->assertSame('CONFLICT', $result['status']);
        $this->assertSame(1, $result['conflict_sessions']);
        $this->assertSame(1, $result['conflict_fields']);
        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['trading_authorized']);
    }

    public function test_unknown_basis_identity_and_lineage_fail_closed(): void
    {
        $service = app(MarketSeriesReconciliation::class);
        $a = $this->document();
        $this->assertSame('METADATA_UNKNOWN_OR_MISMATCH', $service->compare($a, array_replace($a, ['price_adjustment' => null]))['reason']);
        $this->assertSame('INSTRUMENT_IDENTITY_MISMATCH', $service->compare($a, array_replace($a, ['provider_identity' => 'AAPL@XNYS']))['reason']);
        $this->assertSame('INTEGRITY_OR_LINEAGE_REQUIRED', $service->compare($a, array_replace($a, ['lineage_source_status' => 'UNREGISTERED']))['reason']);
    }
}
