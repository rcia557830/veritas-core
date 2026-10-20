<?php

// Read-only audit; never boots Laravel or loads credentials.
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $argv[1] ?? "$root/database/veritas_demo.sqlite";
$private = realpath($argv[2] ?? "$root/storage/app/private");
if (! is_file($source) || ! $private) {
    throw new RuntimeException('Source database/private folder missing.');
}
$db = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA query_only=ON');
$db->beginTransaction();
$integrity = $db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
$foreign = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$counts = [];
foreach ($tables as $table) {
    $counts[$table] = (int) $db->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
}
$files = [];
$errors = [];
$unhashed = 0;
foreach (['documents' => 'file_sha256', 'journal_documents' => 'sha256'] as $table => $hashColumn) {
    foreach ($db->query("SELECT * FROM $table WHERE file_path IS NOT NULL AND file_path <> ''", PDO::FETCH_ASSOC) as $row) {
        $relative = $row['file_path'];
        $path = realpath($private.'/'.$relative);
        if (! $path || ! str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $private).'/') || ! is_file($path)) {
            $errors[] = "$table:{$row['id']} missing/unsafe attachment";

            continue;
        }
        $hash = hash_file('sha256', $path);
        if (! empty($row[$hashColumn]) && ! hash_equals($row[$hashColumn], $hash)) {
            $errors[] = "$table:{$row['id']} hash mismatch";
        }
        if (empty($row[$hashColumn])) {
            $unhashed++;
        }
        $files[$relative] = ['bytes' => filesize($path), 'sha256' => $hash];
    }
}
ksort($files);
$result = ['sqlite_sha256' => hash_file('sha256', $source), 'integrity' => $integrity, 'foreign_key_violations' => count($foreign), 'counts' => $counts, 'attachments' => $files, 'references_without_recorded_hash' => $unhashed, 'errors' => $errors];
$db->rollBack();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($integrity === ['ok'] && ! $foreign && ! $errors && ($counts['users'] ?? 0) === 4 ? 0 : 1);
