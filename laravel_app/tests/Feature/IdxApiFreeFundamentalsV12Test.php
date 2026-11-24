<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdxApiFreeFundamentalsV12Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    /** Synthetic network fixtures—not real IDX statement data. */
    private function fakeThree(int $year = 2024, string $symbol = 'BBCA', string $accession = 'IDX-FILING-2024'): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use ($year, $symbol, $accession) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $data = match ($path) {
                '/v1/income-statement' => ['Revenues' => 120, 'ProfitLossAttributableToOwnersOfParent' => 10],
                '/v1/balance-sheet-statement' => ['Assets' => 500, 'Equity' => 300],
                '/v1/cash-flow-statement' => ['NetCashFlowsReceivedFromUsedInOperatingActivities' => 50],
                default => throw new \LogicException('Unexpected API endpoint: '.$path),
            };

            return Http::response([[
                'symbol' => $symbol,
                'calendarYear' => $year,
                'period' => 'FY',
                'filingDate' => '2025-03-15',
                'labelsVersion' => 'idx',
                'reportedCurrency' => 'IDR',
                'reportingScale' => 'million',
                'scaleMultiplier' => 1000000,
                'data' => $data,
                'provenance' => ['accessionNumber' => $accession],
            ]], 200, ['Content-Type' => 'application/json']);
        });
    }

    public function test_free_tier_fetches_three_annual_statements_through_fixed_provider_only_and_never_persists_db(): void
    {
        config()->set('ii.idxapi.key', 'test-free-key');
        $this->fakeThree();
        $owner = $this->analyst();

        $response = $this->actingAs($owner)->post('/ii/fundamentals/idxapi/free-review', [
            'idxapi_ticker' => 'bbca',
            'idxapi_year' => '2024',
        ])->assertOk()->assertHeader('X-II-Review-Required', 'true')
            ->assertHeader('X-II-Source-Origin', 'IDXAPI_THIRD_PARTY_FREE')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('II_IDXAPI_BBCA_FY2024_REVIEW.json', $response->headers->get('Content-Disposition'));

        $data = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('BBCA', $data['ticker']);
        $this->assertSame('IDXAPI_FREE_TIER', $data['origin']);
        $this->assertSame(3, $data['api_requests']);
        $this->assertSame('IDR', $data['currency']);
        $this->assertSame('1000000', $data['scale_multiplier']);
        $this->assertSame('IDX-FILING-2024', $data['accession']);
        $this->assertSame(120, $data['statements']['income']['reported_line_items']['Revenues']);
        $this->assertSame(500, $data['statements']['balance']['reported_line_items']['Assets']);
        $this->assertSame(50, $data['statements']['cash_flow']['reported_line_items']['NetCashFlowsReceivedFromUsedInOperatingActivities']);
        $this->assertSame(64, strlen($data['statements']['income']['source_sha256']));
        $this->assertSame('REVIEW_REQUIRED', $data['status']);
        $this->assertSame('NOT_ADMITTED', $data['gate']);
        $this->assertSame('HOLD', $data['decision']);
        $this->assertSame('NONE', $data['trading_authority']);
        $this->assertFalse($data['automatic_database_import']);
        $this->assertFalse($data['redistribution_allowed']);
        $this->assertStringNotContainsString('test-free-key', $response->getContent());
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), 'https://api.idxapi.io/v1/')
            && $request->hasHeader('Authorization', 'Bearer test-free-key')
            && str_contains($request->url(), 'symbol=BBCA')
            && str_contains($request->url(), 'calendarYear=2024')
            && str_contains($request->url(), 'fiscalPeriod=FY')
        );
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }

    public function test_missing_key_and_guest_never_contact_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config()->set('ii.idxapi.key', '');
        $this->post('/ii/fundamentals/idxapi/free-review', ['idxapi_ticker' => 'BBCA', 'idxapi_year' => 2024])
            ->assertRedirect('/login');
        $owner = $this->analyst();
        $this->actingAs($owner)->post('/ii/fundamentals/idxapi/free-review', [
            'idxapi_ticker' => 'BBCA', 'idxapi_year' => '2024',
        ])->assertRedirect('/ii/fundamentals?workspace=activation')
            ->assertSessionHasErrors('idxapi_ticker');
        Http::assertNothingSent();
    }

    public function test_provider_quota_and_mismatched_filing_identity_fail_closed(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('ii.idxapi.key', 'test-free-key');
        $owner = $this->analyst();
        Http::preventStrayRequests();
        Http::fake(['https://api.idxapi.io/*' => Http::response('', 429)]);
        $this->actingAs($owner)->post('/ii/fundamentals/idxapi/free-review', [
            'idxapi_ticker' => 'BBCA', 'idxapi_year' => '2024',
        ])->assertRedirect('/ii/fundamentals?workspace=activation')->assertSessionHasErrors();
        Http::assertSentCount(1);

        // Faking mismatched issuer prevents any old or new data being promoted.
        $this->fakeThree(symbol: 'GOTO');
        $this->actingAs($owner)->post('/ii/fundamentals/idxapi/free-review', [
            'idxapi_ticker' => 'BBCA', 'idxapi_year' => '2024',
        ])->assertRedirect('/ii/fundamentals?workspace=activation')->assertSessionHasErrors();
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
    }

    public function test_automatic_opt_in_command_archives_provenance_without_database_writes(): void
    {
        Storage::fake('local');
        config()->set('ii.idxapi.key', 'test-free-key');
        config()->set('ii.idxapi.tickers', 'BBCA');
        config()->set('ii.idxapi.auto_sync', true);
        $this->fakeThree();

        $this->assertSame(0, Artisan::call('ii:idxapi:sync', ['--year' => '2024']));
        $files = Storage::disk('local')->allFiles('ii_idxapi_review/FY2024');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('~/BBCA-[a-f0-9]{64}\.json$~', $files[0]);
        $saved = json_decode(Storage::disk('local')->get($files[0]), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('REVIEW_REQUIRED', $saved['status']);
        $this->assertSame('IDXAPI_FREE_TIER', $saved['origin']);
        $this->assertSame(3, count($saved['statements']));
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);

        // Same source payload is deduplicated as an immutable private artifact.
        $this->assertSame(0, Artisan::call('ii:idxapi:sync', ['--ticker' => 'BBCA', '--year' => '2024']));
        $this->assertCount(1, Storage::disk('local')->allFiles('ii_idxapi_review/FY2024'));
    }
}
