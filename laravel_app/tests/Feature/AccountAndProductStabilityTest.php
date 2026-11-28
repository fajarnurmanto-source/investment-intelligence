<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalResearchAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountAndProductStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_creation_real_login_password_reset_and_role_change_preserve_owned_content(): void
    {
        $accounts = app(LocalResearchAccountService::class);
        $user = $accounts->create(' Owner@Example.org ', 'First!SecurePass2026', 'analyst');
        $caseId = (string) Str::uuid();
        DB::table('ii_vs5_cases')->insert([
            'id' => $caseId,
            'ticker' => 'DEMO2',
            'title' => 'Owner retained case',
            'company_name' => 'Demo Company 2',
            'source_id' => 'GENERATED_DEMO:DEMO2',
            'review_status' => 'OPEN',
            'ii_created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'session-to-invalidate',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        $this->post('/ii/login', [
            'email' => 'owner@example.org',
            'password' => 'First!SecurePass2026',
        ])->assertRedirect(route('ii.dashboard'));
        $this->assertAuthenticatedAs($user);

        $reset = $accounts->resetPassword('owner@example.org', 'Second!SecurePass2026');
        $this->assertSame($user->id, $reset->id);
        $this->assertTrue(Hash::check('Second!SecurePass2026', $reset->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-invalidate']);
        $this->assertDatabaseHas('ii_vs5_cases', ['id' => $caseId, 'ii_created_by' => $user->id]);

        $updated = $accounts->setRole('owner@example.org', 'reviewer');
        $this->assertSame('reviewer', $updated->ii_research_role);
        $this->expectException(\InvalidArgumentException::class);
        $accounts->setRole('owner@example.org', 'trader');
    }

    public function test_research_cases_and_dashboard_counts_are_isolated_between_users(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'analyst'])->save();
        $other = User::factory()->create();
        $other->forceFill(['ii_research_role' => 'admin'])->save();
        $caseId = (string) Str::uuid();
        DB::table('ii_vs5_cases')->insert([
            'id' => $caseId,
            'ticker' => 'DEMO2',
            'title' => 'Private owner case',
            'company_name' => 'Demo Company 2',
            'source_id' => 'GENERATED_DEMO:DEMO2',
            'review_status' => 'OPEN',
            'ii_created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)->get('/ii/cases')->assertOk()->assertSee('Private owner case');
        $this->actingAs($other)->get('/ii/cases')->assertOk()->assertDontSee('Private owner case');
        $this->actingAs($other)->get('/ii/cases/'.$caseId)->assertNotFound();
        $this->actingAs($other)->get('/ii/cases/'.$caseId.'/report')->assertNotFound();
        $this->actingAs($other)->post('/ii/cases/'.$caseId.'/note', [
            'finding' => 'Must not cross account boundary',
            'review_status' => 'REVIEWED_SYNTHETIC',
        ])->assertNotFound();
        $this->actingAs($owner)->get('/ii/dashboard')->assertOk()
            ->assertSee('Catatan riset')->assertViewHas('researchCases', 1);
        $this->actingAs($other)->get('/ii/dashboard')->assertOk()
            ->assertSee('Catatan riset')->assertViewHas('researchCases', 0);
    }

    public function test_core_authenticated_navigation_and_exports_remain_available(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        foreach ([
            '/ii/dashboard', '/ii/signals', '/ii/fundamentals', '/ii/screener',
            '/ii/strategy', '/ii/strategy/validate', '/ii/strategy/rolling',
            '/ii/risk-budget', '/ii/portfolio', '/ii/macro', '/ii/company',
            '/ii/workspace', '/ii/cases',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }

        // Macro CSV requires independently reviewed source-use rights.
        $this->actingAs($user)->get('/ii/macro/report/rights')->assertOk()
            ->assertJsonPath('status', 'DENIED');
        $this->actingAs($user)->get('/ii/macro/report.csv')->assertForbidden();
        $this->actingAs($user)->get('/ii/screener/export.csv')->assertOk();
        $this->actingAs($user)->get('/ii/fundamentals/coverage/export.csv')->assertOk();
    }
}
