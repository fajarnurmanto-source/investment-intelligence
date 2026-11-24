<?php

declare(strict_types=1);

/**
 * Read-only restore eligibility review for a same-device II SQLite snapshot.
 *
 * This tool NEVER copies, renames, replaces, or restores a database.
 * Usage: php Verify-IIPersonalRestore.php DEVICE_ROOT BACKUP_FILENAME SHA256
 *
 * Source/backup SQLite connections use mode=ro; WAL/journal presence blocks
 * a prospective offline restore, but cannot prove the runtime is stopped.
 * The expected SHA256 must be provided independently of the backup bytes.
 */
function iiVerifyPersonalRestore(string $root, string $filename, string $expectedSha): array
{
    if (!preg_match('/^ii-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}\.sqlite$/D', $filename)
        || !preg_match('/^[a-f0-9]{64}$/D', $expectedSha)) {
        throw new RuntimeException('II_RESTORE_INPUT_INVALID');
    }
    $canonical = realpath($root);
    if ($canonical === false || !is_dir($canonical) || is_link($root)) {
        throw new RuntimeException('II_RESTORE_DEVICE_INVALID');
    }
    $rootParent = dirname($canonical);
    if (is_link($rootParent)) {
        throw new RuntimeException('II_RESTORE_DEVICE_PARENT_UNTRUSTED');
    }
    $metadataPath = $canonical.DIRECTORY_SEPARATOR.'device.json';
    $envPath = $canonical.DIRECTORY_SEPARATOR.'.env';
    $dbDir = $canonical.DIRECTORY_SEPARATOR.'database';
    $db = $dbDir.DIRECTORY_SEPARATOR.'ii.sqlite';
    $backupDir = $dbDir.DIRECTORY_SEPARATOR.'backups';
    $backup = $backupDir.DIRECTORY_SEPARATOR.$filename;

    foreach ([$metadataPath, $envPath, $db] as $path) {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('II_RESTORE_DEVICE_STATE_INCOMPLETE');
        }
    }
    if (!is_dir($dbDir) || is_link($dbDir)
        || !is_dir($backupDir) || is_link($backupDir)
        || realpath($backupDir) !== $backupDir
        || !is_file($backup) || is_link($backup)
        || realpath($backup) !== $backup) {
        throw new RuntimeException('II_RESTORE_BACKUP_PATH_UNTRUSTED');
    }
    $metadata = json_decode((string) file_get_contents($metadataPath), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($metadata)
        || ($metadata['schema'] ?? null) !== 'ii.prt.device-bootstrap.v1'
        || ($metadata['database_migrated'] ?? false) !== true
        || ($metadata['owner_enrolled'] ?? false) !== true
        || !in_array(($metadata['activation_status'] ?? null), [
            'OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE', 'RUNTIME_ACCEPTED',
        ], true)) {
        throw new RuntimeException('II_RESTORE_DEVICE_NOT_ENROLLED');
    }

    // The local APP_KEY and Windows user DPAPI profile are intentionally
    // NOT included in the SQLite snapshot; these must be preserved.
    $env = (string) file_get_contents($envPath);
    $keyMatches = [];
    if (preg_match_all('/^APP_KEY=(base64:[A-Za-z0-9+\/=]{40,})\r?$/m', $env, $keyMatches) !== 1
        || !str_contains($env, 'DB_CONNECTION=sqlite')) {
        throw new RuntimeException('II_RESTORE_LOCAL_APP_KEY_MISSING');
    }
    $actual = hash_file('sha256', $backup);
    if ($actual === false || !hash_equals($expectedSha, $actual)) {
        throw new RuntimeException('II_RESTORE_BACKUP_SHA256_MISMATCH');
    }
    if (filesize($backup) < 100 || filesize($db) < 100) {
        throw new RuntimeException('II_RESTORE_DATABASE_TOO_SMALL');
    }
    foreach ([$db.'-wal', $db.'-shm', $db.'-journal'] as $sidecar) {
        if (file_exists($sidecar)) {
            throw new RuntimeException('II_RESTORE_SQLITE_SIDECAR_PRESENT');
        }
    }
    // Explicit runtime gate. Even absence of this marker cannot prove that
    // all PHP or webserver processes have stopped.
    foreach (['runtime.pid', 'server.pid', 'frankenphp.pid'] as $marker) {
        if (file_exists($canonical.DIRECTORY_SEPARATOR.$marker)) {
            throw new RuntimeException('II_RESTORE_RUNTIME_MARKER_PRESENT');
        }
    }
    $inspect = static function (string $path): array {
        $pdo = new PDO('sqlite:file:'.str_replace(['%', '#', '?'], ['%25', '%23', '%3F'], str_replace('\\', '/', $path)).'?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $userVersion = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        $schema = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','migrations','ii_byok_credentials') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $pdo = null;
        if ($integrity !== 'ok') {
            throw new RuntimeException('II_RESTORE_SQLITE_INTEGRITY_FAILED');
        }

        return ['user_version' => $userVersion, 'schema' => $schema];
    };
    $current = $inspect($db);
    $candidate = $inspect($backup);
    foreach (['users', 'migrations', 'ii_byok_credentials'] as $required) {
        if (!in_array($required, $current['schema'], true)
            || !in_array($required, $candidate['schema'], true)) {
            throw new RuntimeException('II_RESTORE_SCHEMA_MISMATCH');
        }
    }
    if ($current['user_version'] !== $candidate['user_version']) {
        throw new RuntimeException('II_RESTORE_SQLITE_VERSION_MISMATCH');
    }
    // This is NOT approval to replace a live DB: it indicates only that the
    // offline candidate passed a bounded read-only integrity preflight.
    return [
        'status' => 'OFFLINE_RESTORE_PREFLIGHT_VERIFIED',
        'database_sha256' => hash_file('sha256', $db),
        'backup_sha256' => $actual,
        'backup_bytes' => filesize($backup),
        'schema_user_version' => $candidate['user_version'],
        'restore_performed' => false,
        'runtime_stopped_verified' => false,
        'dpapi_identity_verified' => false,
        'app_key_exported' => false,
        'release_authorized' => false,
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if ($argc !== 4) {
            throw new RuntimeException('II_RESTORE_ARGUMENTS_REQUIRED');
        }
        echo json_encode(iiVerifyPersonalRestore((string) $argv[1], (string) $argv[2], (string) $argv[3]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    } catch (Throwable) {
        fwrite(STDERR, "II_DEVICE_RESTORE_PREFLIGHT_DENIED\n");
        exit(1);
    }
}
