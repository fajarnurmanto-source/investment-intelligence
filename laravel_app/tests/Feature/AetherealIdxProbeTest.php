<?php

namespace Tests\Feature;

use App\Services\AetherealIdxProbe;
use App\Services\ZeroCostAcquisitionPolicy;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AetherealIdxProbeTest extends TestCase
{
    public function test_local_file_diagnostic_and_network_denial(): void
    {
        $this->app->instance(ZeroCostAcquisitionPolicy::class, new ZeroCostAcquisitionPolicy());
        Http::fake();
        $file = tempnam(sys_get_temp_dir(), 'ii-aethereal-');
        try {
            file_put_contents($file, "date,ticker,open,high,low,close,adj_close,volume\n2024-01-01,BBCA,100,102,99,101,98,1000\n2024-01-02,BBCA,101,102,99,100,,0\n2024-01-03,BBCA,101,99,98,100,100,10\n");
            $result = app(AetherealIdxProbe::class)->inspectFile($file);
            $this->assertSame(3, $result['rows']);
            $this->assertSame(1, $result['invalid_rows']);
            $this->assertSame(1, $result['adjusted_close_missing']);
            $this->assertSame(1, $result['adjusted_close_differs']);
            $this->assertSame(1, $result['zero_volume_rows']);
            $this->assertSame(hash_file('sha256', $file), $result['sha256']);
            $this->assertFalse($result['admitted']);
            $this->artisan('ii:probe-aethereal-idx', ['--rights-confirmed'=>true])->assertFailed();
            Http::assertNothingSent();
        } finally {
            unlink($file);
        }
    }
}
