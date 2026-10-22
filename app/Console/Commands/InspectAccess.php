<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InspectAccess extends Command
{
    protected $signature = 'veritas:inspect-access';

    protected $description = 'Read-only session configuration, schema and account role/grant diagnostics (no secrets)';

    public function handle(): int
    {
        $this->line('Configuration belongs to the runtime executing this command, not necessarily Render.');
        $this->line(json_encode([
            'app_url' => config('app.url'),
            'app_key_present' => filled(config('app.key')),
            'configuration_cached' => app()->configurationIsCached(),
            'session_driver' => config('session.driver'),
            'session_domain' => config('session.domain'),
            'session_secure_cookie' => config('session.secure'),
            'session_same_site' => config('session.same_site'),
            'trusted_proxies' => config('trustedproxy.proxies'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $connection = DB::connection(config('session.connection'));
            $columns = $connection->getSchemaBuilder()->getColumnListing(config('session.table'));
            $missing = array_diff(['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'], $columns);
            $this->line('Session table: '.($missing ? 'missing required columns: '.implode(', ', $missing) : 'required columns present'));
            // Never select passwords, remember tokens, session payloads or emails.
            $users = DB::table('users')->leftJoin('roles', 'roles.id', '=', 'users.role_id')
                ->select('users.id', 'users.status', 'roles.slug')->orderBy('users.id')->get();
            $grants = DB::table('roles')->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->select('roles.slug', 'permissions.name')->get()->groupBy('slug');
            foreach ($users as $user) {
                $actual = $grants->get($user->slug, collect())->pluck('name')->all();
                // Production seeding deliberately does not newly grant posting.
                $expected = array_diff(config('rbac.grants.'.$user->slug, []), ['bookkeeping.post']);
                $this->line(json_encode([
                    'user_id' => $user->id, 'status' => $user->status, 'role' => $user->slug,
                    'recognized_role' => array_key_exists($user->slug ?? '', config('rbac.roles')),
                    'missing_baseline_grants' => array_values(array_diff($expected, $actual)),
                    'additional_grants' => array_values(array_diff($actual, config('rbac.grants.'.$user->slug, []))),
                    'posting_granted' => in_array('bookkeeping.post', $actual, true),
                ], JSON_THROW_ON_ERROR));
            }
            $this->line('Differences require review; custom revocations may be intentional. No changes made.');

            return $missing ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Database inspection failed. Check connectivity and schema privately; exception details suppressed.');

            return self::FAILURE;
        }
    }
}
