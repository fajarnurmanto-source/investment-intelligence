<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class IdxMarketIntelligenceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_workspace(): void
    {
        $this->get('/ii/market-intelligence')->assertRedirect();
    }

    public function test_analyst_can_open_guided_local_workspace(): void
    {
        $user = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->get('http://localhost/ii/market-intelligence')
            ->assertOk()->assertSee('Mulai analisis')->assertSee('Analisis pasar')
            ->assertHeader('X-Content-Type-Options','nosniff');
    }

    public function test_missing_permission_returns_plain_language_error(): void
    {
        $user = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post('http://localhost/ii/market-intelligence', [
                'workbook'=>UploadedFile::fake()->create('summary.xlsx', 1),
            ])->assertStatus(422)->assertSee('konfirmasikan izin penggunaan data');
    }

    public function test_invalid_workbook_is_rejected_without_technical_exception(): void
    {
        $user = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post('http://localhost/ii/market-intelligence', [
                'workbook'=>UploadedFile::fake()->createWithContent('summary.xlsx','invalid'),
                'rights'=>'1',
            ])->assertStatus(422)->assertSee('Format atau isi file tidak sesuai');
    }

    public function test_remote_host_is_rejected(): void
    {
        $user = User::factory()->create(['ii_research_role'=>'analyst']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR'=>'192.0.2.1'])
            ->get('http://localhost/ii/market-intelligence')->assertForbidden();
    }
}
