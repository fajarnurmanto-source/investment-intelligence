<?php

namespace Tests\Feature;

use App\Services\MarketProviderCreditGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Tests\TestCase;

final class MarketProviderCreditGuardTest extends TestCase
{
    public function test_observed_exhaustion_is_owner_scoped_and_expires(): void
    {
        config(['cache.default' => 'array']);
        $guard = app(MarketProviderCreditGuard::class);
        $this->assertNull($guard->remaining(1));
        $guard->observe(1, new Response(new PsrResponse(200, ['api-credits-left' => '0'])));
        $this->assertSame(0, $guard->remaining(1));
        $this->assertNull($guard->remaining(2));
        try {
            $guard->assertAvailable(1);
            $this->fail('Exhausted budget accepted');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('II_PROVIDER_RATE_LIMITED', $exception->getMessage());
        }
        $guard->assertAvailable(2);
        $this->travel(61)->seconds();
        $this->assertNull($guard->remaining(1));
    }

    public function test_malformed_headers_do_not_replace_valid_observation(): void
    {
        config(['cache.default' => 'array']);
        $guard = app(MarketProviderCreditGuard::class);
        $guard->observe(1, new Response(new PsrResponse(200, ['api-credits-left' => '4'])));
        $guard->observe(1, new Response(new PsrResponse(200, ['api-credits-left' => 'unknown'])));
        $this->assertSame(4, $guard->remaining(1));
        $guard->observe(1, new Response(new PsrResponse(429)));
        $this->assertSame(0, $guard->remaining(1));
    }
}
