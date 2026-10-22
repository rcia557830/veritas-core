<?php

// Read-only inventory. Never boot Laravel, print hashes, or mutate either source.
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
$sources = [];
$hashes = [];
$inspect = function (PDO $db, string $label) use (&$sources, &$hashes): void {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $db->exec($sqlite ? 'PRAGMA query_only=ON' : 'SET SESSION TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $roles = $db->query('SELECT * FROM roles')->fetchAll(PDO::FETCH_ASSOC);
        $roles = array_column($roles, null, 'id');
        $users = [];
        foreach ($db->query('SELECT * FROM users', PDO::FETCH_ASSOC) as $row) {
            $hashes[$label][$row['email']] = $row['password'];
            $users[] = [
                'id' => $row['id'], 'name' => $row['name'], 'email' => $row['email'],
                'role' => $roles[$row['role_id']]['slug'] ?? $roles[$row['role_id']]['name'] ?? null,
                'role_id' => $row['role_id'], 'status' => $row['status'] ?? null,
                'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null,
                'session_version' => $row['session_version'] ?? null,
                'password_present' => ! empty($row['password']),
                'password_algorithm' => password_get_info($row['password'])['algoName'],
                'development_email' => (bool) preg_match('/@(?:.*\.test|veritascore\.local)$/i', $row['email']),
            ];
        }
        $grants = [];
        foreach ($db->query('SELECT pr.role_id, p.name FROM permission_role pr JOIN permissions p ON p.id = pr.permission_id ORDER BY pr.role_id, p.name', PDO::FETCH_ASSOC) as $grant) {
            $role = $roles[$grant['role_id']]['slug'] ?? $roles[$grant['role_id']]['name'];
            $grants[$role][] = $grant['name'];
        }
        $sources[$label] = ['users' => $users, 'grants_by_role' => $grants, 'migration_count' => (int) $db->query('SELECT COUNT(*) FROM migrations')->fetchColumn()];
    } finally {
        $db->rollBack();
    }
};

foreach (['veritas_demo.sqlite' => 'local_demo_sqlite'] as $file => $label) {
    if (is_file($root.'/database/'.$file)) {
        $inspect(new PDO('sqlite:'.$root.'/database/'.$file), $label);
    }
}
if (($env['DB_CONNECTION'] ?? '') === 'mysql' && in_array($env['DB_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    try {
        $port = (int) ($env['DB_PORT'] ?? 3306);
        $database = $env['DB_DATABASE'] ?? '';
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new RuntimeException('Unsupported local database name');
        }
        $inspect(new PDO("mysql:host={$env['DB_HOST']};port={$port};dbname={$database};charset=utf8mb4", $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_TIMEOUT => 5]), 'configured_local_mysql');
    } catch (Throwable $error) {
        $sources['configured_local_mysql'] = ['inspection' => 'unavailable; exception omitted to protect connection details'];
    }
}
$comparisons = [];
foreach ($hashes['configured_local_mysql'] ?? [] as $email => $hash) {
    $other = $hashes['local_demo_sqlite'][$email] ?? null;
    $comparisons[] = ['email' => $email, 'present_in_demo' => $other !== null, 'stored_hashes_equal' => $other !== null && hash_equals($hash, $other)];
}
$report = ['sources' => $sources, 'comparison' => $comparisons, 'authority' => 'Requires owner confirmation and comparison with hosted accounts; timestamps do not prove password authority.'];
$path = $root.'/storage/app/private/selective-account-inventory.json';
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
foreach ($sources as $label => $source) {
    echo $label.': '.(isset($source['users']) ? count($source['users']).' users; '.count(array_filter($source['users'], fn ($u) => $u['development_email'])).' development-domain emails' : $source['inspection']).PHP_EOL;
    foreach ($source['users'] ?? [] as $user) {
        echo '  ID '.$user['id'].' / '.$user['role'].' / '.$user['status'].' / updated '.$user['updated_at'].PHP_EOL;
    }
    foreach ($source['grants_by_role'] ?? [] as $role => $grants) {
        echo '  '.$role.': '.count($grants).' permissions'.PHP_EOL;
    }
}
echo 'Hash comparison: '.count($comparisons).' shared-email candidates examined; '.count(array_filter($comparisons, fn ($c) => $c['stored_hashes_equal'])).' exact matches.'.PHP_EOL;
echo "Private identity inventory saved; no passwords or hashes exported.\n";
