<?php

// Read-only remote preflight. Never boots Laravel or prints connection details.
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
try {
    $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.deployment/clevercloud.env'));
} catch (Throwable $error) {
    fwrite(STDERR, "Unable to parse the credentials file; details suppressed.\n");
    exit(1);
}
$directory = $root.'/storage/app/private/hosted-review-'.gmdate('Ymd-His');
mkdir($directory, 0700, true);
$optionFile = $directory.'/client.cnf';
$quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
$ca = $env['MYSQL_ATTR_SSL_CA'] ?? ini_get('openssl.cafile') ?: ini_get('curl.cainfo');
$options = "[client]\nprotocol=tcp\nconnect-timeout=15\nssl-mode=VERIFY_IDENTITY\n";
foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'user' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $name) {
    if (empty($env[$name])) {
        throw new RuntimeException('Required connection field is missing; no connection attempted.');
    }
    $options .= $key.'='.$quote($env[$name])."\n";
}
if ($ca && is_file($ca)) {
    $options .= 'ssl-ca='.$quote($ca)."\n";
}
file_put_contents($optionFile, $options);
chmod($optionFile, 0600);
$mysql = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
$exitCode = 0;
try {
    $sql = "SET SESSION TRANSACTION READ ONLY; START TRANSACTION READ ONLY; SELECT VERSION() AS server_version; SHOW SESSION STATUS LIKE 'Ssl_cipher'; SELECT @@transaction_read_only AS session_read_only; ROLLBACK;";
    $process = new Process([$mysql, '--defaults-extra-file='.$optionFile, '--batch', '--raw', '--database='.$env['DB_DATABASE'], '--execute='.$sql]);
    $process->setTimeout(30);
    $process->run();
    if (! $process->isSuccessful()) {
        $error = $process->getErrorOutput();
        $category = match (true) {
            str_contains($error, '2026') => 'TLS verification/negotiation failed',
            str_contains($error, '2003') => 'Network connection failed',
            str_contains($error, '1045') => 'Authentication denied',
            default => 'Connection/preflight failed',
        };
        preg_match('/ERROR (\d+)/', $error, $code);
        $tlsReason = match (true) {
            str_contains(strtolower($error), "doesn't support"), str_contains(strtolower($error), 'does not support') => 'Server does not support the requested TLS connection',
            str_contains(strtolower($error), 'self-signed') => 'Certificate is self-signed and not trusted by the configured CA bundle',
            str_contains(strtolower($error), 'hostname'), str_contains(strtolower($error), 'identity') => 'Server certificate identity could not be verified',
            str_contains(strtolower($error), 'certificate verify failed') => 'Certificate chain verification failed',
            str_contains(strtolower($error), 'unable to get') => 'Trusted issuer certificate is unavailable',
            default => 'No recognized safe diagnostic detail',
        };
        file_put_contents($directory.'/status.json', json_encode(['status' => 'blocked', 'category' => $category, 'mysql_error_code' => $code[1] ?? null, 'tls_reason' => $tlsReason], JSON_PRETTY_PRINT));
        echo $category.'; MySQL code '.($code[1] ?? 'unknown').". No database data read or backup taken.\n";
        echo $tlsReason.PHP_EOL;
        $exitCode = 1;
    } else {
        file_put_contents($directory.'/connection.txt', $process->getOutput());
        echo $process->getOutput(); // Only version, cipher and read-only session flag.
        echo "Verified connection preflight complete.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Connection preflight failed; raw exception suppressed to protect connection details.\n");
    $exitCode = 1;
} finally {
    if (is_file($optionFile)) {
        unlink($optionFile);
    }
}
exit($exitCode);
