<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SecOfficialCompanyFactsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class IIEnvironmentReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_doctor_separates_global_records_from_local_user_and_never_mutates_database(): void
    {
        $user = User::factory()->create(['email' => 'research@example.org']);
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        // Environment CA availability is an operational prerequisite, not
        // a database fixture property; exercise the read-only diagnostic
        // without requiring a staging-specific PEM to be installed.
        $this->assertSame(0, Artisan::call('ii:doctor', [
            '--email' => 'research@example.org',
        ]));
        $report = Artisan::output();
        $this->assertStringContainsString('II_DOCTOR_DATABASE_INTEGRITY=ok', $report);
        $this->assertStringContainsString('II_DOCTOR_ACCOUNT_EXISTS=YES', $report);
        $this->assertStringContainsString('II_DOCTOR_ACCOUNT_ROLE=ANALYST', $report);
        $this->assertStringContainsString('II_DOCTOR_ACCOUNT_DOCUMENTS=0', $report);
        $this->assertStringContainsString('II_DOCTOR_ACCOUNT_PERIODS=0', $report);
        $this->assertStringContainsString('II_DOCTOR_DATA_SCOPE=PER_USER', $report);
        $this->assertStringContainsString('II_DOCTOR_NO_DATABASE_WRITES=YES', $report);
        $this->assertDatabaseCount('users', 1);

        $this->assertSame(1, Artisan::call('ii:doctor', [
            '--email' => 'not-registered@example.org', '--strict' => true,
        ]));
        $this->assertStringContainsString('II_DOCTOR_ACCOUNT_EXISTS=NO', Artisan::output());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_frankenphp_tls_must_use_real_runtime_ca_bundle_without_disabling_verification(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ii_ca_test_'.bin2hex(random_bytes(6));
        mkdir($directory.DIRECTORY_SEPARATOR.'certs', 0700, true);

        try {
            $this->expectException(\InvalidArgumentException::class);
            SecOfficialCompanyFactsService::tlsOptions($directory, 'frankenphp');
        } finally {
            rmdir($directory.DIRECTORY_SEPARATOR.'certs');
            rmdir($directory);
        }
    }

    public function test_frankenphp_tls_resolves_real_ca_file_but_cli_remains_system_verified(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ii_ca_test_'.bin2hex(random_bytes(6));
        mkdir($directory.DIRECTORY_SEPARATOR.'certs', 0700, true);
        $ca = $directory.DIRECTORY_SEPARATOR.'certs'.DIRECTORY_SEPARATOR.'cacert.pem';
        file_put_contents($ca, str_repeat('X', 100200));

        try {
            $actual = SecOfficialCompanyFactsService::tlsOptions($directory, 'frankenphp');
            $this->assertSame(realpath($ca), $actual['verify']);
            $this->assertFalse($actual['allow_redirects']);
            $cli = SecOfficialCompanyFactsService::tlsOptions($directory, 'cli');
            $this->assertTrue($cli['verify']);
            $this->assertFalse($cli['allow_redirects']);
        } finally {
            unlink($ca);
            rmdir($directory.DIRECTORY_SEPARATOR.'certs');
            rmdir($directory);
        }
    }
}
