<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Deliberately local-only. No production import mode exists in this tool.
$root = dirname(__DIR__);
require "$root/vendor/autoload.php";
if (is_file("$root/bootstrap/cache/config.php")) {
    throw new RuntimeException('Use an uncached checkout.');
}
$token = getenv('VERITAS_REHEARSAL_TOKEN');
$name = getenv('DB_DATABASE');
if (! $token || ! preg_match('/^[a-f0-9]{32}$/', $token) || $name !== 'veritas_rehearsal_'.$token || getenv('DB_HOST') !== '127.0.0.1' || (int) getenv('DB_PORT') < 20000) {
    throw new RuntimeException('Disposable local target required.');
}
$source = realpath($argv[1] ?? "$root/database/veritas_demo.sqlite");
if (! $source) {
    throw new RuntimeException('Source missing.');
}
$before = hash_file('sha256', $source);
$sqlite = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sqlite->exec('PRAGMA query_only=ON');
$sqlite->beginTransaction();
if ($sqlite->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $sqlite->query('PRAGMA foreign_key_check')->fetch()) {
    throw new RuntimeException('Source integrity failed.');
}
$app = require "$root/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();
$mysql = DB::connection()->getPdo();
$actual = str_replace('\\', '/', $mysql->query('SELECT @@datadir')->fetchColumn());
$expected = str_replace('\\', '/', getenv('VERITAS_REHEARSAL_DATADIR'));
if (rtrim($actual, '/') !== rtrim($expected, '/') || ! str_contains($actual, $token)) {
    throw new RuntimeException('Server identity mismatch.');
}
if ($mysql->query('SHOW TABLES')->fetch()) {
    throw new RuntimeException('Target must be empty.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Schema migration failed.');
}
$tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$targetTables = $mysql->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($targetTables);
if ($tables !== $targetTables) {
    throw new RuntimeException('Table sets differ.');
}
$triggers = [];
foreach ($mysql->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
    $triggers[$trigger['Trigger']] = 'CREATE TRIGGER `'.$trigger['Trigger'].'` '.$trigger['Timing'].' '.$trigger['Event'].' ON `'.$trigger['Table'].'` FOR EACH ROW '.$trigger['Statement'];
}
// Empty disposable target only: load historical posted rows, then restore guards.
foreach ($triggers as $trigger => $ddl) {
    $mysql->exec("DROP TRIGGER `$trigger`");
}
$mysql->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) {
    $mysql->exec("TRUNCATE TABLE `$table`");
}
$mysql->beginTransaction();
try {
    foreach ($tables as $table) {
        $rows = $sqlite->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (! $rows) {
            continue;
        }
        $columns = array_keys($rows[0]);
        $stmt = $mysql->prepare("INSERT INTO `$table` (`".implode('`,`', $columns).'`) VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
        }
    }
    $mysql->commit();
} catch (Throwable $error) {
    $mysql->rollBack();
    throw $error;
}
$mysql->exec('SET FOREIGN_KEY_CHECKS=1');
foreach ($triggers as $ddl) {
    $mysql->exec($ddl);
}
$sequencesChecked = 0;
foreach ($sqlite->query('SELECT name,seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC) as $sequence) {
    if (! in_array($sequence['name'], $tables, true)) {
        throw new RuntimeException('Unexpected source sequence.');
    }
    $table = $sequence['name'];
    $next = (int) $sequence['seq'] + 1;
    $mysql->exec("ALTER TABLE `$table` AUTO_INCREMENT=$next");
    $query = $mysql->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $query->execute([$table]);
    if ((int) $query->fetchColumn() < $next) {
        throw new RuntimeException('Auto-increment high-water mark was lost.');
    }
    $sequencesChecked++;
}
function canonicalJson(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        $properties = get_object_vars($value);
        ksort($properties);

        return (object) array_map('canonicalJson', $properties);
    }
    if (is_array($value)) {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map('canonicalJson', $value);
    }

    return $value;
}
$counts = [];
foreach ($tables as $table) {
    $columns = $mysql->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    $normalize = function (array $row) use ($columns): string {
        $normalized = [];
        foreach ($columns as $column) {
            $value = $row[$column['Field']];
            $type = $column['Type'];
            if ($value !== null) {
                if ($type === 'json') {
                    $value = canonicalJson(json_decode((string) $value, false, 512, JSON_THROW_ON_ERROR));
                } elseif (preg_match('/^decimal\(\d+,(\d+)\)/', $type, $m)) {
                    $decimal = bcadd((string) $value, '0', (int) $m[1]);
                    $scale = strlen(explode('.', (string) $value, 2)[1] ?? '');
                    if (bccomp((string) $value, $decimal, max($scale, (int) $m[1])) !== 0) {
                        throw new RuntimeException('Decimal precision would be lost.');
                    }
                    $value = $decimal;
                } elseif ($type === 'date') {
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/', (string) $value)) {
                        throw new RuntimeException('Non-midnight time would be lost in DATE conversion.');
                    }
                    $value = substr((string) $value, 0, 10);
                } else {
                    $value = (string) $value;
                }
            }
            $normalized[$column['Field']] = $value;
        }

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    };
    $a = array_map($normalize, $sqlite->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC));
    $b = array_map($normalize, $mysql->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC));
    sort($a);
    sort($b);
    if ($a !== $b) {
        file_put_contents(dirname(getenv('VERITAS_REHEARSAL_REPORT')).'/difference.json', json_encode(['table' => $table, 'source' => array_values(array_diff($a, $b)), 'target' => array_values(array_diff($b, $a))], JSON_PRETTY_PRINT));
        throw new RuntimeException("Row comparison failed: $table (private difference report saved)");
    }
    $counts[$table] = count($a);
}
// Re-enabling FK checks does not validate existing rows; explicitly check each FK.
$foreignKeys = $mysql->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
foreach ($foreignKeys as $fk) {
    $t = $fk['TABLE_NAME'];
    $c = $fk['COLUMN_NAME'];
    $p = $fk['REFERENCED_TABLE_NAME'];
    $k = $fk['REFERENCED_COLUMN_NAME'];
    if ($mysql->query("SELECT COUNT(*) FROM `$t` c LEFT JOIN `$p` p ON c.`$c`=p.`$k` WHERE c.`$c` IS NOT NULL AND p.`$k` IS NULL")->fetchColumn()) {
        throw new RuntimeException("Foreign key mismatch: $t.$c");
    }
}
$sourceUsers = $sqlite->query('SELECT id,password FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$targetUsers = $mysql->query('SELECT id,password FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (count($sourceUsers) !== 4 || $sourceUsers !== $targetUsers) {
    throw new RuntimeException('Credential preservation failed.');
}
$guardsChecked = 0;
foreach (["UPDATE ledger_entries SET status=status WHERE status='Posted' LIMIT 1", 'UPDATE audit_logs SET id=id LIMIT 1', 'DELETE FROM security_events LIMIT 1'] as $sql) {
    $mysql->beginTransaction();
    try {
        $mysql->exec($sql);
        throw new RuntimeException('Expected immutable-history trigger to reject mutation.');
    } catch (PDOException $error) {
        if ($error->getCode() !== '45000') {
            throw $error;
        }
        $guardsChecked++;
    } finally {
        $mysql->rollBack();
    }
}
$sqlite->rollBack();
if (! hash_equals($before, hash_file('sha256', $source))) {
    throw new RuntimeException('Source changed during rehearsal.');
}
$report = ['source_sha256' => $before, 'mysql_version' => $mysql->query('SELECT VERSION()')->fetchColumn(), 'counts' => $counts, 'all_rows_match' => true, 'password_hashes_exact' => true, 'foreign_keys_checked' => count($foreignKeys), 'triggers_restored' => count($triggers), 'sequence_high_water_marks_checked' => $sequencesChecked, 'immutability_probes_passed' => $guardsChecked, 'source_unchanged' => true];
file_put_contents(getenv('VERITAS_REHEARSAL_REPORT'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'PASS: all tables and rows match; four password hashes exact; foreign keys valid; triggers restored; source unchanged.'.PHP_EOL;
