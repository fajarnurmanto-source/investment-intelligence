<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CompanyResearchService;
use App\Services\ScenarioEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaravelCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_research_and_scenario_execute_natively(): void
    {
        $research = app(CompanyResearchService::class)->research('DEMO2');
        $this->assertSame('HOLD', $research['effective_decision']);
        $this->assertSame('GENERATED_DEMO', $research['provenance']['source_type']);

        $scenario = app(ScenarioEvaluationService::class)->evaluate(8, 15, 10);
        $this->assertTrue($scenario['thesis_falsified']);
        $this->assertSame('HOLD', $scenario['research_state']);
        $this->assertFalse($scenario['trading_authorized']);
    }

    public function test_authenticated_company_workspace_has_no_external_engine_dependency(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        $this->actingAs($user)
            ->get('/ii/company?ticker=DEMO2')
            ->assertOk()
            ->assertSee('Contoh simulasi')
            ->assertSee('perusahaan fiktif')
            ->assertViewHas('research', fn ($research) => $research['provenance']['source_id'] === 'SYNTHETIC_FIXTURE_LARAVEL_DEMO2' && $research['admission'] === 'NOT_ADMITTED' && $research['trading_authorized'] === false);

        $this->actingAs($user)
            ->get('/ii/workspace?ticker=DEMO3')
            ->assertOk()
            ->assertSee('Synthetic Industrial Business');
    }
}
