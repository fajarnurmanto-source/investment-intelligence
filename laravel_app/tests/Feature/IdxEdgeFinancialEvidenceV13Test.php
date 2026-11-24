<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdxEdgeFinancialEvidenceV13Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    /** Synthetic response for a contract not yet accepted against a real provider account. */
    private function fixture(string $ticker = 'BBCA'): array
    {
        return [
            'stock_code' => $ticker,
            'data' => [
                ['period' => 'FY 2024', 'revenue' => 120, 'total_assets' => 500],
                ['period' => 'FY 2025', 'revenue' => 130, 'total_assets' => 550],
            ],
        ];
    }

    public function test_authenticated_free_provider_fetch_preserves_raw_evidence_and_never_imports_financials(): void
    {
        config()->set('ii.idxedge.key', 'test-provider-key');
        Http::preventStrayRequests();
        Http::fake(['https://stock.arjum.com/api/financial-statements/BBCA' =>
            Http::response($this->fixture(), 200, [
                'Content-Type' => 'application/json',
                'X-RateLimit-Remaining' => '999',
            ])]);

        $response = $this->actingAs($this->analyst())->post('/ii/fundamentals/idxedge/free-review', [
            'idxedge_ticker' => 'bbca',
        ])->assertOk()->assertHeader('X-II-Review-Required', 'true')
            ->assertHeader('X-II-Source-Origin', 'IDXEDGE_THIRD_PARTY_FREE')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('II_IDXEDGE_BBCA_REVIEW.json', $response->headers->get('Content-Disposition'));

        $data = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('BBCA', $data['ticker_requested']);
        $this->assertSame('MATCHED_PROVIDER_CLAIM', $data['identity_status']);
        $this->assertSame('UNVERIFIED', $data['financial_period_status']);
        $this->assertSame('UNVERIFIED', $data['currency_status']);
        $this->assertSame('SCHEMA_REVIEW_REQUIRED', $data['status']);
        $this->assertSame('UNRECONCILED', $data['numeric_reconciliation']);
        $this->assertSame('NOT_ADMITTED', $data['gate']);
        $this->assertSame('HOLD', $data['decision']);
        $this->assertSame('NONE', $data['trading_authority']);
        $this->assertFalse($data['automatic_database_import']);
        $this->assertSame(999, $data['quota_remaining_claim']);
        $this->assertSame($this->fixture(), $data['raw_provider_payload']);
        $this->assertSame(64, strlen($data['source_sha256']));
        $this->assertStringNotContainsString('test-provider-key', $response->getContent());

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://stock.arjum.com/api/financial-statements/BBCA'
            && $request->hasHeader('X-API-Key', 'test-provider-key')
            && $request->hasHeader('Accept', 'application/json'));
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }

    public function test_no_key_and_guest_requests_never_call_external_provider(): void
    {
        config()->set('ii.idxedge.key', '');
        Http::preventStrayRequests();
        Http::fake();

        $this->post('/ii/fundamentals/idxedge/free-review', ['idxedge_ticker' => 'BBCA'])
            ->assertRedirect('/login');
        $this->actingAs($this->analyst())->post('/ii/fundamentals/idxedge/free-review', [
            'idxedge_ticker' => 'BBCA',
        ])->assertRedirect('/ii/fundamentals?workspace=activation')
            ->assertSessionHasErrors('idxedge_ticker');
        Http::assertNothingSent();
    }

    public function test_quota_invalid_content_and_issuer_mismatch_are_rejected_without_data_writes(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('ii.idxedge.key', 'test-provider-key');
        $owner = $this->analyst();
        Http::preventStrayRequests();
        foreach ([
            Http::response('', 429),
            Http::response('<html>oops</html>', 200, ['Content-Type' => 'text/html']),
            Http::response($this->fixture('GOTO'), 200, ['Content-Type' => 'application/json']),
            Http::response(['success' => false, 'error' => 'upstream failed'], 200),
            Http::response(['data' => []], 200, ['Content-Type' => 'application/json']),
        ] as $fake) {
            Http::fake(['https://stock.arjum.com/*' => $fake]);
            $this->actingAs($owner)->post('/ii/fundamentals/idxedge/free-review', [
                'idxedge_ticker' => 'BBCA',
            ])->assertRedirect('/ii/fundamentals?workspace=activation')
                ->assertSessionHasErrors('idxedge_ticker');
        }
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
    }

    public function test_pilot_archives_private_checksum_evidence_for_three_tickers_without_database_import(): void
    {
        Storage::fake('local');
        config()->set('ii.idxedge.key', 'test-provider-key');
        config()->set('ii.idxedge.tickers', 'BBCA,TLKM,ASII');
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $ticker = substr((string) parse_url($request->url(), PHP_URL_PATH), -4);

            return Http::response($this->fixture($ticker), 200, ['Content-Type' => 'application/json']);
        });

        $this->assertSame(0, Artisan::call('ii:idxedge:pilot'));
        foreach (['BBCA', 'TLKM', 'ASII'] as $ticker) {
            $files = Storage::disk('local')->allFiles('ii_idxedge_review/'.$ticker);
            $this->assertCount(1, $files);
            $this->assertMatchesRegularExpression('~/[a-f0-9]{64}\.json$~', $files[0]);
            $stored = json_decode(Storage::disk('local')->get($files[0]), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($ticker, $stored['ticker_requested']);
            $this->assertSame('SCHEMA_REVIEW_REQUIRED', $stored['status']);
        }
        Http::assertSentCount(3);
        $this->assertSame(0, Artisan::call('ii:idxedge:pilot', ['--ticker' => 'BBCA']));
        $this->assertCount(1, Storage::disk('local')->allFiles('ii_idxedge_review/BBCA'));
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }
}
