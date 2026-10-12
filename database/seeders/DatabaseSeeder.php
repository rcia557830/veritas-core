<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development seed accounts are disabled outside local/testing environments.');
        }
        $this->call(StaffSeeder::class);
        $adminRole = Role::where('slug', 'owner')->firstOrFail();
        User::firstOrCreate(['email' => 'owner@veritascore.local'], ['name' => 'System Owner', 'password' => 'password123', 'role_id' => $adminRole->id, 'status' => 'Active']);
        $this->call(SampleDataSeeder::class);
    }
}
