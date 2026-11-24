<?php

declare(strict_types=1);

/**
 * Offline, owner-local SQLite backup for an initialized II personal device.
 *
 * Run with bundled PHP CLI:
 *   php tools/installer/Backup-IIPersonalDevice.php DEVICE_DATA_ROOT
 *
 * Uses SQLite VACUUM INTO (consistent snapshot, including WAL transactions).
 * The backup stays under the SAME private device root: database/backups.
 * Never copies .env, APP_KEY, plaintext credentials or DPAPI user profile.
 * Backups are SENSITIVE SQLite files, not portable or encrypted archives.
 * Does not restore, overwrite, enroll, activate or modify installed sources.
 */
function iiBackupDevice(string $root): array
{
    if ($root === '' || !is_dir($root) || is_link($root)) {
        throw new RuntimeException('II_DEVICE_BACKUP_ROOT_INVALID');
    }
    $canonical = realpath($root);
    if ($canonical === false) {
        throw new RuntimeException('II_DEVICE_BACKUP_ROOT_INVALID');
    }
    $metadataPath = $canonical.DIRECTORY_SEPARATOR.'device.json';
    $database = $canonical.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'ii.sqlite';
    if (!is_file($metadataPath) || is_link($metadataPath)
        || !is_file($database) || is_link($database)) {
        throw new RuntimeException('II_DEVICE_BACKUP_NOT_INITIALIZED');
    }
    $metadata = json_decode((string) file_get_contents($metadataPath), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($metadata)
        || ($metadata['schema'] ?? '') !== 'ii.prt.device-bootstrap.v1'
        || ($metadata['database_migrated'] ?? false) !== true
        || ($metadata['owner_enrolled'] ?? false) !== true
        || ($metadata['activation_status'] ?? '') !== 'OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE'
           && ($metadata['activation_status'] ?? '') !== 'RUNTIME_ACCEPTED') {
        throw new RuntimeException('II_DEVICE_BACKUP_ENROLLMENT_NOT_VERIFIED');
    }

    $dbDir = dirname($database);
    if (is_link($dbDir) || realpath($dbDir) === false || filesize($database) < 100) {
        throw new RuntimeException('II_DEVICE_BACKUP_DATABASE_INVALID');
    }
    $backupDir = $dbDir.DIRECTORY_SEPARATOR.'backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, false) && !is_dir($backupDir)) {
        throw new RuntimeException('II_DEVICE_BACKUP_DESTINATION_UNAVAILABLE');
    }
    if (is_link($backupDir) || realpath($backupDir) !== $backupDir) {
        throw new RuntimeException('II_DEVICE_BACKUP_DESTINATION_UNTRUSTED');
    }
    if (PHP_OS_FAMILY !== 'Windows' && (fileperms($backupDir) & 0077) !== 0) {
        if (!chmod($backupDir, 0700)) {
            throw new RuntimeException('II_DEVICE_BACKUP_PERMISSIONS_UNSAFE');
        }
    }

    $name = 'ii-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sqlite';
    $target = $backupDir.DIRECTORY_SEPARATOR.$name;
    $created = false;
    try {
        // Read-only connection prevents accidental source schema changes.
        $pdo = new PDO('sqlite:file:'.str_replace('%', '%25', $database).'?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('II_DEVICE_BACKUP_SOURCE_CORRUPT');
        }
        $escaped = str_replace("'", "''", $target);
        // SQLite may create the target before reporting a VACUUM error.
        // Track it *before* execution so even partial backups are removed.
        $created = true;
        $pdo->exec("VACUUM INTO '".$escaped."'");

        if (PHP_OS_FAMILY !== 'Windows' && !chmod($target, 0600)) {
            throw new RuntimeException('II_DEVICE_BACKUP_FILE_PERMISSIONS_UNSAFE');
        }
        $copy = new PDO('sqlite:'.$target, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('II_DEVICE_BACKUP_INTEGRITY_FAILED');
        }
        $copy = null;
        $pdo = null;
        return [
            'status' => 'OWNER_LOCAL_SQLITE_BACKUP_VERIFIED',
            'filename' => $name,
            'sha256' => hash_file('sha256', $target),
            'bytes' => filesize($target),
            'portable' => false,
            'includes_app_key' => false,
            'requires_same_windows_identity_for_dpapi' => true,
            'restore_performed' => false,
        ];
    } catch (Throwable $e) {
        if ($created && is_file($target)) {
            @unlink($target);
        }
        throw $e;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if ($argc !== 2) {
            throw new RuntimeException('II_DEVICE_BACKUP_USAGE_REQUIRES_DEVICE_ROOT');
        }
        echo json_encode(iiBackupDevice((string) $argv[1]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    } catch (Throwable $exception) {
        // Do not disclose filenames, DB contents, keys or PDO exception texts.
        fwrite(STDERR, "II_DEVICE_LOCAL_BACKUP_FAILED\n");
        exit(1);
    }
}
