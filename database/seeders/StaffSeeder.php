<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development seed accounts are disabled outside local/testing environments.');
        }

        $this->call(PermissionSeeder::class);

        foreach ([
            ['email' => 'manager@veritascore.local', 'name' => 'Sample Office Manager', 'role' => 'office-manager'],
            ['email' => 'bookkeeper@veritascore.local', 'name' => 'Sample Bookkeeper', 'role' => 'bookkeeper'],
        ] as $account) {
            User::firstOrCreate(['email' => $account['email']], [
                'name' => $account['name'],
                'password' => 'password123',
                'role_id' => Role::where('slug', $account['role'])->firstOrFail()->id,
                'status' => 'Active',
            ]);
        }
    }
}
