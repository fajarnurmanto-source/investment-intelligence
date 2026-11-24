<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IIPersonalLocalBackupTest extends TestCase
{
    private function runBackup(string $root): Process
    {
        $script = dirname(base_path()).DIRECTORY_SEPARATOR.'tools'
            .DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'Backup-IIPersonalDevice.php';
        $process = new Process([PHP_BINARY, $script, $root]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    public function test_owner_only_consistent_backups_preserve_source_and_do_not_export_app_key(): void
    {
        $root = storage_path('framework/testing/ii-backup-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'database');
        $db = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'ii.sqlite';
        try {
            $pdo = new \PDO('sqlite:'.$db, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, info TEXT NOT NULL)');
            $pdo->exec("INSERT INTO sample(info) VALUES ('private-synthetic-test-only')");
            $pdo = null;
            file_put_contents($root.DIRECTORY_SEPARATOR.'.env', 'APP_KEY=base64:FAKE_TEST_SECRET_NEVER_COPY');
            $metadata = [
                'schema' => 'ii.prt.device-bootstrap.v1',
                'database_migrated' => true,
                'owner_enrolled' => false,
                'activation_status' => 'PENDING_OWNER_ENROLLMENT',
            ];
            file_put_contents($root.DIRECTORY_SEPARATOR.'device.json', json_encode($metadata));

            $denied = $this->runBackup($root);
            $this->assertFalse($denied->isSuccessful());
            $this->assertStringContainsString('II_DEVICE_LOCAL_BACKUP_FAILED', $denied->getErrorOutput());
            $this->assertSame([], glob($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'*') ?: []);

            $metadata['owner_enrolled'] = true;
            $metadata['activation_status'] = 'OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE';
            file_put_contents($root.DIRECTORY_SEPARATOR.'device.json', json_encode($metadata));
            $beforeHash = hash_file('sha256', $db);
            $first = $this->runBackup($root);
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $receipt = json_decode($first->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('OWNER_LOCAL_SQLITE_BACKUP_VERIFIED', $receipt['status']);
            $this->assertFalse($receipt['portable']);
            $this->assertFalse($receipt['includes_app_key']);
            $this->assertFalse($receipt['restore_performed']);
            $this->assertSame($beforeHash, hash_file('sha256', $db));

            $backup = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'backups'
                .DIRECTORY_SEPARATOR.$receipt['filename'];
            $this->assertFileExists($backup);
            $this->assertSame($receipt['sha256'], hash_file('sha256', $backup));
            $copy = new \PDO('sqlite:'.$backup, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $this->assertSame('private-synthetic-test-only',
                $copy->query('SELECT info FROM sample')->fetchColumn());
            $copy = null;
            $this->assertFalse(file_exists($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'.env'));
            $this->assertStringNotContainsString('FAKE_TEST_SECRET', $first->getOutput());

            $second = $this->runBackup($root);
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $receipt2 = json_decode($second->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertNotSame($receipt['filename'], $receipt2['filename']);
            $this->assertCount(2, glob($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'*.sqlite'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_missing_device_metadata_does_not_create_backup(): void
    {
        $root = storage_path('framework/testing/ii-backup-empty-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'database');
        try {
            $result = $this->runBackup($root);
            $this->assertFalse($result->isSuccessful());
            $this->assertSame([], glob($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'*') ?: []);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
