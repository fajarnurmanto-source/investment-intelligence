<?php

declare(strict_types=1);

/**
 * DISPOSABLE FIXTURE RESTORE ONLY — NEVER on an enrolled user device.
 *
 * This exercises rollback and atomic rename mechanics on an isolated test
 * device. A real owner device can NEVER meet the fixture-only gates.
 *
 * Usage: php Execute-IIPersonalRestoreFixture.php ROOT BACKUP_FILENAME SHA256
 *         (also requires II_DISPOSABLE_RESTORE_TEST=1 in process environment)
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'Verify-IIPersonalRestore.php';

function iiExecuteFixtureRestore(string $root, string $filename, string $expectedSha): array
{
    if (getenv('II_DISPOSABLE_RESTORE_TEST') !== '1') {
        throw new RuntimeException('II_FIXTURE_RESTORE_DISABLED');
    }
    $canonical = realpath($root);
    if ($canonical === false
        || !preg_match('/^ii-restore-fixture-[a-f0-9]{12}$/D', basename($canonical))
        || !is_file($canonical.DIRECTORY_SEPARATOR.'.ii-disposable-restore-fixture')) {
        throw new RuntimeException('II_FIXTURE_RESTORE_ROOT_DENIED');
    }
    $metadata = json_decode(
        (string) file_get_contents($canonical.DIRECTORY_SEPARATOR.'device.json'),
        true, 16, JSON_THROW_ON_ERROR
    );
    if (($metadata['restore_fixture_only'] ?? false) !== true
        || ($metadata['activation_status'] ?? '') !== 'OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE'
        || ($metadata['runtime_accepted'] ?? false) !== false) {
        throw new RuntimeException('II_FIXTURE_RESTORE_NOT_DISPOSABLE');
    }
    $folder = $canonical.DIRECTORY_SEPARATOR.'database';
    $lockPath = $folder.DIRECTORY_SEPARATOR.'.restore-fixture.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('II_FIXTURE_RESTORE_LOCK_UNTRUSTED');
    }
    $lock = fopen($lockPath, 'c+b');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('II_FIXTURE_RESTORE_LOCKED');
    }

    $db = $folder.DIRECTORY_SEPARATOR.'ii.sqlite';
    $source = $folder.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.$filename;
    $nonce = bin2hex(random_bytes(8));
    $staged = $folder.DIRECTORY_SEPARATOR.'.restore-'.$nonce.'.pending';
    $rollback = $folder.DIRECTORY_SEPARATOR.'ii-'.$nonce.'.rollback.sqlite';
    $moved = false;
    $published = false;
    try {
        $eligibility = iiVerifyPersonalRestore($canonical, $filename, $expectedSha);
        $originalSha = $eligibility['database_sha256'];
        if (!copy($source, $staged)) {
            throw new RuntimeException('II_FIXTURE_RESTORE_STAGING_FAILED');
        }
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($staged, 0600)) {
            throw new RuntimeException('II_FIXTURE_RESTORE_PERMISSIONS_FAILED');
        }
        if (!hash_equals($expectedSha, (string) hash_file('sha256', $staged))) {
            throw new RuntimeException('II_FIXTURE_RESTORE_STAGE_CHECKSUM_FAILED');
        }
        // Reject a race with any still-open SQLite writer where possible.
        $sqlite = new PDO('sqlite:'.$db, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 1,
        ]);
        if ($sqlite->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('II_FIXTURE_RESTORE_SOURCE_INTEGRITY_FAILED');
        }
        if (!hash_equals($originalSha, (string) hash_file('sha256', $db))) {
            throw new RuntimeException('II_FIXTURE_RESTORE_SOURCE_CHANGED');
        }
        $sqlite = null;
        foreach ([$db.'-wal', $db.'-shm', $db.'-journal'] as $sidecar) {
            if (file_exists($sidecar)) {
                throw new RuntimeException('II_FIXTURE_RESTORE_ACTIVE_SQLITE_SIDECAR');
            }
        }

        // Preserve the original inode as rollback; never overwrite/delete it.
        if (!rename($db, $rollback)) {
            throw new RuntimeException('II_FIXTURE_RESTORE_ROLLBACK_RENAME_FAILED');
        }
        $moved = true;
        if (getenv('II_FIXTURE_RESTORE_INJECT_FAILURE') === 'AFTER_ROLLBACK_MOVE') {
            throw new RuntimeException('II_FIXTURE_RESTORE_INJECTED_FAILURE');
        }
        if (!rename($staged, $db)) {
            throw new RuntimeException('II_FIXTURE_RESTORE_PUBLISH_FAILED');
        }
        $published = true;
        if (!hash_equals($expectedSha, (string) hash_file('sha256', $db))) {
            throw new RuntimeException('II_FIXTURE_RESTORE_RESULT_MISMATCH');
        }
        $check = new PDO('sqlite:'.$db, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('II_FIXTURE_RESTORE_RESULT_CORRUPT');
        }
        $check = null;

        return [
            'status' => 'DISPOSABLE_FIXTURE_RESTORE_VERIFIED',
            'backup_sha256' => $expectedSha,
            'restored_database_sha256' => hash_file('sha256', $db),
            'rollback_sha256' => hash_file('sha256', $rollback),
            'rollback_filename' => basename($rollback),
            'restore_performed' => true,
            'fixture_only' => true,
            'production_restore_authorized' => false,
            'dpapi_identity_verified' => false,
        ];
    } catch (Throwable $e) {
        // Never replace a good copy until the exact previous database can be
        // restored. Keep the rollback file if rollback itself fails.
        if ($moved) {
            if ($published && is_file($db)) {
                $failed = $folder.DIRECTORY_SEPARATOR.'.failed-restore-'.$nonce.'.sqlite';
                if (!rename($db, $failed)) {
                    throw new RuntimeException('II_FIXTURE_ROLLBACK_UNSAFE');
                }
            }
            if (!rename($rollback, $db)) {
                throw new RuntimeException('II_FIXTURE_ROLLBACK_UNSAFE');
            }
        }
        throw $e;
    } finally {
        if (is_file($staged)) {
            @unlink($staged);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if ($argc !== 4) {
            throw new RuntimeException('II_FIXTURE_RESTORE_USAGE');
        }
        echo json_encode(iiExecuteFixtureRestore(
            (string) $argv[1], (string) $argv[2], (string) $argv[3]
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    } catch (Throwable) {
        fwrite(STDERR, "II_DISPOSABLE_RESTORE_DENIED_OR_ROLLED_BACK\n");
        exit(1);
    }
}
