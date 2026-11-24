<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;
use Tests\TestCase;

class SecOfficialApiIntakeV11Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        return $user;
    }

    private function fixture(int $cik = 320193): array
    {
        $accn = '0000000001-25-000001';
        return [
            'cik' => $cik, 'entityName' => 'Synthetic SEC Network Response',
            'facts' => [
                'us-gaap' => [
                    'RevenueFromContractWithCustomerExcludingAssessedTax' => [
                        'units' => ['USD' => [[
                            'val' => 1222, 'start' => '2024-01-01', 'end' => '2024-12-31',
                            'form' => '10-K', 'fp' => 'FY', 'accn' => $accn, 'filed' => '2025-02-12',
                        ]]],
                    ],
                    'Assets' => ['units' => ['USD' => [[
                        'val' => 4200, 'end' => '2024-12-31', 'form' => '10-K',
                        'fp' => 'FY', 'accn' => $accn, 'filed' => '2025-02-12',
                    ]]]],
                ],
            ],
        ];
    }

    private function requestPayload(array $changes = []): array
    {
        return array_replace([
            'sec_cik' => '320193',
            'sec_contact_email' => 'analyst@example.org',
            'sec_contact_confirmed' => '1',
        ], $changes);
    }

    public function test_official_live_request_uses_declared_user_agent_fixed_host_and_returns_review_csv_without_db_writes(): void
    {
        $url = 'https://data.sec.gov/api/xbrl/companyfacts/CIK0000320193.json';
        Http::preventStrayRequests();
        Http::fake([
            $url => Http::response(json_encode($this->fixture(), JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);
        $user = $this->analyst();

        $r = $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/live', $this->requestPayload())
            ->assertOk()
            ->assertHeader('X-II-Source-Origin', 'SEC_OFFICIAL_API')
            ->assertHeader('X-II-Review-Required', 'true')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('private', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('CIK0000320193_REVIEW.csv', $r->headers->get('Content-Disposition'));
        $this->assertSame(64, strlen((string) $r->headers->get('X-II-Source-Sha256')));
        $r->assertHeader('X-II-Lineage-Status', 'SOURCE_OFFICIAL_REVIEW_REQUIRED')
            ->assertHeader('X-II-Export-Scope', 'LOCAL_REVIEW_ONLY_NOT_CLEARED_FOR_REDISTRIBUTION');
        $this->assertSame(hash('sha256', $r->getContent()),
            $r->headers->get('X-II-Content-SHA256'));
        $registry = app(\App\Services\LocalLineageRegistry::class);
        $nodeId = $r->headers->get('X-II-Lineage-Node');
        $this->assertTrue($registry->verifyTrace($user->id, $nodeId));
        $this->assertCount(2, $registry->trace($user->id, $nodeId)['nodes']);
        $this->assertFalse($registry->allows($user->id, $nodeId, 'export'));


        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === $url
            && $request->method() === 'GET'
            && $request->hasHeader('User-Agent', 'Investment Intelligence Research analyst@example.org')
            && $request->hasHeader('Accept', 'application/json'));

        $reader = Reader::createFromString($r->getContent());
        $reader->setHeaderOffset(0);
        $rows = iterator_to_array($reader->getRecords(), false);
        $this->assertCount(1, $rows);
        $this->assertSame('1222', $rows[0]['revenue']);
        $this->assertSame('4200', $rows[0]['total_assets']);
        $this->assertSame('', $rows[0]['market_price']);
        $this->assertSame('', $rows[0]['ebitda']);

        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }

    public function test_sec_access_denial_and_not_found_are_reported_without_ingesting_any_data(): void
    {
        // Test upstream responses independently of the separately tested
        // route limiter (which correctly blocks request #3).
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = $this->analyst();
        foreach ([403, 429, 404] as $status) {
            Http::preventStrayRequests();
            Http::fake(['https://data.sec.gov/*' => Http::response('', $status)]);
            $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/live', $this->requestPayload())
                ->assertRedirect('/ii/fundamentals?workspace=activation')->assertSessionHasErrors('sec_cik');
        }
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
    }

    public function test_malformed_cik_contact_and_missing_consent_never_trigger_sec_request(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::preventStrayRequests();
        Http::fake();
        $user = $this->analyst();

        foreach ([
            ['sec_cik' => 'oops'],
            ['sec_cik' => '0000000000'],
            ['sec_contact_email' => 'not-an-email'],
            ['sec_contact_confirmed' => '0'],
        ] as $changes) {
            $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/live',
                $this->requestPayload($changes))->assertSessionHasErrors();
        }
        Http::assertNothingSent();
    }

    public function test_mismatched_sec_cik_json_and_non_json_response_fail_closed(): void
    {
        $user = $this->analyst();
        $url = 'https://data.sec.gov/api/xbrl/companyfacts/CIK0000320193.json';
        Http::preventStrayRequests();
        Http::fake([$url => Http::response(json_encode($this->fixture(123456), JSON_THROW_ON_ERROR), 200,
            ['Content-Type' => 'application/json'])]);

        $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/live', $this->requestPayload())
            ->assertRedirect('/ii/fundamentals?workspace=activation')->assertSessionHasErrors('sec_cik');

        Http::fake([$url => Http::response('<html>blocked</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/live', $this->requestPayload())
            ->assertRedirect('/ii/fundamentals?workspace=activation')->assertSessionHasErrors('sec_cik');
        $this->assertDatabaseCount('ii_issuer_documents', 0);
    }

    public function test_anonymous_request_is_rejected_and_user_email_not_saved(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->post('/ii/fundamentals/sec-companyfacts/live', $this->requestPayload())
            ->assertRedirect('/login');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ii_issuer_documents', 0);
    }
}
