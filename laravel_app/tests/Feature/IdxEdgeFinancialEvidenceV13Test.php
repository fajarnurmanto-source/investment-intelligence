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
        $this->assertArrayNotHasKey('raw_response_body', $data);

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

            return Http::response(json_encode($this->fixture($ticker), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, ['Content-Type' => 'application/json']);
        });

        $this->assertSame(0, Artisan::call('ii:idxedge:pilot'));
        foreach (['BBCA', 'TLKM', 'ASII'] as $ticker) {
            $files = Storage::disk('local')->allFiles('ii_idxedge_review/'.$ticker);
            $this->assertCount(2, $files);
            $manifestPaths = array_values(array_filter($files, fn (string $p) => str_ends_with($p, '.json')));
            $rawPaths = array_values(array_filter($files, fn (string $p) => str_ends_with($p, '.raw')));
            $this->assertCount(1, $manifestPaths);
            $this->assertCount(1, $rawPaths);
            $stored = json_decode(Storage::disk('local')->get($manifestPaths[0]), true, 64, JSON_THROW_ON_ERROR);
            $raw = Storage::disk('local')->get($rawPaths[0]);
            $this->assertSame($ticker, $stored['ticker_requested']);
            $this->assertSame('SCHEMA_REVIEW_REQUIRED', $stored['status']);
            $this->assertSame($stored['source_sha256'], hash('sha256', $raw));
            $this->assertSame($rawPaths[0], $stored['raw_private_path']);
            $this->assertTrue($stored['raw_hash_verified']);
            $this->assertArrayNotHasKey('raw_response_body', $stored);
            $this->assertSame($this->fixture($ticker), json_decode($raw, true));
            $this->assertSame(json_encode($this->fixture($ticker), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $raw);
        }
        Http::assertSentCount(3);
        $this->assertSame(0, Artisan::call('ii:idxedge:pilot', ['--ticker' => 'BBCA']));
        $this->assertCount(2, Storage::disk('local')->allFiles('ii_idxedge_review/BBCA'));
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }
    public function test_legacy_manifest_cannot_be_silently_promoted_to_verified_raw_evidence(): void
    {
        Storage::fake('local');
        config()->set('ii.idxedge.key', 'test-provider-key');
        Http::preventStrayRequests();

        $body = json_encode($this->fixture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $body);
        $prefix = 'ii_idxedge_review/BBCA/'.$hash;
        Storage::disk('local')->put($prefix.'.json', '{"source_sha256":"'.$hash.'","status":"SCHEMA_REVIEW_REQUIRED"}');
        Http::fake(['https://stock.arjum.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame(1, Artisan::call('ii:idxedge:pilot', ['--ticker' => 'BBCA']));
        $this->assertFalse(Storage::disk('local')->exists($prefix.'.raw'));
        $this->assertSame('{"source_sha256":"'.$hash.'","status":"SCHEMA_REVIEW_REQUIRED"}', Storage::disk('local')->get($prefix.'.json'));
        $this->assertDatabaseCount('ii_issuer_periods', 0);
    }

    public function test_tampered_existing_raw_archive_fails_closed_without_overwrite(): void
    {
        Storage::fake('local');
        config()->set('ii.idxedge.key', 'test-provider-key');
        Http::preventStrayRequests();

        $body = json_encode($this->fixture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $body);
        $prefix = 'ii_idxedge_review/BBCA/'.$hash;
        Storage::disk('local')->put($prefix.'.raw', '{"tampered":true}');
        Http::fake(['https://stock.arjum.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame(1, Artisan::call('ii:idxedge:pilot', ['--ticker' => 'BBCA']));
        $this->assertSame('{"tampered":true}', Storage::disk('local')->get($prefix.'.raw'));
        $this->assertFalse(Storage::disk('local')->exists($prefix.'.json'));
        $this->assertDatabaseCount('ii_issuer_periods', 0);
    }

    public function test_raw_disk_write_failure_leaves_no_partial_manifest_or_numeric_import(): void
    {
        Storage::fake('local');
        config()->set('ii.idxedge.key', 'test-provider-key');
        Http::preventStrayRequests();
        $body = json_encode($this->fixture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $body);
        $prefix = 'ii_idxedge_review/BBCA/'.$hash;
        Http::fake(['https://stock.arjum.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->once()->with($prefix.'.raw', $body)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->assertSame(1, Artisan::call('ii:idxedge:pilot', ['--ticker' => 'BBCA']));
        $this->assertFalse($disk->exists($prefix.'.raw'));
        $this->assertFalse($disk->exists($prefix.'.json'));
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }

    public function test_manifest_write_failure_rolls_back_only_newly_created_raw_evidence(): void
    {
        Storage::fake('local');
        config()->set('ii.idxedge.key', 'test-provider-key');
        Http::preventStrayRequests();
        $body = json_encode($this->fixture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $body);
        $prefix = 'ii_idxedge_review/BBCA/'.$hash;
        Http::fake(['https://stock.arjum.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->once()->with($prefix.'.json', \Mockery::type('string'))->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->assertSame(1, Artisan::call('ii:idxedge:pilot', ['--ticker' => 'BBCA']));
        $this->assertFalse($disk->exists($prefix.'.raw'));
        $this->assertFalse($disk->exists($prefix.'.json'));
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }

}
