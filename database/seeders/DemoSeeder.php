<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('demo.enabled')) {
            throw new \RuntimeException('Demo seeding requires DEMO_MODE=true.');
        }
        $email = config('demo.owner_email');
        $password = config('demo.staff_password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 10) {
            throw new \RuntimeException('Set DEMO_OWNER_EMAIL and a DEMO_STAFF_PASSWORD of at least 10 characters.');
        }
        User::where('email', $email)->whereHas('role', fn ($query) => $query->where('slug', 'owner'))->firstOrFail();
        DB::transaction(function () use ($password) {
            $this->call(PermissionSeeder::class);
            foreach ([
                ['manager@veritascore.local', 'Sample Office Manager', 'office-manager'],
                ['bookkeeper@veritascore.local', 'Sample Bookkeeper', 'bookkeeper'],
            ] as [$email, $name, $role]) {
                User::firstOrCreate(['email' => $email], [
                    'name' => $name, 'password' => $password,
                    'role_id' => Role::where('slug', $role)->firstOrFail()->id,
                    'status' => 'Active',
                ]);
            }
            $this->call(SampleDataSeeder::class);
        });
    }
}
