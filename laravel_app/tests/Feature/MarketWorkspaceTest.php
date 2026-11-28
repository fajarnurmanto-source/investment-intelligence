<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarketWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_is_owner_scoped_and_local(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'admin'])->save();
        $other = User::factory()->create();
        $other->forceFill(['ii_research_role' => 'admin'])->save();
        config(['market_feed' => [
            'owner_id' => $owner->id, 'enabled' => false,
            'ticker' => 'AAPL', 'provider' => 'local',
        ]]);
        DB::table('ii_market_acquisition_receipts')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $owner->id,
            'ticker' => 'AAPL', 'provider' => 'local', 'status' => 'WAITING_FOR_FEED',
            'started_at' => now('UTC'), 'completed_at' => now('UTC'),
        ]);
        $this->actingAs($owner)->get('/ii/market-data')->assertOk()
            ->assertSee('AAPL')->assertSee('NO_DATA')->assertSee('WAITING_FOR_FEED');
        $this->actingAs($other)->get('/ii/market-data')->assertOk()
            ->assertSee('Belum dikonfigurasi')->assertViewHas('overview', fn ($overview) => $overview['status'] === 'NOT_CONFIGURED')->assertDontSee('AAPL')->assertDontSee('WAITING_FOR_FEED');
        $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/ii/market-data')->assertForbidden();
    }
}
