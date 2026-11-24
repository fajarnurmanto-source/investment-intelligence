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

    public function test_device_state_is_atomically_marked_enrolled_but_not_runtime_accepted(): void
    {
        $root = storage_path('framework/testing/ii-device-owner-'.bin2hex(random_bytes(5)));
        \Illuminate\Support\Facades\File::ensureDirectoryExists($root);
        $file = $root.DIRECTORY_SEPARATOR.'device.json';
        $metadata = [
            'schema' => 'ii.prt.device-bootstrap.v1',
            'database_migrated' => true,
            'owner_enrolled' => false,
            'activation_status' => 'PENDING_OWNER_ENROLLMENT',
            'device_id' => 'synthetic-device',
        ];

        try {
            file_put_contents($file, json_encode($metadata, JSON_THROW_ON_ERROR));
            $command = app(\App\Console\Commands\IIPersonalEnrollOwner::class);
            // Unit-test the private filesystem transition in isolation:
            // testing mode deliberately never mutates actual device.json.
            $method = new \ReflectionMethod($command, 'publishOwnerEnrollmentState');
            $method->invoke($command, $root);
            $actual = json_decode((string) file_get_contents($file), true);
            $this->assertTrue($actual['owner_enrolled']);
            $this->assertFalse($actual['login_ready']);
            $this->assertFalse($actual['runtime_accepted']);
            $this->assertSame('OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE', $actual['activation_status']);
            $this->assertSame('synthetic-device', $actual['device_id']);
            $this->assertArrayNotHasKey('password', $actual);
            $this->assertArrayNotHasKey('email', $actual);

            try {
                $method->invoke($command, $root);
                $this->fail('Duplicate owner-state activation must fail closed.');
            } catch (\ReflectionException | \RuntimeException $e) {
                $this->assertStringContainsString('II_PRT_DEVICE_STATE_CHANGED', $e->getMessage());
            }
            $this->assertSame([], glob($file.'.owner-*.pending'));
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($root);
        }
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
