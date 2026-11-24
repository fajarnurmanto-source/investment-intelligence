<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IIPersonalRestoreFixtureTest extends TestCase
{
    private function execute(string $root, string $filename, string $sha, bool $enable, bool $inject = false): Process
    {
        $script = dirname(base_path()).DIRECTORY_SEPARATOR.'tools'
            .DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'Execute-IIPersonalRestoreFixture.php';
        $env = [
            'II_DISPOSABLE_RESTORE_TEST' => $enable ? '1' : '0',
            'II_FIXTURE_RESTORE_INJECT_FAILURE' => $inject ? 'AFTER_ROLLBACK_MOVE' : '',
        ];
        $process = new Process([PHP_BINARY, $script, $root, $filename, $sha], null, $env);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function prepareFixture(): array
    {
        $root = storage_path('framework/testing/ii-restore-fixture-'.bin2hex(random_bytes(6)));
        $dir = $root.DIRECTORY_SEPARATOR.'database';
        File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'backups');
        $name = 'ii-20261125-065000-123456abcdef.sqlite';
        $db = $dir.DIRECTORY_SEPARATOR.'ii.sqlite';
        $backup = $dir.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.$name;
        $pdo = new \PDO('sqlite:'.$db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA user_version=3');
        foreach (['users', 'migrations', 'ii_byok_credentials'] as $table) {
            $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, value TEXT)");
        }
        $pdo->exec("INSERT INTO users (value) VALUES ('snapshot-owner')");
        $pdo->exec("VACUUM INTO '".str_replace("'", "''", $backup)."'");
        $pdo->exec("INSERT INTO users (value) VALUES ('current-not-in-snapshot')");
        $pdo = null;
        file_put_contents($root.DIRECTORY_SEPARATOR.'.env',
            'APP_KEY=base64:'.base64_encode(random_bytes(32))."\nDB_CONNECTION=sqlite\n");
        file_put_contents($root.DIRECTORY_SEPARATOR.'device.json', json_encode([
            'schema' => 'ii.prt.device-bootstrap.v1',
            'database_migrated' => true,
            'owner_enrolled' => true,
            'activation_status' => 'OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE',
            'restore_fixture_only' => true,
            'runtime_accepted' => false,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.DIRECTORY_SEPARATOR.'.ii-disposable-restore-fixture', 'test only');

        return [$root, $db, $name, hash_file('sha256', $backup)];
    }

    public function test_fixture_restore_preserves_original_as_rollback_and_never_modifies_env(): void
    {
        [$root, $db, $name, $sha] = $this->prepareFixture();
        try {
            $originalSha = hash_file('sha256', $db);
            $env = file_get_contents($root.DIRECTORY_SEPARATOR.'.env');
            $disabled = $this->execute($root, $name, $sha, false);
            $this->assertFalse($disabled->isSuccessful());
            $this->assertSame($originalSha, hash_file('sha256', $db));

            $run = $this->execute($root, $name, $sha, true);
            $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());
            $receipt = json_decode($run->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('DISPOSABLE_FIXTURE_RESTORE_VERIFIED', $receipt['status']);
            $this->assertTrue($receipt['restore_performed']);
            $this->assertTrue($receipt['fixture_only']);
            $this->assertFalse($receipt['production_restore_authorized']);
            $this->assertFalse($receipt['dpapi_identity_verified']);
            $this->assertSame($sha, hash_file('sha256', $db));
            $rollback = dirname($db).DIRECTORY_SEPARATOR.$receipt['rollback_filename'];
            $this->assertFileExists($rollback);
            $this->assertSame($originalSha, hash_file('sha256', $rollback));
            $this->assertSame($env, file_get_contents($root.DIRECTORY_SEPARATOR.'.env'));
            $restored = new \PDO('sqlite:'.$db);
            $this->assertSame(1, (int) $restored->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $restored = null;
            $this->assertStringNotContainsString('APP_KEY', $run->getOutput());
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_fixture_fault_after_original_move_restores_original_and_no_partial_backup(): void
    {
        [$root, $db, $name, $sha] = $this->prepareFixture();
        try {
            $before = hash_file('sha256', $db);
            $run = $this->execute($root, $name, $sha, true, true);
            $this->assertFalse($run->isSuccessful());
            $this->assertSame($before, hash_file('sha256', $db));
            $this->assertSame([], glob(dirname($db).DIRECTORY_SEPARATOR.'.restore-*.pending') ?: []);
            $this->assertSame([], glob(dirname($db).DIRECTORY_SEPARATOR.'ii-*.rollback.sqlite') ?: []);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_real_root_without_disposable_marker_is_never_modified(): void
    {
        [$root, $db, $name, $sha] = $this->prepareFixture();
        try {
            $before = hash_file('sha256', $db);
            unlink($root.DIRECTORY_SEPARATOR.'.ii-disposable-restore-fixture');
            $run = $this->execute($root, $name, $sha, true);
            $this->assertFalse($run->isSuccessful());
            $this->assertSame($before, hash_file('sha256', $db));
            $this->assertSame([], glob(dirname($db).DIRECTORY_SEPARATOR.'ii-*.rollback.sqlite') ?: []);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
