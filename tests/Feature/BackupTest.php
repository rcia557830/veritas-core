<?php

namespace Tests\Feature;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupStore;
use App\Services\Backup\FileBackup;
use App\Services\Backup\RestoreVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function tempPath(string $label): string
    {
        $path = sys_get_temp_dir().'/veritas-'.$label.'-'.uniqid().'.sqlite';
        $this->tempFiles[] = $path;

        return $path;
    }

    private function makeSourceDatabase(): string
    {
        $path = $this->tempPath('source');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR NOT NULL)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, slug VARCHAR)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, email VARCHAR, role_id INTEGER, status VARCHAR, FOREIGN KEY (role_id) REFERENCES roles(id))');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, firm_name VARCHAR)');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, business_name VARCHAR)');
        $pdo->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, title VARCHAR, FOREIGN KEY (client_id) REFERENCES clients(id))');
        $pdo->exec("INSERT INTO migrations (migration) VALUES ('0001_init')");
        $pdo->exec("INSERT INTO roles (name, slug) VALUES ('Owner','owner'),('Bookkeeper','bookkeeper'),('Office Manager','office-manager')");
        $pdo->exec("INSERT INTO users (name, email, role_id, status) VALUES ('Restore Owner','owner@restore.local',1,'Active')");
        $pdo->exec("INSERT INTO settings (firm_name) VALUES ('SYNTHETIC RESTORE FIRM')");
        $pdo->exec("INSERT INTO clients (business_name) VALUES ('Restore Client A'),('Restore Client B')");
        $pdo->exec("INSERT INTO documents (client_id, title) VALUES (1,'Restore Document')");

        return $path;
    }

    public function test_database_backup_command_fails_cleanly_for_in_memory_sqlite(): void
    {
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('SQLite-only failure path.');
        }

        $this->artisan('veritas:backup:database')->assertFailed();
    }

    public function test_database_backup_succeeds_against_mysql(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires the verified disposable MySQL server.');
        }

        $this->artisan('veritas:backup:database', ['--retention' => '14'])->assertSuccessful();
    }

    public function test_restore_reports_a_missing_mysql_binary(): void
    {
        $file = $this->makeSourceDatabase();
        $target = ['database' => 'veritas_restore_x', 'host' => '127.0.0.1', 'port' => '3306', 'username' => 'u', 'password' => 'p'];

        $this->expectException(BackupException::class);
        RestoreVerification::restoreMysql($target, $file, 'C:/nonexistent/mysql.exe');
    }

    public function test_backup_artifacts_are_stored_outside_the_public_web_root(): void
    {
        $this->assertSame(storage_path('app/private/backups'), BackupStore::basePath());
        $this->assertFalse(str_starts_with(BackupStore::basePath(), public_path()));
    }

    public function test_file_backup_produces_a_nonempty_archive_and_manifest(): void
    {
        $root = sys_get_temp_dir().'/veritas-backup-root-'.uniqid();
        mkdir($root.'/documents', 0700, true);
        file_put_contents($root.'/documents/evidence.pdf', 'SYNTHETIC FILE BACKUP');
        config(['filesystems.disks.local.root' => $root]);

        $result = app(FileBackup::class)->run(['retention' => 14]);
        $artifact = BackupStore::basePath().'/files/'.$result['file'];
        $this->tempFiles[] = $artifact;
        $this->tempFiles[] = $artifact.'.json';

        $this->assertSame('ok', $result['status']);
        $this->assertFileExists($artifact);
        $this->assertGreaterThan(0, filesize($artifact));
        $this->assertFileExists($artifact.'.json');
    }

    public function test_restore_preflight_refuses_the_primary_database(): void
    {
        $primary = ['driver' => 'mysql', 'database' => 'veritas_core_db', 'host' => '127.0.0.1', 'port' => '3306'];
        $target = ['driver' => 'mysql', 'database' => 'veritas_core_db', 'host' => '127.0.0.1', 'port' => '3306'];

        $this->expectException(BackupException::class);
        RestoreVerification::preflight($primary, $target, 'testing', false);
    }

    public function test_restore_preflight_refuses_production_without_force(): void
    {
        $primary = ['driver' => 'mysql', 'database' => 'veritas_core_db', 'host' => '127.0.0.1', 'port' => '3306'];
        $target = ['driver' => 'mysql', 'database' => 'veritas_restore_abc', 'host' => '127.0.0.1', 'port' => '3306'];

        $this->expectException(BackupException::class);
        RestoreVerification::preflight($primary, $target, 'production', false);
    }

    public function test_restore_preflight_requires_disposable_target_naming(): void
    {
        $primary = ['driver' => 'mysql', 'database' => 'veritas_core_db', 'host' => '127.0.0.1', 'port' => '3306'];
        $target = ['driver' => 'mysql', 'database' => 'random_target', 'host' => '127.0.0.1', 'port' => '3306'];

        $this->expectException(BackupException::class);
        RestoreVerification::preflight($primary, $target, 'testing', false);
    }

    public function test_restore_preflight_allows_a_disposable_target(): void
    {
        $primary = ['driver' => 'mysql', 'database' => 'veritas_core_db', 'host' => '127.0.0.1', 'port' => '3306'];
        $target = ['driver' => 'mysql', 'database' => 'veritas_restore_123abc', 'host' => '127.0.0.1', 'port' => '3306'];

        RestoreVerification::preflight($primary, $target, 'testing', false);
        $this->addToAssertionCount(1);
    }

    public function test_sqlite_restore_verification_recovers_representative_data(): void
    {
        $source = $this->makeSourceDatabase();
        $backup = $this->tempPath('backup');
        copy($source, $backup);
        $target = $this->tempPath('restored');

        $this->artisan('veritas:restore:verify', [
            '--file' => $backup,
            '--target' => $target,
            '--driver' => 'sqlite',
        ])->assertSuccessful();

        // Post-restore application connectivity: the restored DB is readable.
        $pdo = new PDO('sqlite:'.$target);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertSame('SYNTHETIC RESTORE FIRM', $pdo->query('SELECT firm_name FROM settings LIMIT 1')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());

        $report = RestoreVerification::verify($pdo);
        $this->assertTrue($report['migrations_present']);
        $this->assertTrue($report['foreign_keys_enforced']);
        $this->assertSame([], $report['foreign_key_violations']);
        $this->assertSame(1, $report['row_counts']['users']);
    }
}
