<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstitutionalExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_account_login_logout_and_brand_assets(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Institutional!Test2026'),
            'ii_research_role' => 'analyst',
        ]);
        $this->get('/')->assertRedirect('/ii/dashboard');
        $this->get('/ii/dashboard')->assertRedirect();
        $this->get('/ii/login')->assertOk()
            ->assertSee('Selamat datang')->assertSee('ii/brand.svg')
            ->assertSee('ii/institutional.css')->assertDontSee('NOT_ADMITTED');
        $this->post('/ii/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/ii/login', ['email' => $user->email, 'password' => 'Institutional!Test2026'])
            ->assertRedirect(route('ii.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post('/ii/logout')->assertRedirect('/ii/login');
        $this->assertGuest();
        $this->assertFileExists(public_path('ii/brand.svg'));
        $this->assertFileExists(public_path('ii/institutional.css'));
    }

    public function test_all_sixteen_primary_destinations_use_institutional_shell(): void
    {
        $user = User::factory()->create(['ii_research_role' => 'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $routes = [
            'ii.dashboard', 'ii.market-intelligence.index', 'ii.signals.index',
            'ii.macro.index', 'ii.screener.index', 'ii.company',
            'ii.fundamentals.index', 'ii.portfolio.index', 'ii.strategy.index',
            'ii.strategy.validate', 'ii.strategy.rolling', 'ii.risk-budget.index',
            'ii.cases.index', 'ii.workspace', 'ii.market-data.index', 'ii.byok.index',
        ];
        foreach ($routes as $name) {
            $page = $this->get(route($name))->assertOk()
                ->assertSee('class="ii-institutional ii-light-content"', false)
                ->assertSee('ii/brand.svg')->assertSee('ii/institutional.css')
                ->assertSee('aria-label="Navigasi utama"', false)
                ->assertSee('aria-current="page"', false);
            foreach ($routes as $destination) {
                $page->assertSee(route($destination));
            }
        }
    }
}
