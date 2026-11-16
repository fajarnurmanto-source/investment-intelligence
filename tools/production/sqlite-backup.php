<?php

if ($argc !== 3 || ! is_file($argv[1]) || file_exists($argv[2])) {
    fwrite(STDERR, "Usage: sqlite-backup.php EXISTING_SOURCE NEW_TARGET\n");
    exit(2);
}
$source = realpath($argv[1]);
$target = $argv[2];
$pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$quoted = str_replace("'", "''", $target);
$pdo->exec("VACUUM INTO '{$quoted}'");
$backup = new PDO('sqlite:'.$target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($backup->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('SQLite backup integrity check failed.');
}
echo json_encode(['bytes' => filesize($target), 'sha256' => hash_file('sha256', $target), 'integrity' => 'ok'], JSON_THROW_ON_ERROR).PHP_EOL;
