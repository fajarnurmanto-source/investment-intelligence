<?php

namespace Tests\Feature;

use App\Models\IssuerMarketPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BulkMarketDataOnboardingV8Test extends TestCase
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
            'source_name' => 'Licensed EOD V8 fixture',
            'publisher' => 'Test Publisher',
            'source_url' => 'https://example.test/provider-agreement',
            'license_status' => 'licensed',
            'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider',
            'provenance_confidence' => 'high',
            'rights_confirmed' => '1',
        ];
    }

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('v8-closing-prices.csv',
            "ticker,observed_on,currency,price,adjustment_status\n".implode("\n", $rows)."\n");
    }

    private function existing(User $user, string $ticker, string $price, string $date): void
    {
        IssuerMarketPrice::create([
            'user_id' => $user->id, 'issuer_code' => $ticker, 'observed_on' => $date,
            'currency' => 'IDR', 'price' => $price, 'unit' => 'per_share',
            'adjustment_status' => 'UNADJUSTED_CLOSE', 'source_name' => 'Previous licensed source',
            'source_url' => 'https://example.test/previous',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'LICENSED_PROVIDER',
            'provenance_confidence' => 'HIGH', 'redistribution_allowed' => false,
            'sha256' => hash('sha256', $user->id.$ticker.$date.$price),
            'imported_at' => now(),
        ]);
    }

    public function test_preview_is_read_only_scoped_and_flags_same_date_conflicts(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = $this->analyst();
        $other = $this->analyst();
        $this->existing($owner, 'AAA.JK', '100', '2025-01-02');
        $this->existing($owner, 'BBB.JK', '199', '2025-01-03');
        $this->existing($other, 'CCC.JK', '1', '2025-01-04');

        $rows = [
            'AAA.JK,2025-01-02,IDR,100,unadjusted_close',
            'BBB.JK,2025-01-03,IDR,200,unadjusted_close',
            'CCC.JK,2025-01-04,IDR,300,unadjusted_close',
        ];
        $sha256 = hash_file('sha256', $this->csv($rows)->getRealPath());

        $this->actingAs($owner)->post('/ii/fundamentals/market-prices/preview', [
            'market_prices_csv' => $this->csv($rows),
        ])->assertRedirect('/ii/fundamentals?workspace=prices')
            ->assertSessionHas('market_price_preview', function (array $preview) use ($sha256): bool {
                return $preview['is_preview'] === true
                    && $preview['rows'] === 3
                    && $preview['tickers'] === 3
                    && $preview['first_date'] === '2025-01-02'
                    && $preview['last_date'] === '2025-01-04'
                    && $preview['overlaps'] === 2
                    && $preview['potential_conflicts'] === 1
                    && $preview['examples'] === ['BBB.JK / 2025-01-03']
                    && $preview['already_imported'] === false
                    && $preview['sha256_prefix'] === substr($sha256, 0, 16);
            });

        $this->assertDatabaseCount('ii_issuer_market_prices', 3);
        $this->assertSame([], Storage::disk('local')->allFiles('ii_price_batches'));

        $this->actingAs($owner)->get('/ii/fundamentals?workspace=prices')
            ->assertOk()->assertSee('Periksa file sebelum mengimpor')
            ->assertSee('Periksa tanpa menyimpan');

        $this->actingAs($other)->post('/ii/fundamentals/market-prices/preview', [
            'market_prices_csv' => $this->csv($rows),
        ])->assertSessionHas('market_price_preview', fn (array $summary): bool =>
            $summary['overlaps'] === 1 && $summary['potential_conflicts'] === 1
        );
    }

    public function test_bulk_import_accepts_over_100_rows_and_rejects_over_1000_atomically(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->analyst();
        $rows = [];
        for ($i = 1; $i <= 125; $i++) {
            $date = (new \DateTimeImmutable('2025-01-01'))->modify('+'.($i - 1).' days')->format('Y-m-d');
            $rows[] = 'BULK.JK,'.$date.',IDR,'.(100 + $i).',unadjusted_close';
        }
        $file = $this->csv($rows);
        $sha = hash_file('sha256', $file->getRealPath());

        $this->actingAs($user)->post('/ii/fundamentals/market-prices/batch',
            array_merge($this->source(), ['market_prices_csv' => $file])
        )->assertRedirect('/ii/fundamentals')->assertSessionHasNoErrors();

        $this->assertSame(125, IssuerMarketPrice::where('user_id', $user->id)->count());
        Storage::disk('local')->assertExists('ii_price_batches/u'.$user->id.'/'.$sha.'.csv');

        $large = [];
        for ($i = 1; $i <= 1001; $i++) {
            $date = (new \DateTimeImmutable('2022-01-01'))->modify('+'.($i - 1).' days')->format('Y-m-d');
            $large[] = 'OVER.JK,'.$date.',IDR,'.(100 + $i).',unadjusted_close';
        }
        $tooLarge = $this->csv($large);
        $badSha = hash_file('sha256', $tooLarge->getRealPath());

        $this->actingAs($user)->post('/ii/fundamentals/market-prices/batch',
            array_merge($this->source(), ['market_prices_csv' => $tooLarge])
        )->assertRedirect()->assertSessionHasErrors('market_prices_csv');

        $this->assertDatabaseCount('ii_issuer_market_prices', 125);
        Storage::disk('local')->assertMissing('ii_price_batches/u'.$user->id.'/'.$badSha.'.csv');
    }

    public function test_preview_rejects_unauthorized_request_and_bad_csv_without_storage(): void
    {
        Storage::fake('local');
        $file = $this->csv(['FAKE.JK,2025-01-02,IDR,=2+2,unadjusted_close']);

        $this->post('/ii/fundamentals/market-prices/preview', [
            'market_prices_csv' => $file,
        ])->assertRedirect();

        $this->actingAs($this->analyst())->post('/ii/fundamentals/market-prices/preview', [
            'market_prices_csv' => $this->csv(['FAKE.JK,2025-01-02,IDR,=2+2,unadjusted_close']),
        ])->assertRedirect('/ii/fundamentals?workspace=prices')->assertSessionHasErrors('market_prices_csv');

        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('ii_price_batches'));
    }
}
