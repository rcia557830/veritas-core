<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development seed accounts are disabled outside local/testing environments.');
        }
        $this->call([StaffSeeder::class, SampleDataSeeder::class]);
    }
}
