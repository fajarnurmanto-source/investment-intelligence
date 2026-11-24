<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IIPersonalRestorePreflightTest extends TestCase
{
    private function runVerify(string $root, string $file, string $sha): Process
    {
        $script = dirname(base_path()).DIRECTORY_SEPARATOR.'tools'
            .DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'Verify-IIPersonalRestore.php';
        $process = new Process([PHP_BINARY, $script, $root, $file, $sha]);
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    public function test_restore_candidate_is_verified_read_only_and_does_not_touch_device_secrets(): void
    {
        $root = storage_path('framework/testing/ii-restore-'.bin2hex(random_bytes(6)));
        $dbDir = $root.DIRECTORY_SEPARATOR.'database';
        $backups = $dbDir.DIRECTORY_SEPARATOR.'backups';
        File::ensureDirectoryExists($backups);
        $database = $dbDir.DIRECTORY_SEPARATOR.'ii.sqlite';
        $name = 'ii-20261125-012300-a1b2c3d4e5f6.sqlite';
        $candidate = $backups.DIRECTORY_SEPARATOR.$name;

        try {
            $db = new \PDO('sqlite:'.$database, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $db->exec('PRAGMA user_version=8');
            foreach (['users', 'migrations', 'ii_byok_credentials'] as $table) {
                $db->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, value TEXT)');
            }
            $db->exec("INSERT INTO users (value) VALUES ('newer-local-owner-fixture')");
            $db->exec("VACUUM INTO '".str_replace("'", "''", $candidate)."'");
            $db->exec("INSERT INTO users (value) VALUES ('newer-current-only')");
            $db = null;

            $env = 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\nDB_CONNECTION=sqlite\n";
            file_put_contents($root.DIRECTORY_SEPARATOR.'.env', $env);
            $metadata = [
                'schema' => 'ii.prt.device-bootstrap.v1',
                'database_migrated' => true,
                'owner_enrolled' => true,
                'activation_status' => 'OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE',
            ];
            file_put_contents($root.DIRECTORY_SEPARATOR.'device.json', json_encode($metadata));
            $sourceSha = hash_file('sha256', $database);
            $candidateSha = hash_file('sha256', $candidate);

            $result = $this->runVerify($root, $name, $candidateSha);
            $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
            $report = json_decode($result->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('OFFLINE_RESTORE_PREFLIGHT_VERIFIED', $report['status']);
            $this->assertSame($candidateSha, $report['backup_sha256']);
            $this->assertSame($sourceSha, $report['database_sha256']);
            $this->assertFalse($report['restore_performed']);
            $this->assertFalse($report['runtime_stopped_verified']);
            $this->assertFalse($report['dpapi_identity_verified']);
            $this->assertFalse($report['release_authorized']);
            $this->assertStringNotContainsString('APP_KEY', $result->getOutput());
            $this->assertSame($sourceSha, hash_file('sha256', $database));
            $this->assertSame($candidateSha, hash_file('sha256', $candidate));
            $this->assertSame($env, file_get_contents($root.DIRECTORY_SEPARATOR.'.env'));

            $wrongSha = $this->runVerify($root, $name, str_repeat('0', 64));
            $this->assertFalse($wrongSha->isSuccessful());
            $this->assertSame($sourceSha, hash_file('sha256', $database));

            file_put_contents($database.'-wal', 'simulated-active-WAL');
            $walDenied = $this->runVerify($root, $name, $candidateSha);
            $this->assertFalse($walDenied->isSuccessful());
            unlink($database.'-wal');

            file_put_contents($root.DIRECTORY_SEPARATOR.'runtime.pid', 'synthetic');
            $activeDenied = $this->runVerify($root, $name, $candidateSha);
            $this->assertFalse($activeDenied->isSuccessful());
            unlink($root.DIRECTORY_SEPARATOR.'runtime.pid');

            $metadata['owner_enrolled'] = false;
            file_put_contents($root.DIRECTORY_SEPARATOR.'device.json', json_encode($metadata));
            $ownerDenied = $this->runVerify($root, $name, $candidateSha);
            $this->assertFalse($ownerDenied->isSuccessful());
            $this->assertSame($sourceSha, hash_file('sha256', $database));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_rejects_backup_paths_and_corrupt_snapshot_without_source_mutation(): void
    {
        $root = storage_path('framework/testing/ii-restore-invalid-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'database');
        try {
            $bad = $this->runVerify($root, '../secret.sqlite', str_repeat('a', 64));
            $this->assertFalse($bad->isSuccessful());
            $this->assertStringContainsString('II_DEVICE_RESTORE_PREFLIGHT_DENIED', $bad->getErrorOutput());
            $this->assertFileDoesNotExist($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'ii.sqlite');
        } finally {
            File::deleteDirectory($root);
        }
    }
}
