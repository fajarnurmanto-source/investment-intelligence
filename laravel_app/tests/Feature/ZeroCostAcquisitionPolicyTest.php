<?php

namespace Tests\Feature;

use App\Services\ZeroCostAcquisitionPolicy;
use App\Services\IdxHistoricalSnapshotSource;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Psr7\Request;
use Tests\TestCase;

final class ZeroCostAcquisitionPolicyTest extends TestCase
{
    public function test_production_policy_allows_only_exact_public_read_endpoints(): void
    {
        $policy = new ZeroCostAcquisitionPolicy();
        foreach ([IdxHistoricalSnapshotSource::RAW_URL,
            'https://fred.stlouisfed.org/graph/fredgraph.csv?id=CPIAUCSL',
            'https://data.sec.gov/api/xbrl/companyfacts/CIK0000320193.json'] as $url) {
            $policy->assertAllowed(new Request('GET', $url));
            $this->addToAssertionCount(1);
        }
        foreach ([
            new Request('GET', 'https://api.twelvedata.com/time_series'),
            new Request('GET', 'https://api.marketdata.app/v1/stocks/quotes/AAPL/'),
            new Request('GET', 'https://unknown.example/api'),
            new Request('GET', 'https://fred.stlouisfed.org/graph/fredgraph.csv?id=CPIAUCSL&apikey=secret'),
            new Request('POST', IdxHistoricalSnapshotSource::RAW_URL),
            new Request('GET', IdxHistoricalSnapshotSource::RAW_URL, ['Authorization' => 'Bearer fixture']),
            new Request('GET', 'https://raw.githubusercontent.com/wildangunawan/Dataset-Saham-IDX/master/Saham/LQ45/BBCA.csv'),
        ] as $request) {
            try {
                $policy->assertAllowed($request);
                $this->fail('Unexpected cost eligibility');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('II_ZERO_COST_', $e->getMessage());
            }
        }
    }

    public function test_http_transport_denies_unknown_and_keeps_public_source_available(): void
    {
        $this->app->instance(ZeroCostAcquisitionPolicy::class, new ZeroCostAcquisitionPolicy());
        Http::fake(['*' => Http::response('fixture')]);
        try {
            Http::get('https://api.twelvedata.com/time_series');
            $this->fail('Paid-capable endpoint reached');
        } catch (\RuntimeException $e) {
            $this->assertSame('II_ZERO_COST_PROVIDER_UNVERIFIED', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame('fixture', Http::get(IdxHistoricalSnapshotSource::RAW_URL)->body());
        Http::assertSentCount(1);
    }
}
