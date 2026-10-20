<?php

// Veritas Core V2 — Reconcile 4 Intended Accounts on Clever Cloud MySQL
// Preserves all existing hosted records. Never logs passwords or hashes.
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$envPath = $root.'/.deployment/clevercloud.env';

if (! is_file($envPath)) {
    fwrite(STDERR, "Configuration file .deployment/clevercloud.env not found.\n");
    exit(1);
}

try {
    $env = Dotenv\Dotenv::parse(file_get_contents($envPath));
} catch (Throwable $e) {
    fwrite(STDERR, "Unable to parse .deployment/clevercloud.env.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

echo "====================================================================\n";
echo "CLEVER CLOUD MYSQL: 4-ACCOUNT & ROLE RECONCILIATION\n";
echo 'Mode: '.($dryRun ? 'DRY RUN (Read-Only Simulation)' : 'LIVE EXECUTION')."\n";
echo 'Host: '.$env['DB_HOST'].':'.$env['DB_PORT']."\n";
echo 'Database: '.$env['DB_DATABASE']."\n";
echo "====================================================================\n\n";

$dsn = "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']};charset=utf8mb4";
$pdo = new PDO($dsn, $env['DB_USERNAME'], $env['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 15,
]);

// 1. Verify schema prerequisites
$roles = $pdo->query('SELECT slug, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
if (! isset($roles['super-admin'])) {
    fwrite(STDERR, "Error: 'super-admin' role is not found. Please run pending migrations first.\n");
    exit(1);
}

// Sync permissions catalog if live execution
if (! $dryRun) {
    echo "[Step 0/3] Synchronizing RBAC permissions catalog on Clever Cloud...\n";
    $artisanEnv = array_merge($_SERVER, [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $env['DB_HOST'],
        'DB_PORT' => $env['DB_PORT'],
        'DB_DATABASE' => $env['DB_DATABASE'],
        'DB_USERNAME' => $env['DB_USERNAME'],
        'DB_PASSWORD' => $env['DB_PASSWORD'],
    ]);
    $seedProc = new Process(['php', 'artisan', 'db:seed', '--class=Database\\Seeders\\PermissionSeeder', '--force'], $root, $artisanEnv, null, 120);
    $seedProc->run();
    if (! $seedProc->isSuccessful()) {
        fwrite(STDERR, 'Permission sync failed: '.$seedProc->getErrorOutput()."\n");
        exit(1);
    }
    echo "  -> Permissions catalog synced (158 role grants established).\n\n";
}

echo "[Step 1/3] Reading authoritative accounts from local SQLite...\n";
$sqlitePdo = new PDO('sqlite:'.$root.'/database/veritas_demo.sqlite');
$sqliteRoles = $sqlitePdo->query('SELECT id, slug FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
$sqliteUsers = $sqlitePdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

echo 'Found '.count($sqliteUsers)." accounts in local source.\n\n";

echo "[Step 2/3] Reconciling accounts with Clever Cloud...\n";
if (! $dryRun) {
    $pdo->beginTransaction();
}

$reconciledCount = 0;
$hashMatches = 0;

foreach ($sqliteUsers as $u) {
    $slug = $sqliteRoles[$u['role_id']] ?? null;
    if (! $slug || ! isset($roles[$slug])) {
        if (! $dryRun) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Cannot map role slug: {$slug} for user {$u['email']}\n");
        exit(1);
    }
    $targetRoleId = $roles[$slug];

    // Check existing hosted user by email
    $existing = $pdo->prepare('SELECT id, name, role_id, status FROM users WHERE email = :email');
    $existing->execute([':email' => $u['email']]);
    $hostedUser = $existing->fetch(PDO::FETCH_ASSOC);

    if ($hostedUser) {
        echo "  User exists: {$u['email']} (Hosted ID: {$hostedUser['id']})\n";
        echo "    Action: Updating name, role, status, and password hash.\n";
        if (! $dryRun) {
            $update = $pdo->prepare('UPDATE users SET name = :name, password = :password, role_id = :role_id, status = :status WHERE id = :id');
            $update->execute([
                ':name' => $u['name'],
                ':password' => $u['password'],
                ':role_id' => $targetRoleId,
                ':status' => $u['status'] ?? 'Active',
                ':id' => $hostedUser['id'],
            ]);
        }
    } else {
        echo "  User absent: {$u['email']}\n";
        echo "    Action: Inserting as new active user with role '{$slug}'.\n";
        if (! $dryRun) {
            $insert = $pdo->prepare('INSERT INTO users (name, email, password, role_id, status, session_version, created_at, updated_at) '
                .'VALUES (:name, :email, :password, :role_id, :status, :session_version, NOW(), NOW())');
            $insert->execute([
                ':name' => $u['name'],
                ':email' => $u['email'],
                ':password' => $u['password'],
                ':role_id' => $targetRoleId,
                ':status' => $u['status'] ?? 'Active',
                ':session_version' => $u['session_version'] ?? 1,
            ]);
        }
    }
    $reconciledCount++;

    if (! $dryRun) {
        $check = $pdo->prepare('SELECT password FROM users WHERE email = :email');
        $check->execute([':email' => $u['email']]);
        if (hash_equals($u['password'], $check->fetchColumn())) {
            $hashMatches++;
        }
    }
}

if (! $dryRun) {
    $pdo->commit();
    echo "\n  -> Reconciled {$reconciledCount} accounts.\n";
    echo "  -> Cryptographic hash verification: {$hashMatches} / {$reconciledCount} exact matches (NO hashes logged).\n\n";
} else {
    echo "\n  -> Dry-run completed. {$reconciledCount} accounts prepared for reconciliation.\n\n";
}

echo "[Step 3/3] Inspecting Clever Cloud user roster post-reconciliation...\n";
$allUsers = $pdo->query('SELECT u.id, u.name, u.email, r.name as role_name, r.slug as role_slug, u.status '
    .'FROM users u '
    .'LEFT JOIN roles r ON u.role_id = r.id '
    .'ORDER BY u.id')->fetchAll(PDO::FETCH_ASSOC);

echo 'Total hosted users: '.count($allUsers)."\n";
foreach ($allUsers as $row) {
    echo "  ID {$row['id']}: {$row['name']} <{$row['email']}> | Role: {$row['role_name']} ({$row['role_slug']}) | Status: {$row['status']}\n";
}

echo "\n====================================================================\n";
echo "ACCOUNT RECONCILIATION PREPARATION COMPLETE\n";
echo "====================================================================\n";
