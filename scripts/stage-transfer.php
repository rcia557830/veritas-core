<?php

declare(strict_types=1);

// Local snapshot only: no network calls and no .env/credentials in the package.
$root = dirname(__DIR__);
ob_start();
// Invoke the read-only audit in a child process because it has an explicit exit.
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/audit-transfer.php'), $code);
$audit = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
if ($code !== 0) {
    throw new RuntimeException('Transfer audit failed.');
}
$directory = "$root/storage/app/private/transfer-".date('Ymd-His').'-'.bin2hex(random_bytes(4));
mkdir($directory, 0700, true);
$source = new SQLite3("$root/database/veritas_demo.sqlite", SQLITE3_OPEN_READONLY);
$snapshot = new SQLite3("$directory/source.sqlite");
if (! $source->backup($snapshot)) {
    throw new RuntimeException('Snapshot failed.');
}
$snapshot->close();
$source->close();
$manifest = [];
foreach ($audit['attachments'] as $relative => $metadata) {
    $target = "$directory/private/$relative";
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    if (! copy("$root/storage/app/private/$relative", $target) || ! hash_equals($metadata['sha256'], hash_file('sha256', $target))) {
        throw new RuntimeException('Attachment copy failed.');
    }
    $manifest['private/'.$relative] = $metadata;
}
// Public logos/assets also need persistent storage.
$public = "$root/storage/app/public";
if (is_dir($public)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getFilename() === '.gitignore') {
            continue;
        }
        if ($file->isLink()) {
            throw new RuntimeException('Unexpected public storage symlink.');
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($public) + 1));
        $target = "$directory/public/$relative";
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        $hash = hash_file('sha256', $file->getPathname());
        if (! copy($file->getPathname(), $target) || ! hash_equals($hash, hash_file('sha256', $target))) {
            throw new RuntimeException('Public asset copy failed.');
        }
        $manifest['public/'.$relative] = ['bytes' => $file->getSize(), 'sha256' => $hash];
    }
}
$manifest['source.sqlite'] = ['bytes' => filesize("$directory/source.sqlite"), 'sha256' => hash_file('sha256', "$directory/source.sqlite")];
if (! hash_equals($audit['sqlite_sha256'], hash_file('sha256', "$root/database/veritas_demo.sqlite"))) {
    throw new RuntimeException('Source changed while staging; discard this incomplete package and rerun with writes stopped.');
}
file_put_contents("$directory/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo "Verified local transfer package: $directory".PHP_EOL;
