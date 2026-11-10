<?php

namespace Tests\Feature;

use App\Models\IssuerMarketPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketPriceBatchV3Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function source(): array
    {
        return [
            'source_name' => 'Licensed synthetic EOD fixture',
            'publisher' => 'Test publisher',
            'source_url' => 'https://example.test/licensed/eod',
            'license_status' => 'licensed',
            'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider',
            'provenance_confidence' => 'high',
            'rights_confirmed' => '1',
        ];
    }

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic-eod.csv',
            "ticker,observed_on,currency,price,adjustment_status\n".implode("\n", $rows)."\n");
    }

    public function test_licensed_csv_batch_import_is_atomic_provenanced_and_user_scoped(): void
    {
        Storage::fake('local');
        $analyst = $this->analyst();
        $other = $this->analyst();
        $file = $this->csv([
            'AAA.JK,2025-01-02,IDR,1000.50,unadjusted_close',
            'BBB.JK,2025-01-02,IDR,350.00,split_adjusted',
            'CCC.JK,2025-01-03,IDR,20,unadjusted',
        ]);
        $sha256 = hash_file('sha256', $file->getRealPath());

        $this->actingAs($analyst)->post('/ii/fundamentals/market-prices/batch', array_merge($this->source(), [
            'market_prices_csv' => $file,
        ]))->assertRedirect('/ii/fundamentals')->assertSessionHas('fundamental_notice');

        $this->assertDatabaseCount('ii_issuer_market_prices', 3);
        $this->assertSame(3, IssuerMarketPrice::where('user_id', $analyst->id)->where('batch_sha256', $sha256)->count());
        $this->assertDatabaseHas('ii_issuer_market_prices', [
            'user_id' => $analyst->id, 'issuer_code' => 'AAA.JK',
            'adjustment_status' => 'UNADJUSTED_CLOSE', 'usage_rights' => 'licensed_internal',
            'batch_sha256' => $sha256, 'batch_original_filename' => 'synthetic-eod.csv',
        ]);
        Storage::disk('local')->assertExists('ii_price_batches/u'.$analyst->id.'/'.$sha256.'.csv');

        $this->actingAs($other)->get('/ii/fundamentals')
            ->assertOk()->assertDontSee('Licensed synthetic EOD fixture');
        $this->actingAs($analyst)->get('/ii/fundamentals')
            ->assertOk()->assertSee('Licensed synthetic EOD fixture')
            ->assertSee(substr($sha256, 0, 16));

        $retry = $this->csv([
            'AAA.JK,2025-01-02,IDR,1000.50,unadjusted_close',
            'BBB.JK,2025-01-02,IDR,350.00,split_adjusted',
            'CCC.JK,2025-01-03,IDR,20,unadjusted',
        ]);
        $this->actingAs($analyst)->post('/ii/fundamentals/market-prices/batch', array_merge($this->source(), [
            'market_prices_csv' => $retry,
        ]))->assertRedirect()->assertSessionHasErrors('market_prices_csv');
        $this->assertDatabaseCount('ii_issuer_market_prices', 3);
    }

    public function test_invalid_price_row_rolls_back_all_siblings_and_storage(): void
    {
        Storage::fake('local');
        $analyst = $this->analyst();
        $file = $this->csv([
            'AAA.JK,2025-01-02,IDR,100,unadjusted_close',
            'BBB.JK,2025-01-03,IDR,200,unadjusted_close',
        ]);
        $this->actingAs($analyst)->post('/ii/fundamentals/market-prices', array_merge($this->source(), [
            'ticker' => 'BBB.JK', 'observed_on' => '2025-01-03', 'currency' => 'IDR', 'price' => '200',
            'unit' => 'per_share', 'adjustment_status' => 'unadjusted_close',
        ]))->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseCount('ii_issuer_market_prices', 1);

        $sha256 = hash_file('sha256', $file->getRealPath());
        $this->actingAs($analyst)->post('/ii/fundamentals/market-prices/batch', array_merge($this->source(), [
            'market_prices_csv' => $file,
        ]))->assertRedirect()->assertSessionHasErrors('market_prices_csv');
        $this->assertDatabaseCount('ii_issuer_market_prices', 1);
        Storage::disk('local')->assertMissing('ii_price_batches/u'.$analyst->id.'/'.$sha256.'.csv');
    }

    public function test_bad_dates_duplicate_rows_and_rights_missing_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        $analyst = $this->analyst();
        foreach ([
            ['AAA.JK,2099-01-01,IDR,100,unadjusted_close'],
            ['AAA.JK,2025-01-02,IDR,100,unadjusted_close', 'AAA.JK,2025-01-02,IDR,200,unadjusted_close'],
            ['AAA.JK,2025-01-02,IDR,=10+20,unadjusted_close'],
        ] as $rows) {
            $this->actingAs($analyst)->post('/ii/fundamentals/market-prices/batch', array_merge($this->source(), [
                'market_prices_csv' => $this->csv($rows),
            ]))->assertRedirect()->assertSessionHasErrors('market_prices_csv');
        }
        $noRights = $this->source();
        unset($noRights['rights_confirmed']);
        $this->actingAs($analyst)->post('/ii/fundamentals/market-prices/batch', array_merge($noRights, [
            'market_prices_csv' => $this->csv(['AAA.JK,2025-01-02,IDR,100,unadjusted_close']),
        ]))->assertRedirect()->assertSessionHasErrors();

        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('ii_price_batches'));
    }
}
