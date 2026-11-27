<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Existing connector contract tests exercise synthetic HTTP payloads.
        // Never allow those fixtures to escape to an actual network.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->app->instance(\App\Services\ZeroCostAcquisitionPolicy::class,
            new class extends \App\Services\ZeroCostAcquisitionPolicy {
                public function assertAllowed(\Psr\Http\Message\RequestInterface $request): void {}
            });
    }
}
