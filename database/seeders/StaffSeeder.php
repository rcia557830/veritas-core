<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Development accounts only; production provisioning will use invitations. */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development seed accounts are disabled outside local/testing environments.');
        }

        $this->call(PermissionSeeder::class);

        foreach ([
            ['owner@veritascore.local', 'System Owner', 'owner'],
            ['bookkeeper@veritascore.local', 'Sample Bookkeeper', 'bookkeeper'],
            ['manager@veritascore.local', 'Sample Office Manager', 'office-manager'],
        ] as [$email, $name, $role]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password123',
                'role_id' => Role::where('slug', $role)->firstOrFail()->id,
                'status' => 'Active',
            ]);
        }
    }
}
