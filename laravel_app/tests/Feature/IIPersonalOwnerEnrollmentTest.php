<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IIPersonalOwnerEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_enrollment_is_opt_in_and_not_available_over_http(): void
    {
        $this->artisan('ii:prt:enroll-owner')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        $this->post('/ii/register', [
            'email' => 'owner@example.test',
            'password' => 'long-password-test-123',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_interactive_enrollment_creates_one_hashed_admin_and_denies_second(): void
    {
        $this->artisan('ii:prt:enroll-owner', ['--confirm-single-owner' => true])
            ->expectsQuestion('Owner name', 'Personal Research Owner')
            ->expectsQuestion('Owner email', 'owner@example.test')
            ->expectsQuestion('Owner password (minimum 14 characters)', 'long-private-passphrase-123')
            ->expectsQuestion('Repeat owner password', 'long-private-passphrase-123')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $owner = User::query()->firstOrFail();
        $this->assertSame('admin', $owner->ii_research_role);
        $this->assertTrue(Hash::check('long-private-passphrase-123', $owner->password));
        $this->assertNotSame('long-private-passphrase-123', $owner->password);
        $this->artisan('ii:prt:enroll-owner', ['--confirm-single-owner' => true])
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_users_prevent_personal_owner_enrollment(): void
    {
        User::factory()->create();
        $this->artisan('ii:prt:enroll-owner', ['--confirm-single-owner' => true])
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_owner_password_mismatch_fails_closed(): void
    {
        $this->artisan('ii:prt:enroll-owner', ['--confirm-single-owner' => true])
            ->expectsQuestion('Owner name', 'Owner')
            ->expectsQuestion('Owner email', 'owner@example.test')
            ->expectsQuestion('Owner password (minimum 14 characters)', 'long-private-passphrase-123')
            ->expectsQuestion('Repeat owner password', 'different-password-123')
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }
}
