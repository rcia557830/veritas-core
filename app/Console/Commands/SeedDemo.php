<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DemoDatabase;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

class SeedDemo extends Command
{
    protected $signature = 'demo:seed {--confirm : Explicitly permit fictional demo data}';

    protected $description = 'Prepare the isolated Phase 6 demo with privately entered account passwords';

    public function handle(): int
    {
        DemoDatabase::check();
        if (! $this->option('confirm')) {
            $this->error('Pass --confirm to explicitly permit demo data.');

            return self::FAILURE;
        }
        foreach (DemoDataSeeder::ROLES as $role) {
            $email = $role.'@demo.example.test';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            if (! $this->input->isInteractive()) {
                $this->error('New demo accounts require interactive private password entry.');

                return self::FAILURE;
            }
            do {
                $password = $this->secret('Choose a private password (12+ characters, at most 72 bytes) for '.$email);
            } while (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72);
            config(['demo.passwords.'.$role => $password]);
        }
        try {
            $result = $this->call('db:seed', ['--class' => DemoDataSeeder::class]);
        } finally {
            config(['demo.passwords' => []]);
        }
        if ($result !== self::SUCCESS) {
            return self::FAILURE;
        }
        $this->info('Demo ready. Existing records and credentials are preserved on repeat runs.');

        return self::SUCCESS;
    }
}
