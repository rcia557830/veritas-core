# RBAC: complete implementation files

See `RBAC.md` for upgrade commands and the permission matrix. All files are already installed under `C:\laragon\www\veritas-core`. These are complete final files, including the subsequent usability refinements; do not paste them into the old static frontend or Flutter project.

## 1. Database and permission defaults

### `C:\laragon\www\veritas-core\config\rbac.php`

```php
<?php

return [
    'permissions' => [
        'client.view', 'client.create', 'client.update', 'client.assign', 'client.archive', 'client.restore',
        'document.view', 'document.upload', 'document.update', 'document.validate', 'document.approve', 'document.reject', 'document.download', 'document.archive',
        'bookkeeping.view', 'bookkeeping.create', 'bookkeeping.update', 'bookkeeping.submit', 'bookkeeping.review', 'bookkeeping.approve', 'bookkeeping.delete',
        'compliance.view', 'compliance.create', 'compliance.update', 'compliance.assign', 'compliance.file', 'compliance.archive',
        'notice.view', 'notice.create', 'notice.update', 'notice.publish', 'notice.archive',
        'billing.view', 'billing.create', 'billing.update', 'billing.issue', 'billing.payment', 'billing.cancel',
        'knowledge.view', 'knowledge.create', 'knowledge.update', 'knowledge.publish', 'knowledge.archive',
        'report.view', 'report.generate', 'report.export', 'report.print',
        'user.view', 'user.create', 'user.update', 'user.role', 'user.activate', 'user.deactivate', 'user.reset-password',
        'workspace.manage', 'audit.view',
    ],
    'roles' => ['owner' => 'Owner', 'bookkeeper' => 'Bookkeeper', 'office-manager' => 'Office Manager'],
    'grants' => [
        'bookkeeper' => [
            'client.view', 'client.create', 'client.update',
            'document.view', 'document.upload', 'document.update', 'document.download',
            'bookkeeping.view', 'bookkeeping.create', 'bookkeeping.update', 'bookkeeping.submit', 'bookkeeping.delete',
            'compliance.view', 'compliance.update',
            'billing.view', 'billing.create', 'billing.update', 'billing.issue', 'billing.payment',
            'knowledge.view', 'knowledge.create', 'knowledge.update',
            'report.view', 'report.generate', 'report.export', 'report.print',
        ],
        'office-manager' => [
            'client.view', 'client.create', 'client.update', 'client.assign', 'client.archive', 'client.restore',
            'document.view', 'document.upload', 'document.update', 'document.validate', 'document.approve', 'document.reject', 'document.download',
            'bookkeeping.view', 'bookkeeping.review', 'bookkeeping.approve',
            'compliance.view', 'compliance.create', 'compliance.update', 'compliance.assign', 'compliance.file',
            'notice.view', 'notice.create', 'notice.update', 'notice.publish', 'notice.archive',
            'billing.view', 'billing.create', 'billing.update', 'billing.issue', 'billing.payment',
            'knowledge.view', 'knowledge.create', 'knowledge.update', 'knowledge.publish', 'knowledge.archive',
            'report.view', 'report.generate', 'report.export', 'report.print',
        ],
    ],
];
```

### `C:\laragon\www\veritas-core\database\migrations\2026_10_02_000001_add_three_role_permissions_and_notices.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->string('slug')->nullable()->unique());
        // Preserve user IDs, passwords and assignments when upgrading the original roles.
        foreach (DB::table('roles')->get() as $role) {
            $name = ['Administrator' => 'Owner', 'Staff' => 'Bookkeeper'][$role->name] ?? $role->name;
            DB::table('roles')->where('id', $role->id)->update(['name' => $name, 'slug' => Str::slug($name)]);
        }
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id']);
        });
        Schema::create('notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('body');
            $t->string('status')->default('Draft')->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        DB::table('roles')->where('slug', 'owner')->update(['name' => 'Administrator']);
        DB::table('roles')->where('slug', 'bookkeeper')->update(['name' => 'Staff']);
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('slug'));
    }
};
```

### `C:\laragon\www\veritas-core\database\seeders\RoleSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('rbac.roles') as $slug => $name) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
```

### `C:\laragon\www\veritas-core\database\seeders\PermissionSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        DB::transaction(function () {
            foreach (config('rbac.permissions') as $name) {
                Permission::firstOrCreate(['name' => $name]);
            }
            foreach (config('rbac.roles') as $slug => $name) {
                $grants = $slug === 'owner' ? config('rbac.permissions') : config('rbac.grants.'.$slug, []);
                Role::where('slug', $slug)->firstOrFail()->permissions()->sync(Permission::whereIn('name', $grants)->pluck('id'));
            }
        });
    }
}
```

### `C:\laragon\www\veritas-core\database\seeders\DatabaseSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development seed accounts are disabled outside local/testing environments.');
        }
        $this->call(PermissionSeeder::class);
        $adminRole = Role::where('slug', 'owner')->firstOrFail();
        $staffRole = Role::where('slug', 'bookkeeper')->firstOrFail();
        $admin = User::firstOrCreate(['email' => 'owner@veritascore.local'], ['name' => 'System Owner', 'password' => 'password123', 'role_id' => $adminRole->id, 'status' => 'Active']);
        $staff = User::firstOrCreate(['email' => 'bookkeeper@veritascore.local'], ['name' => 'Sample Bookkeeper', 'password' => 'password123', 'role_id' => $staffRole->id, 'status' => 'Active']);
        User::firstOrCreate(['email' => 'manager@veritascore.local'], ['name' => 'Sample Office Manager', 'password' => 'password123', 'role_id' => Role::where('slug', 'office-manager')->firstOrFail()->id, 'status' => 'Active']);
        Setting::firstOrCreate(['id' => 1], ['firm_name' => 'RBCIA Accounting Firm', 'firm_address' => 'Davao City, Philippines', 'firm_email' => 'office@example.com', 'currency' => 'PHP', 'page_size' => 10, 'notifications_enabled' => true]);
        $names = ['Davao Prime Trading', 'Lanang Café Group', 'Calinan Growers Cooperative', 'Southline Professional Services', 'Matina Builders', 'Northpoint Logistics'];
        foreach ($names as $index => $name) {
            $client = Client::firstOrCreate(['client_code' => 'CL-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)], ['business_name' => $name, 'business_type' => ['Sole Proprietorship', 'Corporation', 'Cooperative', 'Partnership'][$index % 4], 'contact_person' => ['Mara Santos', 'Paolo Reyes', 'Elena Cruz'][$index % 3], 'email' => 'accounts'.($index + 1).'@example.com', 'registration_status' => 'On file', 'business_license_status' => 'On file', 'status' => 'Active', 'created_by' => $admin->id, 'assigned_to' => $staff->id]);
            Document::firstOrCreate(['document_number' => 'DOC-'.($index + 1)], ['client_id' => $client->id, 'title' => 'Monthly supporting records', 'document_type' => 'Receipt', 'status' => ['Submitted', 'Under Review', 'Needs Clarification', 'Reviewed', 'Approved', 'Submitted'][$index], 'received_date' => today()->subDays(3), 'due_date' => today()->addDays(2), 'notes' => 'Confirm the received records with the client.', 'uploaded_by' => $staff->id]);
            ComplianceRecord::firstOrCreate(['client_id' => $client->id, 'requirement' => 'Periodic report preparation'], ['agency' => ['BIR', 'SEC', 'CDA', 'BIR', 'LGU', 'SSS'][$index], 'reporting_period' => today()->format('Y-m'), 'due_date' => today()->addDays([-2, 3, 8, 14, 21, 1][$index]), 'status' => 'Pending', 'assigned_to' => $staff->id, 'created_by' => $admin->id, 'notes' => 'Internal planning date. Verify the applicable deadline before filing.']);
            $ledger = LedgerEntry::firstOrCreate(['reference_number' => 'OR-'.($index + 101)], ['client_id' => $client->id, 'transaction_date' => today()->subDays(2), 'description' => 'Office supplies purchase', 'status' => 'For Review', 'created_by' => $staff->id]);
            if (! $ledger->items()->exists()) {
                $ledger->items()->createMany([['account_name' => 'Office Supplies Expense', 'debit' => '2500.00', 'credit' => '0.00'], ['account_name' => 'Cash', 'debit' => '0.00', 'credit' => '2500.00']]);
            }
            $invoice = Invoice::firstOrCreate(['invoice_number' => 'INV-'.today()->year.'-'.($index + 1)], ['client_id' => $client->id, 'invoice_date' => today()->subDays(15), 'due_date' => today()->addDays($index === 0 ? -3 : 10), 'tax' => '0.00', 'status' => 'Open', 'created_by' => $admin->id]);
            if (! $invoice->items()->exists()) {
                $invoice->items()->create(['description' => 'Monthly accounting services', 'quantity' => '1.00', 'unit_price' => '6500.00']);
            }
            if ($index === 2 && ! $invoice->payments()->exists()) {
                $invoice->payments()->create(['payment_date' => today(), 'amount' => '6500.00', 'payment_method' => 'Bank Transfer', 'reference_number' => 'PAY-001', 'recorded_by' => $staff->id]);
            }
        }
        KnowledgeArticle::firstOrCreate(['slug' => 'ten-day-preparation'], ['title' => 'Start preparation 10 days ahead', 'category' => 'Compliance', 'content' => 'Request supporting information at least 10 days before the confirmed deadline. Assign a reviewer, resolve missing information, and retain the filing acknowledgment. Always verify the deadline applicable to the client.', 'tags' => ['preparation', 'filing'], 'status' => 'Published', 'author_id' => $admin->id]);
        KnowledgeArticle::firstOrCreate(['slug' => 'document-intake'], ['title' => 'From intake to approved document', 'category' => 'Documentation', 'content' => 'Log each document against its client. Move it to Under Review when work begins. Use Needs Clarification for missing or unreadable material. Mark it Reviewed after checking and Approved when ready for the client file.', 'tags' => ['documents', 'quality'], 'status' => 'Published', 'author_id' => $admin->id]);
    }
}
```

## 2. Models

### `C:\laragon\www\veritas-core\app\Models\Role.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'slug'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
```

### `C:\laragon\www\veritas-core\app\Models\Permission.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['name'];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
```

### `C:\laragon\www\veritas-core\app\Models\User.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'status', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'last_login_at' => 'datetime'];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->status === 'Active' && $this->role?->slug === $role;
    }

    public function hasAnyRole(array|string $roles): bool
    {
        foreach ((array) $roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->status === 'Active' && (bool) $this->role?->permissions()->where('name', $permission)->exists();
    }

    public function hasAnyPermission(array|string $permissions): bool
    {
        foreach ((array) $permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function clients()
    {
        return $this->hasMany(Client::class, 'created_by');
    }

    public function assignedClients()
    {
        return $this->hasMany(Client::class, 'assigned_to');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class, 'created_by');
    }

    public function knowledgeArticles()
    {
        return $this->hasMany(KnowledgeArticle::class, 'author_id');
    }
}
```

### `C:\laragon\www\veritas-core\app\Models\Notice.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notice extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'title', 'body', 'status', 'created_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

## 3. Middleware, policies and registration

### `C:\laragon\www\veritas-core\app\Http\Middleware\ActiveAccount.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->status !== 'Active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your account is inactive. Contact an administrator.']);
        }

        return $next($request);
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Middleware\PermissionMiddleware.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        foreach ($permissions as $permission) {
            abort_unless($request->user()?->hasPermission($permission), 403);
        }

        return $next($request);
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Middleware\RoleMiddleware.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        abort_unless($request->user()?->hasAnyRole($roles), 403);

        return $next($request);
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\BillingPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BillingPolicy extends RecordPolicy
{
    protected string $permission = 'billing';

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && $record->status === 'Draft' && ! $record->payments()->exists();
    }

    public function issue(User $user, Invoice $record): bool
    {
        return $this->update($user, $record) && $user->hasPermission('billing.issue');
    }

    public function recordPayment(User $user, Invoice $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('billing.payment');
    }

    public function cancel(User $user, Invoice $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('billing.cancel');
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\BookkeepingPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BookkeepingPolicy extends RecordPolicy
{
    protected string $permission = 'bookkeeping';

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && in_array($record->status, ['Draft', 'Needs Correction']) && ($user->hasRole('owner') || $record->created_by === $user->id);
    }

    public function submit(User $user, LedgerEntry $record): bool
    {
        return $this->update($user, $record) && $user->hasPermission('bookkeeping.submit');
    }

    public function review(User $user, LedgerEntry $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('bookkeeping.review') && $record->created_by !== $user->id;
    }

    public function approve(User $user, LedgerEntry $record): bool
    {
        return $this->review($user, $record) && $user->hasPermission('bookkeeping.approve');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('bookkeeping.delete') && $record->status === 'Draft' && ($user->hasRole('owner') || $record->created_by === $user->id);
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\ClientPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy extends RecordPolicy
{
    protected string $permission = 'client';

    public function archive(User $user, Client $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('client.archive');
    }

    public function restore(User $user, Client $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('client.restore');
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\CompliancePolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\ComplianceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CompliancePolicy extends RecordPolicy
{
    protected string $permission = 'compliance';

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && (! $user->hasRole('bookkeeper') || $record->status !== 'Filed');
    }

    public function assign(User $user, ComplianceRecord $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('compliance.assign');
    }

    public function file(User $user, ComplianceRecord $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('compliance.file');
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\DocumentPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DocumentPolicy extends RecordPolicy
{
    protected string $permission = 'document';

    public function create(User $user): bool
    {
        return $user->hasPermission('document.upload');
    }

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && (! $user->hasRole('bookkeeper') || in_array($record->status, ['Submitted', 'Needs Clarification', 'Rejected']));
    }

    public function validate(User $user, Document $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('document.validate');
    }

    public function approve(User $user, Document $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('document.approve');
    }

    public function reject(User $user, Document $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('document.reject');
    }

    public function download(User $user, Document $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('document.download');
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\KnowledgePolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class KnowledgePolicy extends RecordPolicy
{
    protected string $permission = 'knowledge';

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && (! $user->hasRole('bookkeeper') || ($record->author_id === $user->id && $record->status === 'Draft'));
    }

    public function publish(User $user, KnowledgeArticle $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('knowledge.publish');
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\NoticePolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Notice;
use App\Models\User;

class NoticePolicy extends RecordPolicy
{
    protected string $permission = 'notice';

    public function publish(User $user, Notice $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('notice.publish');
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\RecordPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\KnowledgeArticle;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordPolicy
{
    protected string $permission;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->permission.'.view');
    }

    public function view(User $user, Model $record): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }
        if ($user->hasAnyRole(['owner', 'office-manager'])) {
            return true;
        }
        if (! $user->hasRole('bookkeeper')) {
            return false;
        }
        if ($record instanceof KnowledgeArticle) {
            return $record->status === 'Published' || ($record->author_id === $user->id && $record->status === 'Draft');
        }
        if ($record instanceof Notice) {
            return false;
        }
        $client = $record instanceof Client ? $record : $record->client;

        return $client && $client->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission($this->permission.'.create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission($this->permission.'.update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission($this->permission.'.archive');
    }
}
```

### `C:\laragon\www\veritas-core\app\Policies\UserPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasRole('owner') && $user->hasPermission('user.'.$permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, User $record): bool
    {
        return $this->allows($user, 'update');
    }

    public function changeRole(User $user, User $record): bool
    {
        return $this->allows($user, 'role') && $user->id !== $record->id;
    }

    public function activate(User $user, User $record): bool
    {
        return $this->allows($user, 'activate');
    }

    public function deactivate(User $user, User $record): bool
    {
        return $this->allows($user, 'deactivate') && $user->id !== $record->id;
    }

    public function resetPassword(User $user, User $record): bool
    {
        return $this->allows($user, 'reset-password');
    }
}
```

### `C:\laragon\www\veritas-core\bootstrap\app.php`

```php
<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['active' => ActiveAccount::class, 'role' => RoleMiddleware::class, 'permission' => PermissionMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {})
    ->create();
```

### `C:\laragon\www\veritas-core\app\Providers\AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Register outside web.php so record binding also works with cached routes.
        Route::bind('record', function ($value, $route) {
            $module = explode('.', $route->getName())[0];
            $model = Modules::get($module)['model'];

            return $model::findOrFail($value);
        });
        Paginator::useBootstrapFive();
        Gate::define('owner', fn ($user) => $user->hasRole('owner'));
        foreach (config('rbac.permissions') as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }
        foreach ([Client::class => 'ClientPolicy', Document::class => 'DocumentPolicy', LedgerEntry::class => 'BookkeepingPolicy', ComplianceRecord::class => 'CompliancePolicy', Invoice::class => 'BillingPolicy', KnowledgeArticle::class => 'KnowledgePolicy', Notice::class => 'NoticePolicy', User::class => 'UserPolicy'] as $model => $policy) {
            Gate::policy($model, 'App\\Policies\\'.$policy);
        }
        View::composer(['layouts.app', 'billing.print'], function ($view) {
            $view->with('firm', Setting::first());
        });
    }
}
```

## 4. Routes, authorization and business actions

### `C:\laragon\www\veritas-core\routes\web.php`

```php
<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:10,1')->name('login.store');
    Route::get('/forgot-password', [AuthController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
Route::middleware(['auth', 'active', 'role:owner,bookkeeper,office-manager'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile.edit');
    Route::patch('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/clients/{record}/archive', [ClientController::class, 'archive'])->name('clients.archive');
    Route::get('/documents/{record}/validate', [DocumentController::class, 'validation'])->middleware('permission:document.validate')->name('documents.validation');
    Route::post('/documents/{record}/validate', [DocumentController::class, 'validateDocument'])->middleware('permission:document.validate')->name('documents.validate');
    Route::get('/documents/{record}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::post('/ledger/{record}/transition', [LedgerController::class, 'transition'])->name('ledger.transition');
    Route::post('/billing/{record}/transition', [InvoiceController::class, 'transition'])->name('billing.transition');
    Route::post('/billing/{record}/payments', [PaymentController::class, 'store'])->name('billing.payments.store');
    Route::get('/billing/{record}/print', [InvoiceController::class, 'print'])->name('billing.print');
    Route::get('/billing/{record}/pdf', [InvoiceController::class, 'pdf'])->name('billing.pdf');
    foreach (['clients' => ClientController::class, 'documents' => DocumentController::class, 'ledger' => LedgerController::class, 'compliance' => ComplianceController::class, 'billing' => InvoiceController::class, 'knowledge' => KnowledgeController::class] as $module => $controller) {
        Route::resource($module, $controller)->parameters([$module => 'record'])->except($module === 'billing' ? ['destroy'] : []);
    }
    Route::get('/search', SearchController::class)->middleware('throttle:90,1')->name('search');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'all'])->name('notifications.all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:report.view,report.generate')->name('reports.index');
    Route::get('/reports/csv', [ReportController::class, 'csv'])->middleware('permission:report.export')->name('reports.csv');
    Route::middleware('permission:notice.view')->group(function () {
        Route::post('/notices/{notice}/publish', [NoticeController::class, 'publish'])->name('notices.publish');
        Route::post('/notices/{notice}/archive', [NoticeController::class, 'archive'])->name('notices.archive');
        Route::resource('notices', NoticeController::class)->except('destroy');
    });
    Route::middleware('role:owner')->group(function () {
        Route::get('/workspace', [SettingController::class, 'edit'])->middleware('permission:workspace.manage')->name('workspace.edit');
        Route::put('/workspace', [SettingController::class, 'update'])->middleware('permission:workspace.manage')->name('workspace.update');
        Route::resource('admin/users', UserController::class)->names('admin.users')->except(['show', 'destroy']);
        Route::post('/admin/users/{user}/reset-password', [UserController::class, 'reset'])->name('admin.users.reset');
        Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('admin.audit.index');
    });
});
```

### `C:\laragon\www\veritas-core\app\Support\Modules.php`

```php
<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Notice;

final class Modules
{
    public static function all(): array
    {
        return [
            'clients' => ['model' => Client::class, 'title' => 'Clients', 'singular' => 'client', 'icon' => 'people', 'label' => 'business_name', 'date' => 'created_at', 'search' => ['client_code', 'business_name', 'contact_person', 'email', 'tin'], 'columns' => ['client_code' => 'Client code', 'business_name' => 'Business name', 'business_type' => 'Type', 'status' => 'Status'],
                'fields' => ['business_name' => ['Business name', 'text', true], 'business_type' => ['Business type', ['Sole Proprietorship', 'Partnership', 'Corporation', 'Cooperative', 'Professional', 'Other'], true], 'contact_person' => ['Contact person', 'text'], 'email' => ['Email', 'email'], 'phone' => ['Phone', 'text'], 'tin' => ['TIN', 'text'], 'address' => ['Address', 'textarea'], 'registration_status' => ['Registration', ['Pending', 'On file'], true], 'business_license_status' => ['Business license', ['Pending', 'On file'], true], 'status' => ['Status', ['Active', 'Inactive', 'Archived'], true], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Active', 'Inactive', 'Archived']],
            'documents' => ['model' => Document::class, 'title' => 'Documents', 'singular' => 'document', 'icon' => 'folder2-open', 'label' => 'title', 'date' => 'received_date', 'search' => ['document_number', 'title', 'document_type', 'notes'], 'columns' => ['document_number' => 'Reference', 'title' => 'Document', 'client' => 'Client', 'received_date' => 'Received', 'status' => 'Status'],
                'fields' => ['title' => ['Title', 'text', true], 'document_type' => ['Document type', ['Certificate of Registration', 'Business Permit', 'BIR Document', 'Tax Return', 'Receipt', 'Invoice', 'Bank Statement', 'Financial Statement', 'Government Form', 'Supporting Document', 'Other'], true], 'status' => ['Status', ['Submitted', 'Under Review', 'Needs Clarification', 'Reviewed', 'Approved', 'Rejected'], true], 'received_date' => ['Received date', 'date', true], 'due_date' => ['Follow-up date', 'date'], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Submitted', 'Under Review', 'Needs Clarification', 'Reviewed', 'Approved', 'Rejected']],
            'ledger' => ['model' => LedgerEntry::class, 'title' => 'Ledger Review', 'singular' => 'transaction', 'icon' => 'journal-text', 'label' => 'description', 'date' => 'transaction_date', 'search' => ['reference_number', 'description', 'notes'], 'columns' => ['transaction_date' => 'Date', 'reference_number' => 'Reference', 'description' => 'Description', 'client' => 'Client', 'status' => 'Status'],
                'fields' => ['transaction_date' => ['Transaction date', 'date', true], 'reference_number' => ['Reference', 'text'], 'description' => ['Description', 'text', true], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Draft', 'For Review', 'Reviewed', 'Needs Correction']],
            'compliance' => ['model' => ComplianceRecord::class, 'title' => 'Compliance', 'singular' => 'requirement', 'icon' => 'calendar2-check', 'label' => 'requirement', 'date' => 'due_date', 'search' => ['agency', 'requirement', 'reporting_period', 'reference_number', 'notes'], 'columns' => ['requirement' => 'Requirement', 'client' => 'Client', 'agency' => 'Agency', 'due_date' => 'Due date', 'status' => 'Status'],
                'fields' => ['agency' => ['Agency', ['BIR', 'SEC', 'DTI', 'CDA', 'LGU', 'BOA', 'DOLE', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Other'], true], 'requirement' => ['Requirement', 'text', true], 'reporting_period' => ['Reporting period', 'text'], 'due_date' => ['Due date', 'date', true], 'status' => ['Status', ['Pending', 'In Preparation', 'Ready for Filing', 'Filed'], true], 'filed_date' => ['Filed date', 'date'], 'reference_number' => ['Filing reference', 'text'], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Pending', 'In Preparation', 'Ready for Filing', 'Filed', 'Overdue']],
            'billing' => ['model' => Invoice::class, 'title' => 'Billing', 'singular' => 'invoice', 'icon' => 'receipt', 'label' => 'invoice_number', 'date' => 'invoice_date', 'search' => ['invoice_number', 'notes'], 'columns' => ['invoice_number' => 'Invoice', 'client' => 'Client', 'due_date' => 'Due date', 'total_amount' => 'Total', 'balance' => 'Balance', 'status' => 'Status'],
                'fields' => ['invoice_date' => ['Invoice date', 'date', true], 'due_date' => ['Due date', 'date', true], 'tax' => ['Tax amount (PHP)', 'number', true], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Draft', 'Open', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled']],
            'knowledge' => ['model' => KnowledgeArticle::class, 'title' => 'Knowledge', 'singular' => 'article', 'icon' => 'book', 'label' => 'title', 'date' => 'created_at', 'search' => ['title', 'category', 'content', 'tags'], 'columns' => ['title' => 'Title', 'category' => 'Category', 'status' => 'Status', 'created_at' => 'Created'],
                'fields' => ['title' => ['Title', 'text', true], 'category' => ['Category', ['Accounting', 'Taxation', 'Client Service', 'BIR', 'SEC', 'Compliance', 'Internal Procedure', 'Billing', 'Documentation', 'Other'], true], 'status' => ['Status', ['Draft', 'Published', 'Archived'], true], 'tags' => ['Tags (comma separated)', 'text'], 'content' => ['Content', 'textarea', true]], 'statuses' => ['Draft', 'Published', 'Archived']],
        ];
    }

    public static function permissionFor(string $model): string
    {
        return [Client::class => 'client', Document::class => 'document', LedgerEntry::class => 'bookkeeping', ComplianceRecord::class => 'compliance', Invoice::class => 'billing', KnowledgeArticle::class => 'knowledge', Notice::class => 'notice'][$model];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? abort(404);
    }
}
```

### `C:\laragon\www\veritas-core\app\Services\Access.php`

```php
<?php

namespace App\Services;

use App\Models\Client;
use App\Models\KnowledgeArticle;
use App\Models\Notice;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function query(string $model, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        $query = $model::query();
        $prefix = Modules::permissionFor($model);
        if (! $user?->hasPermission($prefix.'.view')) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasAnyRole(['owner', 'office-manager'])) {
            return $query;
        }
        if (! $user->hasRole('bookkeeper')) {
            return $query->whereRaw('1 = 0');
        }
        if ($model === KnowledgeArticle::class) {
            return $query->where(fn ($q) => $q->where('status', 'Published')->orWhere(fn ($own) => $own->where('author_id', $user->id)->where('status', 'Draft')));
        }
        if ($model === Notice::class) {
            return $query->whereRaw('1 = 0');
        }
        if ($model === Client::class) {
            return $query->where('assigned_to', $user->id);
        }

        return $query->whereHas('client', fn ($q) => $q->where('assigned_to', $user->id));
    }

    public static function client(int $id): Client
    {
        return static::query(Client::class)->findOrFail($id);
    }
}
```

### `C:\laragon\www\veritas-core\app\Services\RecordInput.php`

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RecordInput
{
    // Field-level checks also apply to forged ordinary CRUD requests, not only workflow endpoints.
    public static function authorize(string $module, User $user, array $input, $record = null): void
    {
        $changed = function (string $key) use ($input, $record): bool {
            if (! array_key_exists($key, $input)) {
                return false;
            }
            abort_if(is_array($input[$key]) || is_object($input[$key]), 422, 'Invalid field value.');
            $old = $record?->$key;
            if ($old instanceof \DateTimeInterface) {
                $old = $old->format('Y-m-d');
            }

            return (string) ($input[$key] ?? '') !== (string) ($old ?? '');
        };
        if (in_array($module, ['ledger', 'billing']) && array_key_exists('status', $input)) {
            abort(403, 'Use the authorized workflow action to change status.');
        }
        if ($module === 'clients') {
            if ($changed('assigned_to')) {
                Gate::authorize('client.assign');
            }
            if ($changed('status') && ($input['status'] ?? '') === 'Archived') {
                Gate::authorize('client.archive');
            }
            if ($changed('status') && $record?->status === 'Archived') {
                Gate::authorize('client.restore');
            }
        }
        if ($module === 'documents') {
            if (isset($input['file'])) {
                Gate::authorize('document.upload');
            }
            if ($changed('status')) {
                $permission = match ($input['status'] ?? '') {
                    'Approved' => 'document.approve','Rejected' => 'document.reject',
                    'Under Review','Reviewed','Needs Clarification' => 'document.validate',default => null
                };
                if ($permission) {
                    Gate::authorize($permission);
                }
            }
        }
        if ($module === 'compliance') {
            if ($changed('assigned_to')) {
                Gate::authorize('compliance.assign');
            }
            if ($changed('status') && ($input['status'] ?? '') === 'Filed') {
                Gate::authorize('compliance.file');
            }
            if ($user->hasRole('bookkeeper')) {
                foreach (['client_id', 'agency', 'requirement', 'reporting_period', 'due_date', 'filed_date', 'reference_number', 'assigned_to'] as $key) {
                    abort_if($changed($key), 403, 'Bookkeepers may update preparation status and notes only.');
                }
            }
        }
        if ($module === 'knowledge' && $changed('status')) {
            if (($input['status'] ?? '') === 'Published') {
                Gate::authorize('knowledge.publish');
            }
            if (($input['status'] ?? '') === 'Archived') {
                Gate::authorize('knowledge.archive');
            }
        }
    }
}
```

### `C:\laragon\www\veritas-core\app\Services\RecordWriter.php`

```php
<?php

namespace App\Services;

use App\Http\Requests\RecordRequest;
use App\Models\User;
use App\Support\Modules;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordWriter
{
    public function save(string $module, RecordRequest $request, $record = null)
    {
        $data = $request->validated();
        $path = null;
        $oldPath = $record?->file_path;
        if ($record) {
            Gate::authorize('update', $record);
        }
        if (! $record) {
            Gate::authorize('create', Modules::get($module)['model']);
        }
        RecordInput::authorize($module, $request->user(), $data, $record);
        if (isset($data['client_id'])) {
            Access::client((int) $data['client_id']);
        }
        if ($module === 'clients') {
            if (! $record) {
                $data['created_by'] = $request->user()->id;
            }
            if (! $request->user()->hasPermission('client.assign')) {
                $data['assigned_to'] = $request->user()->id;

            }
        }
        if ($module === 'compliance' && ! empty($data['assigned_to'])) {
            $client = Access::client((int) ($data['client_id'] ?? $record->client_id));
            $assignee = User::findOrFail($data['assigned_to']);
            if (! $assignee->hasAnyRole(['owner', 'office-manager']) && $client->assigned_to !== $assignee->id) {
                throw ValidationException::withMessages(['assigned_to' => 'Assign this requirement to the client’s assigned bookkeeper, Office Manager or Owner.']);
            }
        }
        if ($module === 'compliance' && ! $request->user()->hasRole('bookkeeper') && ($data['status'] ?? '') !== 'Filed') {
            $data['filed_date'] = null;
        }
        $items = $data['items'] ?? [];
        unset($data['items'],$data['file']);
        if ($module === 'ledger') {
            foreach ($items as $index => $item) {
                $d = Money::cents($item['debit']);
                $c = Money::cents($item['credit']);
                if (($d > 0) == ($c > 0)) {
                    throw ValidationException::withMessages(["items.$index.debit" => 'Each line must contain either a positive debit or a positive credit.']);
                }
            }
        }
        if ($module === 'billing') {
            $total = Money::cents($data['tax']);
            foreach ($items as $item) {
                $total += (int) round(Money::cents($item['quantity']) * Money::cents($item['unit_price']) / 100);
            }
            if ($total <= 0 || $total > 99999999999) {
                throw ValidationException::withMessages(['items' => 'Invoice total must be positive and at most PHP 999,999,999.99.']);
            }
        }
        if ($module === 'knowledge') {
            $data['tags'] = array_values(array_filter(array_map('trim', explode(',', $data['tags'] ?? ''))));
            if (! $record) {
                $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(8));
                $data['author_id'] = $request->user()->id;
            }
        }
        if (! $record) {
            $prefix = ['clients' => ['client_code', 'CL'], 'documents' => ['document_number', 'DOC'], 'billing' => ['invoice_number', 'INV']][$module] ?? null;
            if ($prefix) {
                $data[$prefix[0]] = $prefix[1].'-'.now()->format('Y').'-'.Str::upper(Str::random(8));
            }
            if (in_array($module, ['ledger', 'billing', 'compliance'])) {
                $data['created_by'] = $request->user()->id;
            }
            if ($module === 'documents') {
                $data['uploaded_by'] = $request->user()->id;
            }
        }
        try {
            if ($module === 'documents' && $request->hasFile('file')) {
                $file = $request->file('file');
                $path = $file->store('documents', 'local');
                $data['file_path'] = $path;
                $data['original_file_name'] = $file->getClientOriginalName();
                $data['mime_type'] = $file->getMimeType();
            }
            $saved = DB::transaction(function () use ($module, $record, $data, $items, $request) {
                $model = Modules::get($module)['model'];
                $previousStatus = null;
                if ($record) {
                    $record = $model::lockForUpdate()->findOrFail($record->id);
                    Gate::authorize('update', $record);
                    RecordInput::authorize($module, $request->user(), $data, $record);
                    $previousStatus = $record->status;
                    $record->update($data);
                } else {
                    Gate::authorize('create', $model);
                    $record = $model::create($data);
                }
                if (in_array($module, ['ledger', 'billing'])) {
                    $record->items()->delete();
                    $record->items()->createMany($items);
                }
                Audit::record($record->wasRecentlyCreated ? 'created' : 'updated', $module, $record, ucfirst(Modules::get($module)['singular']).' saved; status: '.($record->status ?? '').'.');

                if ($previousStatus !== $record->status) {
                    if ($module === 'documents' && $record->status !== 'Submitted') {
                        Audit::record('document.validated', $module, $record, 'Document decision: '.$record->status.'.');
                    }
                    if ($module === 'compliance' && $record->status === 'Filed') {
                        Audit::record('compliance.filed', $module, $record, 'Requirement filed; reference: '.$record->reference_number.'.');
                    }
                    if ($module === 'knowledge' && $record->status === 'Published') {
                        Audit::record('knowledge.published', $module, $record, 'Knowledge article published.');
                    }
                }

                return $record;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $error;
        }
        if ($path && $oldPath) {
            Storage::disk('local')->delete($oldPath);
        }
        if ($module === 'documents') {
            Notify::record($saved, 'documents', 'Document '.$saved->status.': '.$saved->title);
        }
        if ($module === 'compliance') {
            Notify::record($saved, 'compliance', 'Compliance assignment: '.$saved->requirement);
        }

        return $saved;
    }
}
```

### `C:\laragon\www\veritas-core\app\Services\Audit.php`

```php
<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Notice;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function visible(): Builder
    {
        $user = auth()->user();
        $query = AuditLog::query();
        if ($user->hasRole('owner') && $user->hasPermission('audit.view')) {
            return $query;
        }

        // Recent-activity cards must not expose records from restricted modules.
        return $query->where(function ($q) use ($user) {
            $q->where(fn ($own) => $own->where('module', 'auth')->where('user_id', $user->id));
            $modules = Modules::all();
            $modules['notices'] = ['model' => Notice::class];
            foreach ($modules as $module => $config) {
                if (! $user->hasPermission(Modules::permissionFor($config['model']).'.view')) {
                    continue;
                }
                $q->orWhere(fn ($records) => $records->where('module', $module)->whereIn('record_id', Access::query($config['model'], $user)->select('id')));
            }
        });
    }

    public static function record(string $action, string $module, ?Model $record, string $description): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'client_id' => $record instanceof Client ? $record->id : $record?->client_id,
            'action' => $action, 'module' => $module, 'record_id' => $record?->id, 'description' => $description, 'ip_address' => request()->ip()]);
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Requests\RecordRequest.php`

```php
<?php

namespace App\Http\Requests;

use App\Services\RecordInput;
use App\Support\Modules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('record');
        $model = Modules::get($this->module())['model'];
        Gate::authorize($record ? 'update' : 'create', $record ?? $model);
        RecordInput::authorize($this->module(), $this->user(), $this->all(), $record);

        return true;
    }

    public function module(): string
    {
        return explode('.', $this->route()->getName())[0];
    }

    protected function getRedirectUrl()
    {
        $record = $this->route('record');

        return $record ? route($this->module().'.edit', $record) : route($this->module().'.create');
    }

    public function rules(): array
    {
        $module = $this->module();
        if ($module === 'compliance' && $this->user()->hasRole('bookkeeper')) {
            return ['status' => 'required|in:Pending,In Preparation,Ready for Filing', 'notes' => 'nullable|string|max:30000'];
        }
        $config = Modules::get($module);
        $rules = [];
        foreach ($config['fields'] as $name => $field) {
            $rule = [($field[2] ?? false) ? 'required' : 'nullable'];
            if (is_array($field[1])) {
                $rule[] = Rule::in($field[1]);
            } elseif ($field[1] === 'date') {
                $rule[] = 'date_format:Y-m-d';
            } elseif ($field[1] === 'number') {
                $rule = array_merge($rule, ['numeric', 'min:0', 'max:999999999.99', 'decimal:0,2']);
            } else {
                $rule = array_merge($rule, ['string', 'max:'.($field[1] === 'textarea' ? 30000 : 255)]);
            }
            $rules[$name] = $rule;
        }
        if (! in_array($module, ['clients', 'knowledge'])) {
            $rules['client_id'] = ['required', 'integer', 'exists:clients,id'];
        }
        if ($module === 'clients') {
            $rules['email'] = ['nullable', 'email', 'max:255'];
            $rules['phone'] = ['nullable', 'string', 'max:60'];
            $rules['tin'] = ['nullable', 'regex:/^[0-9 -]{9,20}$/'];
            if ($this->user()->hasPermission('client.assign')) {
                $rules['assigned_to'] = ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'Active')];
            }
        }
        if ($module === 'documents') {
            $rules['file'] = ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:20480'];
        }
        if ($module === 'compliance') {
            $rules['assigned_to'] = ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'Active')];
            $rules['filed_date'] = ['nullable', 'required_if:status,Filed', 'date_format:Y-m-d', 'before_or_equal:today'];
            $rules['reference_number'] = ['nullable', 'required_if:status,Filed', 'string', 'max:255'];
        }
        if (in_array($module, ['ledger', 'billing'])) {
            $rules['items'] = ['required', 'array', 'min:'.($module === 'ledger' ? 2 : 1), 'max:100'];
            if ($module === 'ledger') {
                $rules['items.*.account_name'] = ['required', 'string', 'max:255'];
                foreach (['debit', 'credit'] as $key) {
                    $rules['items.*.'.$key] = ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'];
                }
            } else {
                $rules['due_date'] = ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'];
                $rules['items.*.description'] = ['required', 'string', 'max:255'];
                $rules['items.*.quantity'] = ['required', 'numeric', 'min:0.01', 'max:100000', 'decimal:0,2'];
                $rules['items.*.unit_price'] = ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'];
            }
        }

        if ($module === 'clients' && ! $this->user()->hasPermission('client.archive')) {
            unset($rules['status']);
        }

        return $rules;
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\AuditLogController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('audit.view');
        $logs = AuditLog::with('user')->when(Records::searchText($request) !== '', fn ($q) => $q->where('description', 'like', '%'.Records::searchText($request).'%'))->when($request->filled('module'), fn ($q) => $q->where('module', $request->query('module')))->latest()->paginate(Records::pageSize($request))->withQueryString();

        return view('admin.audit', compact('logs'));
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\AuthController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many login attempts. Please try again in a minute.']);
        }
        if (! Auth::attempt($data + ['status' => 'Active'], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The credentials are incorrect or the account is inactive.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);
        Audit::record('login', 'auth', $request->user(), 'User logged in.');

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Audit::record('logout', 'auth', $request->user(), 'User logged out.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgot()
    {
        return view('auth.forgot');
    }

    public function email(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        return back()->with('success', 'If this address has an account, a password reset link has been sent.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)]]);
        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('success', 'Password reset. Sign in with your new password.');
    }

    public function profile()
    {
        return view('auth.profile');
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', Rule::unique('users')->ignore($request->user()->id)], 'current_password' => 'required|current_password', 'password' => ['nullable', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)]]);
        $user = $request->user();
        $user->fill(collect($data)->only(['name', 'email'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $user->save();
        Audit::record('profile', 'users', $user, 'Profile updated.');

        return back()->with('success', 'Profile updated successfully.');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\ClientController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Access;
use App\Services\Audit;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientController extends ModuleController
{
    protected string $module = 'clients';

    public function show($record)
    {
        Gate::authorize('view', $record);
        $related = [];
        foreach (['Documents' => 'documents', 'Ledger Review' => 'ledger', 'Compliance' => 'compliance', 'Billing' => 'billing'] as $label => $module) {
            $model = Modules::get($module)['model'];
            if (! Gate::allows('viewAny', $model)) {
                continue;
            }
            $query = Access::query($model)->where('client_id', $record->id);
            if ($module === 'billing') {
                $query->with(['items', 'payments']);
            }
            $related[$label] = $query->latest()->limit(10)->get();
        }
        $activity = Audit::visible()->where('client_id', $record->id)->with('user')->latest()->limit(20)->get();

        return view('clients.show', $this->context() + compact('record', 'related', 'activity'));
    }

    public function archive($record)
    {
        Gate::authorize($record->status === 'Archived' ? 'restore' : 'archive', $record);
        DB::transaction(function () use ($record) {
            $record = Client::lockForUpdate()->findOrFail($record->id);
            Gate::authorize($record->status === 'Archived' ? 'restore' : 'archive', $record);
            $record->update(['status' => $record->status === 'Archived' ? 'Active' : 'Archived']);
            Audit::record('status', 'clients', $record, 'Client '.$record->status.'.');
        });

        return back()->with('success', 'Client status updated.');
    }

    public function destroy($record)
    {
        return $this->archive($record);
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\ComplianceController.php`

```php
<?php

namespace App\Http\Controllers;

class ComplianceController extends ModuleController
{
    protected string $module = 'compliance';
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\Controller.php`

```php
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\DashboardController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Notice;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Summary;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $stats = Summary::dashboard();
        $deadlines = Access::query(ComplianceRecord::class)->with('client')->where('status', '!=', 'Filed')->orderBy('due_date')->limit(6)->get();
        $clients = Access::query(Client::class)->latest()->limit(5)->get();
        $documents = Access::query(Document::class)->with('client')->latest()->limit(5)->get();
        $invoices = Access::query(Invoice::class)->with(['client', 'items', 'payments'])->latest()->limit(5)->get();
        $activity = Audit::visible()->with('user')->latest()->limit(8)->get();

        $role = auth()->user()->role->name;
        $userCount = auth()->user()->hasRole('owner') && auth()->user()->hasPermission('user.view') ? User::count() : null;
        $noticeCount = auth()->user()->hasPermission('notice.view') ? Access::query(Notice::class)->where('status', 'Published')->count() : null;

        return view('dashboard.index', compact('stats', 'deadlines', 'clients', 'documents', 'invoices', 'activity', 'role', 'userCount', 'noticeCount'));
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\DocumentController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Audit;
use App\Services\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends ModuleController
{
    protected string $module = 'documents';

    public function download($record)
    {
        Gate::authorize('download', $record);
        abort_unless($record->file_path && Storage::disk('local')->exists($record->file_path), 404, 'Attachment not found.');
        Audit::record('download', 'documents', $record, 'Document downloaded.');

        return Storage::disk('local')->download($record->file_path, $record->original_file_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function validation($record)
    {
        Gate::authorize('validate', $record);

        return redirect()->route('documents.show', $record);
    }

    public function validateDocument(Request $request, $record)
    {
        Gate::authorize('validate', $record);
        $data = $request->validate(['status' => 'required|in:Under Review,Reviewed,Approved,Rejected,Needs Clarification', 'notes' => 'nullable|string|max:30000']);
        DB::transaction(function () use ($record, $data) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::authorize('validate', $document);
            if ($data['status'] === 'Approved') {
                Gate::authorize('approve', $document);
            }
            if ($data['status'] === 'Rejected') {
                Gate::authorize('reject', $document);
            }
            $document->update($data);
            Audit::record('document.validated', 'documents', $document, 'Document validated: '.$document->status.'.');
        });
        Notify::record($record->fresh(), 'documents', 'Document '.$data['status'].': '.$record->title);

        return back()->with('success', 'Document validation saved.');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\InvoiceController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Records;
use App\Services\Summary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceController extends ModuleController
{
    protected string $module = 'billing';

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Invoice::class);
        // All authorized invoices, deliberately independent of table query parameters.
        $billingSummary = Summary::billing(Access::query(Invoice::class));
        $records = Records::query('billing', $request)->paginate(Records::pageSize($request))->withQueryString();
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();

        return view('records.index', $this->context() + compact('billingSummary', 'records', 'clients'));
    }

    public function transition(Request $request, $record)
    {
        Gate::authorize('view', $record);
        $data = $request->validate(['action' => 'required|in:issue,cancel']);
        DB::transaction(function () use ($record, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($record->id);
            Gate::authorize($data['action'] === 'issue' ? 'issue' : 'cancel', $invoice);
            if ($data['action'] === 'issue') {
                abort_unless($invoice->status === 'Draft' && $invoice->total_cents > 0, 409);
                $invoice->status = 'Open';
            } else {
                if ($invoice->payments()->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Paid invoices cannot be cancelled.']);
                }abort_if($invoice->status === 'Cancelled', 409);
                $invoice->status = 'Cancelled';
            }
            $invoice->save();
            Audit::record($data['action'], 'billing', $invoice, 'Invoice '.$invoice->status.'.');
        });

        return back()->with('success', 'Invoice status updated.');
    }

    public function print($record)
    {
        Gate::authorize('view', $record);

        return view('billing.print', ['record' => $record]);
    }

    public function pdf($record)
    {
        Gate::authorize('view', $record);

        return Pdf::loadView('billing.print', ['record' => $record, 'pdf' => true])->download($record->invoice_number.'.pdf');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\KnowledgeController.php`

```php
<?php

namespace App\Http\Controllers;

class KnowledgeController extends ModuleController
{
    protected string $module = 'knowledge';
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\LedgerController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\Audit;
use App\Services\Notify;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LedgerController extends ModuleController
{
    protected string $module = 'ledger';

    public function transition(Request $request, $record)
    {
        Gate::authorize('view', $record);
        $data = $request->validate(['action' => 'required|in:submit,review,return', 'notes' => 'nullable|string|max:3000']);
        DB::transaction(function () use ($data, $record) {
            $entry = LedgerEntry::lockForUpdate()->findOrFail($record->id);
            $action = $data['action'];
            Gate::authorize(match ($action) {
                'submit' => 'submit','review' => 'approve',default => 'review'
            }, $entry);
            if ($action === 'submit') {
                abort_unless(in_array($entry->status, ['Draft', 'Needs Correction']), 409);
                $debit = $entry->items->sum(fn ($i) => Money::cents($i->debit));
                $credit = $entry->items->sum(fn ($i) => Money::cents($i->credit));
                if ($debit <= 0 || $debit !== $credit) {
                    throw ValidationException::withMessages(['items' => 'Total debit must equal total credit before submission.']);
                }
                $entry->update(['status' => 'For Review', 'reviewed_by' => null, 'reviewed_at' => null]);
            } else {
                abort_unless($entry->status === 'For Review', 409);
                if ($entry->created_by === auth()->id()) {
                    throw ValidationException::withMessages(['action' => 'A different employee must review this transaction.']);
                }
                $entry->update(['status' => $action === 'review' ? 'Reviewed' : 'Needs Correction', 'notes' => $data['notes'] ?? $entry->notes, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
            }
            Audit::record($action, 'ledger', $entry, 'Ledger status changed to '.$entry->status.'.');
        });
        Notify::record($record->fresh(), 'ledger', 'Ledger review: '.$record->description);

        return back()->with('success', 'Ledger workflow updated.');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\ModuleController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Records;
use App\Services\RecordWriter;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

abstract class ModuleController extends Controller
{
    protected string $module;

    protected function context(): array
    {
        return ['module' => $this->module, 'config' => Modules::get($this->module)];
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Modules::get($this->module)['model']);
        $records = Records::query($this->module, $request)->paginate(Records::pageSize())->withQueryString();

        return view('records.index', $this->context() + compact('records') + ['clients' => Access::query(Client::class)->orderBy('business_name')->limit(500)->get()]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Modules::get($this->module)['model']);
        $model = Modules::get($this->module)['model'];

        return $this->form($request, new $model);
    }

    public function edit(Request $request, $record)
    {
        Gate::authorize('update', $record);

        return $this->form($request, $record);
    }

    protected function form(Request $request, $record)
    {
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();
        $users = User::where('status', 'Active')->orderBy('name')->get();

        $context = $this->context();
        if ($this->module === 'compliance' && $request->user()->hasRole('bookkeeper')) {
            $context['config']['fields'] = array_intersect_key($context['config']['fields'], array_flip(['status', 'notes']));
            $context['config']['fields']['status'][1] = ['Pending', 'In Preparation', 'Ready for Filing'];
        }
        if ($this->module === 'clients' && ! $request->user()->hasPermission('client.archive')) {
            unset($context['config']['fields']['status']);
        }
        if ($this->module === 'knowledge' && ! $request->user()->hasPermission('knowledge.publish')) {
            $context['config']['fields']['status'][1] = ['Draft'];
        }
        if ($this->module === 'documents') {
            $context['config']['fields']['status'][1] = array_values(array_filter($context['config']['fields']['status'][1], function ($status) use ($request, $record) {
                if ($status === 'Submitted' || $status === $record->status) {
                    return true;
                }
                $permission = match ($status) {
                    'Approved' => 'document.approve','Rejected' => 'document.reject',default => 'document.validate'
                };

                return $request->user()->hasPermission($permission);
            }));
        }

        return view($request->ajax() ? 'records.form-content' : 'records.form', $context + compact('record', 'clients', 'users'));
    }

    public function store(RecordRequest $request, RecordWriter $writer)
    {
        $record = $writer->save($this->module, $request);

        return redirect()->route($this->module.'.show', $record)->with('success', ucfirst(Modules::get($this->module)['singular']).' created successfully.');
    }

    public function update(RecordRequest $request, $record, RecordWriter $writer)
    {
        Gate::authorize('update', $record);
        $writer->save($this->module, $request, $record);

        return redirect()->route($this->module.'.show', $record)->with('success', 'Record updated successfully.');
    }

    public function show($record)
    {
        Gate::authorize('view', $record);

        return view('records.show', $this->context() + compact('record'));
    }

    public function destroy($record)
    {
        Gate::authorize('delete', $record);
        DB::transaction(function () use ($record) {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            Gate::authorize('delete', $record);
            Audit::record('archived', $this->module, $record, 'Record removed from active records.');
            $record->delete();
        });

        return redirect()->route($this->module.'.index')->with('success', 'Record archived successfully.');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\NoticeController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Notice;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class NoticeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Notice::class);
        $request->validate(['q' => 'nullable|string|max:150', 'status' => 'nullable|in:Draft,Published,Archived']);
        $notices = Access::query(Notice::class)->with('client')->when(Records::searchText($request) !== '', fn ($q) => $q->where('title', 'like', '%'.Records::searchText($request).'%'))->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))->latest()->paginate(Records::pageSize($request))->withQueryString();

        return view('notices.index', compact('notices'));
    }

    public function create()
    {
        Gate::authorize('create', Notice::class);

        return $this->form(new Notice);
    }

    public function edit(Notice $notice)
    {
        Gate::authorize('update', $notice);

        return $this->form($notice);
    }

    private function form(Notice $notice)
    {
        return view('notices.form', ['notice' => $notice, 'clients' => Access::query(Client::class)->orderBy('business_name')->limit(500)->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Notice::class);

        return $this->save($request, new Notice);
    }

    public function update(Request $request, Notice $notice)
    {
        Gate::authorize('update', $notice);

        return $this->save($request, $notice);
    }

    private function save(Request $request, Notice $notice)
    {
        abort_if($request->hasAny(['status', 'created_by', 'published_at']), 403, 'Use the publish/archive action.');
        $data = $request->validate(['title' => 'required|string|max:255', 'body' => 'required|string|max:30000', 'client_id' => 'nullable|integer|exists:clients,id']);
        if (! empty($data['client_id'])) {
            Access::client($data['client_id']);
        }
        $notice = DB::transaction(function () use ($notice, $data) {
            if ($notice->exists) {
                $notice = Notice::lockForUpdate()->findOrFail($notice->id);
                Gate::authorize('update', $notice);
            } else {
                Gate::authorize('create', Notice::class);
                $notice->created_by = auth()->id();
            }
            // Changes to a posted notice become a draft until explicitly published again.
            $notice->fill($data);
            $notice->status = 'Draft';
            $notice->published_at = null;
            $notice->save();
            Audit::record('notice.saved', 'notices', $notice, 'Notice saved as draft: '.$notice->title.'.');

            return $notice;
        });

        return redirect()->route('notices.show', $notice)->with('success', 'Notice draft saved.');
    }

    public function show(Notice $notice)
    {
        Gate::authorize('view', $notice);

        return view('notices.show', compact('notice'));
    }

    public function publish(Notice $notice)
    {
        Gate::authorize('publish', $notice);
        DB::transaction(function () use ($notice) {
            $notice = Notice::lockForUpdate()->findOrFail($notice->id);
            Gate::authorize('publish', $notice);
            abort_unless($notice->status === 'Draft', 409);
            $notice->update(['status' => 'Published', 'published_at' => now()]);
            Audit::record('notice.published', 'notices', $notice, 'Notice posted in the internal workspace: '.$notice->title.'.');
        });

        return back()->with('success', 'Notice published in the internal workspace.');
    }

    public function archive(Notice $notice)
    {
        Gate::authorize('delete', $notice);
        DB::transaction(function () use ($notice) {
            $notice = Notice::lockForUpdate()->findOrFail($notice->id);
            Gate::authorize('delete', $notice);
            $notice->update(['status' => 'Archived']);
            Audit::record('notice.archived', 'notices', $notice, 'Notice archived.');
        });

        return back()->with('success', 'Notice archived.');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\NotificationController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\Notify;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        Notify::due($request->user());
        $items = [];
        $unread = 0;
        // Authorization is rechecked when assignment changes after notification creation.
        foreach ($request->user()->notifications()->latest()->limit(100)->get() as $notification) {
            $data = $notification->data;
            $module = $data['module'] ?? '';
            if (! isset(Modules::all()[$module])) {
                continue;
            }
            $model = Modules::get($module)['model'];
            $record = $model::find($data['record_id']);
            if (! $record || ! Gate::allows('view', $record)) {
                continue;
            }
            if (! $notification->read_at) {
                $unread++;
            }
            $items[] = ['id' => $notification->id, 'title' => $data['title'], 'client' => $data['client'], 'url' => route($module.'.show', $record), 'read' => (bool) $notification->read_at];
        }

        return response()->json(compact('items', 'unread'));
    }

    public function read(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function all(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\PaymentController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, $record)
    {
        Gate::authorize('recordPayment', $record);
        $data = $request->validate(['payment_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2', 'payment_method' => 'required|in:Cash,Bank Transfer,Cheque,GCash,Other', 'reference_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        DB::transaction(function () use ($record, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($record->id);
            Gate::authorize('recordPayment', $invoice);
            abort_unless($invoice->status === 'Open', 409);
            if (Money::cents($data['amount']) > $invoice->balance_cents) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the remaining balance.']);
            }
            $invoice->payments()->create($data + ['recorded_by' => auth()->id()]);
            Audit::record('payment', 'billing', $invoice, 'Payment recorded: '.Money::format($data['amount']).'.');
        });

        return back()->with('success', 'Payment recorded successfully.');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\ReportController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LedgerItem;
use App\Services\Access;
use App\Services\Records;
use App\Services\Summary;
use App\Support\Display;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('report.view');
        Gate::authorize('report.generate');
        $request->validate(['module' => 'nullable|string|in:'.implode(',', array_keys(Modules::all()))]);
        $module = ($request->query('module') ?: 'clients');
        $config = Modules::get($module);
        Gate::authorize('viewAny', $config['model']);
        $query = Records::query($module, $request);
        $summary = ['Records' => (clone $query)->count()];
        if ($module === 'billing') {
            $summary += Summary::billing(clone $query);
        }
        if ($module === 'ledger') {
            foreach (['debit', 'credit'] as $key) {
                $summary['Total '.$key] = LedgerItem::whereIn('ledger_entry_id', (clone $query)->reorder()->select('id'))->sum($key);
            }
        }
        $records = $query->paginate(Records::pageSize())->withQueryString();
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();

        return view('reports.index', compact('module', 'config', 'records', 'clients', 'summary'));
    }

    public function csv(Request $request)
    {
        Gate::authorize('report.export');
        $request->validate(['module' => 'nullable|string|in:'.implode(',', array_keys(Modules::all()))]);
        $module = ($request->query('module') ?: 'clients');
        $config = Modules::get($module);
        Gate::authorize('viewAny', $config['model']);
        $query = Records::query($module, $request);

        return response()->streamDownload(function () use ($query, $config) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($config['columns']));
            $query->reorder()->chunkById(200, function ($records) use ($out, $config) {
                foreach ($records as $r) {
                    $row = [];
                    foreach ($config['columns'] as $key => $label) {
                        $value = Display::value($r, $key);
                        if (preg_match('/^[\s]*[=+@-]/u', $value)) {
                            $value = "'".$value;
                        }$row[] = $value;
                    }fputcsv($out, $row);
                }
            });
            fclose($out);
        }, $module.'-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\SearchController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\Records;
use App\Support\Modules;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:150']);
        $results = [];
        foreach (Modules::all() as $module => $config) {
            foreach (Records::query($module, $request)->limit(6)->get() as $record) {
                $results[] = [
                    'title' => $record->{$config['label']}, 'type' => $config['title'], 'client' => $record->client?->business_name,
                    'status' => $record->display_status ?? $record->status, 'url' => route($module.'.show', $record), 'icon' => $config['icon']];
            }
        }

        return response()->json($results);
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\SettingController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function edit()
    {
        Gate::authorize('workspace.manage');

        return view('settings.edit', ['setting' => Setting::firstOrFail()]);
    }

    public function update(Request $request)
    {
        Gate::authorize('workspace.manage');
        $data = $request->validate(['firm_name' => 'required|string|max:255', 'firm_address' => 'nullable|string|max:2000', 'firm_email' => 'nullable|email|max:255', 'contact_number' => 'nullable|string|max:60', 'currency' => 'required|in:PHP', 'page_size' => 'required|in:10,25,50', 'notifications_enabled' => 'nullable|boolean', 'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048']);
        $setting = Setting::firstOrFail();
        $data['notifications_enabled'] = $request->boolean('notifications_enabled');
        unset($data['logo']);
        $newPath = null;
        $old = $setting->logo_path;
        try {
            if ($request->hasFile('logo')) {
                $newPath = $request->file('logo')->store('logos', 'public');
                $data['logo_path'] = $newPath;
            }$setting->update($data);
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }throw $e;
        }
        if ($newPath && $old) {
            Storage::disk('public')->delete($old);
        }
        Audit::record('settings', 'workspace', $setting, 'Firm settings updated.');

        return back()->with('success', 'Workspace settings saved.');
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\UserController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $request->validate(['q' => 'nullable|string|max:150', 'status' => 'nullable|in:Active,Inactive']);
        $users = User::with('role')->when(Records::searchText($request) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.Records::searchText($request).'%')->orWhere('email', 'like', '%'.Records::searchText($request).'%')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))->orderBy('name')->paginate(Records::pageSize($request))->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        Gate::authorize('create', User::class);

        return $this->form(new User);
    }

    public function edit(User $user)
    {
        Gate::authorize('update', $user);

        return $this->form($user);
    }

    private function form(User $user)
    {
        return view('admin.users.form', ['user' => $user, 'roles' => Role::whereIn('slug', array_keys(config('rbac.roles')))->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', User::class);

        return $this->save($request, new User);
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('update', $user);

        return $this->save($request, $user);
    }

    private function save(Request $request, User $user)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'password' => [$user->exists ? 'nullable' : 'required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)], 'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereIn('slug', array_keys(config('rbac.roles')))], 'status' => 'required|in:Active,Inactive']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        DB::transaction(function () use ($user, $data) {
            $ownerRole = Role::where('slug', 'owner')->lockForUpdate()->firstOrFail();
            $saved = $user->exists ? User::lockForUpdate()->findOrFail($user->id) : new User;
            Gate::authorize($saved->exists ? 'update' : 'create', $saved->exists ? $saved : User::class);
            if (! $saved->exists || (int) $data['role_id'] !== $saved->role_id) {
                Gate::authorize('changeRole', $saved);
            }
            if (! $saved->exists || $data['status'] !== $saved->status) {
                Gate::authorize($data['status'] === 'Active' ? 'activate' : 'deactivate', $saved);
            }
            $created = ! $saved->exists;
            $oldRole = $saved->role_id;
            $oldStatus = $saved->status;
            $saved->fill($data);
            $saved->save();
            if (! User::where('role_id', $ownerRole->id)->where('status', 'Active')->exists()) {
                throw ValidationException::withMessages(['role_id' => 'At least one active Owner is required.']);
            }
            if (isset($data['password']) || $oldRole !== $saved->role_id || $oldStatus !== $saved->status) {
                DB::table('sessions')->where('user_id', $saved->id)->delete();
                $saved->forceFill(['remember_token' => Str::random(60)])->save();
            }
            Audit::record($created ? 'user.created' : 'user.updated', 'users', $saved, 'Account saved: '.$saved->name.'.');
            if ($oldRole !== $saved->role_id) {
                Audit::record('role.changed', 'users', $saved, 'Role changed to '.$saved->role->name.'.');
            }
            if ($oldStatus !== $saved->status) {
                Audit::record($saved->status === 'Active' ? 'account.activated' : 'account.deactivated', 'users', $saved, 'Account '.$saved->status.'.');
            }
        });

        return redirect()->route('admin.users.index')->with('success', 'User saved successfully.');
    }

    public function reset(User $user)
    {
        Gate::authorize('resetPassword', $user);
        Password::sendResetLink(['email' => $user->email]);
        Audit::record('password-link', 'users', $user, 'Password reset link requested.');

        return back()->with('success', 'Password reset link requested.');
    }
}
```

## 5. Authorized views

### `C:\laragon\www\veritas-core\resources\views\admin\audit.blade.php`

```blade
@extends('layouts.app')
@section('title','Audit Logs')
@section('content')<form method="get" class="filters"><label>Search<input name="q" class="form-control" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Module<select class="form-select" name="module"><option value="">All modules</option>@foreach(['auth','clients','documents','ledger','compliance','billing','knowledge','users','workspace'] as $module)<option @selected(request('module')===$module)>{{ $module }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button></form><div class="panel table-panel table-responsive"><table class="table"><thead><tr><th>Time</th><th>Employee</th><th>Action</th><th>Module</th><th>Description</th><th>IP address</th></tr></thead><tbody>@forelse($logs as $log)<tr><td>{{ $log->created_at->format('M j, Y g:i:s A') }}</td><td>{{ $log->user?->name??'System' }}</td><td>{{ $log->action }}</td><td>{{ $log->module }}</td><td>{{ $log->description }}</td><td>{{ $log->ip_address }}</td></tr>@empty<tr><td colspan="6">No matching activity. Try a different search.</td></tr>@endforelse</tbody></table></div><x-pagination :records="$logs"/>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\admin\users\form.blade.php`

```blade
@extends('layouts.app')
@section('title',$user->exists?'Edit user':'Add user')
@section('content')<section class="panel panel-pad"><form method="post" action="{{ $user->exists?route('admin.users.update',$user):route('admin.users.store') }}" @if($user->exists)data-confirm="Apply these account changes? Deactivating an account will end its access."@endif>@csrf @if($user->exists)@method('PUT')@endif<div class="row"><div class="col-md-6"><x-field name="name" label="Full name" :value="$user->name" :required="true"/></div><div class="col-md-6"><x-field name="email" label="Email address" type="email" :value="$user->email" :required="true"/></div><div class="col-md-6"><x-field name="role_id" label="Role" type="select" :value="$user->role_id" :options="$roles->pluck('name','id')->all()" :required="true"/></div><div class="col-md-6"><x-field name="status" label="Account status" type="select" :value="$user->status??'Active'" :options="['Active'=>'Active','Inactive'=>'Inactive']" :required="true"/></div><div class="col-md-6"><x-field name="password" label="Password (10+ characters; leave blank to keep existing)" type="password" :required="!$user->exists"/></div><div class="col-md-6"><x-field name="password_confirmation" label="Confirm password" type="password" :required="!$user->exists"/></div></div><div class="actions"><a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">Cancel</a><button class="btn btn-primary">Save user</button></div></form></section>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\admin\users\index.blade.php`

```blade
@extends('layouts.app')
@section('title','User Management')
@section('content')<div class="toolbar"><p class="toolbar-copy">Manage employee access</p><a class="btn btn-primary" href="{{ route('admin.users.create') }}">Add user</a></div><form method="get" class="filters"><label>Search<input class="form-control" name="q" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Status<select class="form-select" name="status"><option value="">All</option>@foreach(['Active','Inactive'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button></form>
<div class="panel table-panel table-responsive"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead><tbody>@forelse($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role?->name }}</td><td><x-badge :status="$user->status"/></td><td>{{ $user->last_login_at?->format('M j, Y g:i A')??'Not yet' }}</td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.edit',$user) }}">Edit account</a><form class="d-inline" method="post" action="{{ route('admin.users.reset',$user) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Send reset link</button></form></td></tr>@empty<tr><td colspan="6"><div class="empty-state"><h2>No matching users</h2><p>Try a different search.</p><a class="btn btn-primary" href="{{ route('admin.users.index') }}">Clear search</a></div></td></tr>@endforelse</tbody></table></div><x-pagination :records="$users"/>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\auth\forgot.blade.php`

```blade
@extends('layouts.guest')
@section('title','Forgot password')
@section('content')<h2 class="modal-title">Reset your password</h2><p class="subtext">Enter your account email to request a secure reset link.</p><form method="post" action="{{ route('password.email') }}">@csrf<x-field name="email" label="Email address" type="email" :required="true"/><button class="btn btn-primary">Send reset link</button><a class="btn btn-outline-secondary" href="{{ route('login') }}">Back to sign in</a></form>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\auth\login.blade.php`

```blade
@extends('layouts.guest')
@section('title','Sign in')
@section('content')<h2 class="modal-title">Welcome back</h2><p class="subtext mb-4">Sign in to your workspace.</p><form action="{{ route('login.store') }}" method="post">@csrf<x-field name="email" label="Email address" type="email" :required="true"/><x-field name="password" label="Password" type="password" :required="true"/><div class="form-check mb-4"><input class="form-check-input" name="remember" type="checkbox" id="remember"><label class="form-check-label" for="remember">Remember me</label></div><button class="btn btn-primary w-100">Sign in</button><a href="{{ route('password.request') }}" class="d-block mt-3">Forgot password?</a></form>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\auth\profile.blade.php`

```blade
@extends('layouts.app')
@section('title','Your profile')
@section('content')<section class="panel panel-pad"><h2 class="section-title mb-4">Profile and password</h2><form method="post" action="{{ route('profile.update') }}">@csrf @method('PATCH')<div class="row"><div class="col-md-6"><x-field name="name" label="Full name" :value="auth()->user()->name" :required="true"/></div><div class="col-md-6"><x-field name="email" label="Email address" type="email" :value="auth()->user()->email" :required="true"/></div><div class="col-md-6"><x-field name="current_password" label="Current password to confirm changes" type="password" :required="true"/></div><div class="col-md-6"><x-field name="password" label="New password (optional, at least 10 characters)" type="password"/></div><div class="col-md-6"><x-field name="password_confirmation" label="Confirm new password" type="password"/></div></div><button class="btn btn-primary">Save profile</button></form></section>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\auth\reset.blade.php`

```blade
@extends('layouts.guest')
@section('title','Reset password')
@section('content')<h2 class="modal-title">Choose a new password</h2><form method="post" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><x-field name="email" label="Email address" type="email" :value="$email" :required="true"/><x-field name="password" label="New password (at least 10 characters)" type="password" :required="true"/><x-field name="password_confirmation" label="Confirm password" type="password" :required="true"/><button class="btn btn-primary">Reset password</button></form>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\billing\detail.blade.php`

```blade
@include('billing.items')
<div class="mini-stats"><div class="mini-stat"><strong>{{ \App\Support\Money::format($record->total_amount) }}</strong><span>Total invoice</span></div><div class="mini-stat"><strong>{{ \App\Support\Money::format($record->amount_paid) }}</strong><span>Paid</span></div><div class="mini-stat"><strong>{{ \App\Support\Money::format($record->balance) }}</strong><span>Balance</span></div></div>
<div class="actions mb-4"><a target="_blank" rel="noopener" class="btn btn-outline-secondary" href="{{ route('billing.print',$record) }}">Print invoice</a><a class="btn btn-outline-secondary" href="{{ route('billing.pdf',$record) }}">Download PDF</a>
@can('issue',$record)<form method="post" action="{{ route('billing.transition',$record) }}">@csrf<input type="hidden" name="action" value="issue"><button class="btn btn-primary">Issue invoice</button></form>@endcan
@can('cancel',$record)@if($record->status!=='Cancelled' && !$record->payments()->exists())<form method="post" action="{{ route('billing.transition',$record) }}" data-confirm="Cancel this invoice? This action cannot be reversed.">@csrf<input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger">Cancel invoice</button></form>@endif
@endcan</div>
<h3 class="section-title">Payment history</h3><div class="table-responsive mb-4"><table class="table"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Recorded by</th><th class="numeric">Amount</th></tr></thead><tbody>@forelse($record->payments()->with('recorder')->latest()->get() as $payment)<tr><td>{{ $payment->payment_date->format('M j, Y') }}</td><td>{{ $payment->payment_method }}</td><td>{{ $payment->reference_number }}</td><td>{{ $payment->recorder?->name }}</td><td class="numeric">{{ \App\Support\Money::format($payment->amount) }}</td></tr>@empty<tr><td colspan="5">No payments recorded.</td></tr>@endforelse</tbody></table></div>
@can('recordPayment',$record)@if($record->status==='Open' && $record->balance_cents>0)<h3 class="section-title mb-3">Record payment</h3><form method="post" action="{{ route('billing.payments.store',$record) }}">@csrf<div class="row"><div class="col-md-4"><x-field name="payment_date" label="Payment date" type="date" :value="today()->toDateString()" :required="true"/></div><div class="col-md-4"><x-field name="amount" label="Amount (PHP)" type="number" :required="true"/></div><div class="col-md-4"><x-field name="payment_method" label="Method" type="select" :options="array_combine(['Cash','Bank Transfer','Cheque','GCash','Other'],['Cash','Bank Transfer','Cheque','GCash','Other'])" :required="true"/></div><div class="col-md-6"><x-field name="reference_number" label="Payment reference"/></div><div class="col-md-6"><x-field name="notes" label="Notes"/></div></div><button class="btn btn-primary">Record payment</button></form>@endif
@endcan
```

### `C:\laragon\www\veritas-core\resources\views\billing\items.blade.php`

```blade
<div class="table-responsive"><table class="table"><thead><tr><th>Service</th><th class="numeric">Quantity</th><th class="numeric">Unit price</th><th class="numeric">Amount</th></tr></thead><tbody>@foreach($record->items as $item)<tr><td>{{ $item->description }}</td><td class="numeric">{{ $item->quantity }}</td><td class="numeric">{{ \App\Support\Money::format($item->unit_price) }}</td><td class="numeric">{{ \App\Support\Money::format($item->amount) }}</td></tr>@endforeach</tbody><tfoot><tr><th colspan="3">Subtotal</th><td class="numeric">{{ \App\Support\Money::format(\App\Support\Money::decimal($record->subtotal_cents)) }}</td></tr><tr><th colspan="3">Tax</th><td class="numeric">{{ \App\Support\Money::format($record->tax) }}</td></tr><tr><th colspan="3">Total</th><td class="numeric">{{ \App\Support\Money::format($record->total_amount) }}</td></tr></tfoot></table></div>
```

### `C:\laragon\www\veritas-core\resources\views\billing\print.blade.php`

```blade
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{{ $record->invoice_number }} · VERITAS CORE</title><style>body{font-family:DejaVu Sans,sans-serif;color:#18283e;font-size:12px;margin:32px}h1{color:#18283e;border-bottom:3px solid #c9a55f;padding-bottom:16px}table{width:100%;border-collapse:collapse;margin-top:24px}td,th{text-align:left;padding:12px 8px;border-bottom:1px solid #dfe4ea}.numeric{text-align:right}button{padding:10px}@media print{button{display:none}}</style></head><body>
<h1>VERITAS CORE</h1><h2>{{ $firm?->firm_name }}</h2><p>{{ $firm?->firm_address }}<br>{{ $firm?->firm_email }} · {{ $firm?->contact_number }}</p>
<h2>Invoice {{ $record->invoice_number }}</h2><p>Status: {{ $record->display_status }}<br>Issued: {{ $record->invoice_date->format('M j, Y') }} · Due: {{ $record->due_date->format('M j, Y') }}</p>
<h3>Bill to {{ $record->client->business_name }}</h3><p>{{ $record->client->address }}<br>{{ $record->client->email }}<br>TIN: {{ $record->client->tin }}</p>
@include('billing.items')<p>Amount paid: {{ \App\Support\Money::format($record->amount_paid) }}<br>Balance: {{ \App\Support\Money::format($record->balance) }}</p><p>{{ $record->notes }}</p>
@if(empty($pdf))<button type="button" onclick="window.print()">Print invoice</button>@endif</body></html>
```

### `C:\laragon\www\veritas-core\resources\views\billing\summary.blade.php`

```blade
<section aria-label="Overall billing totals" class="billing-summary mb-4">
<div class="row g-3">
<x-stat label="Total billed" :value="\App\Support\Money::format($billingSummary['billed'])" icon="receipt"/>
<x-stat label="Total collected" :value="\App\Support\Money::format($billingSummary['collected'])" icon="cash-stack" tone="success"/>
<x-stat label="Outstanding balance" :value="\App\Support\Money::format($billingSummary['outstanding'])" icon="wallet2"/>
<x-stat label="Overdue amount" :value="\App\Support\Money::format($billingSummary['overdue'])" icon="exclamation-circle" tone="warning"/>
</div><p class="subtext mt-2 mb-0">Totals for all invoices you can access. Search and filters below affect the table only.</p>
</section>
```

### `C:\laragon\www\veritas-core\resources\views\clients\show.blade.php`

```blade
@extends('layouts.app')
@section('title','Client profile')
@section('content')<div class="toolbar"><a class="btn btn-outline-secondary" href="{{ route('clients.index') }}">← Clients</a><div class="actions"><a data-modal class="btn btn-primary" href="{{ route('clients.edit',$record) }}">Edit client</a>@can($record->status==='Archived'?'restore':'archive',$record)<form method="post" action="{{ route('clients.archive',$record) }}" data-confirm="{{ $record->status==='Archived'?'Restore this client?':'Archive this client? Related records will be retained.' }}">@csrf<button class="btn btn-outline-danger">{{ $record->status==='Archived'?'Restore':'Archive' }}</button></form>@endcan</div></div>
<section class="panel panel-pad mb-4"><div class="toolbar"><h2 class="modal-title">{{ $record->business_name }}</h2><x-badge :status="$record->status"/></div><dl class="detail-grid">@foreach($config['fields'] as $key=>$field)<div><dt>{{ $field[0] }}</dt><dd>{{ \App\Support\Display::value($record,$key) }}</dd></div>@endforeach<div><dt>Assigned employee</dt><dd>{{ $record->assignee?->name??'Unassigned' }}</dd></div></dl></section>
<div class="row g-4">@foreach($related as $label=>$items)@php($key=['Documents'=>'documents','Ledger Review'=>'ledger','Compliance'=>'compliance','Billing'=>'billing'][$label])<div class="col-lg-6"><section class="panel panel-pad"><div class="toolbar"><h2 class="section-title">{{ $label }}</h2><a href="{{ route($key.'.index',['client_id'=>$record->id]) }}" class="btn btn-sm btn-outline-secondary">View all</a></div><ul class="timeline">@forelse($items as $item)<li><div class="timeline-content"><a href="{{ route($key.'.show',$item) }}">{{ $item->{\App\Support\Modules::get($key)['label']} }}</a></div><x-badge :status="$item->display_status??$item->status"/></li>@empty<li class="subtext">No related records yet. @can('create',\App\Support\Modules::get($key)['model'])<a data-modal href="{{ route($key.'.create',['client_id'=>$record->id]) }}">Add one</a>@endcan</li>@endforelse</ul></section></div>@endforeach</div>
<section class="panel panel-pad mt-4"><h2 class="section-title">Client activity</h2><ul class="timeline">@forelse($activity as $entry)<li><div class="timeline-content">{{ $entry->description }}<div class="subtext">{{ $entry->user?->name }} · {{ $entry->created_at->format('M j, Y g:i A') }}</div></div></li>@empty<li>No activity recorded yet.</li>@endforelse</ul></section>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\components\badge.blade.php`

```blade
@props(['status'])<span class="badge-status tone-{{ \App\Support\Display::tone($status??'') }}">{{ $status }}</span>
```

### `C:\laragon\www\veritas-core\resources\views\components\field.blade.php`

```blade
@props(['name','label','type'=>'text','value'=>'','required'=>false,'options'=>[]])
<div class="mb-3"><label class="form-label" for="field_{{ $name }}">{{ $label }} @if($required)<span class="required-mark" aria-hidden="true">*</span>@endif</label>
@if($type==='select')<select id="field_{{ $name }}" name="{{ $name }}" class="form-select @error($name)is-invalid @enderror" @required($required)>@foreach($options as $key=>$text)<option value="{{ $key }}" @selected((string)old($name,$value)===(string)$key)>{{ $text }}</option>@endforeach</select>
@elseif($type==='textarea')<textarea id="field_{{ $name }}" name="{{ $name }}" class="form-control @error($name)is-invalid @enderror" rows="4" @required($required)>{{ old($name,$value) }}</textarea>
@else<input id="field_{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if(!in_array($type,['file','password'])) value="{{ old($name,$value) }}" @endif class="form-control @error($name)is-invalid @enderror" @if($type==='number') min="0" step="0.01" @endif @required($required)>@endif
@error($name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
```

### `C:\laragon\www\veritas-core\resources\views\components\pagination.blade.php`

```blade
@props(['records'])
<div class="record-pagination" aria-label="Record counts and pages">
<p class="pagination-summary" role="status">Showing <strong>{{ $records->firstItem()??0 }}</strong> to <strong>{{ $records->lastItem()??0 }}</strong> of <strong>{{ $records->total() }}</strong> records</p>
{{ $records->onEachSide(1)->links('pagination.veritas') }}
</div>
```

### `C:\laragon\www\veritas-core\resources\views\components\per-page.blade.php`

```blade
<label class="per-page-control">Per page<select name="per_page" class="form-select" aria-label="Records per page">@foreach([10,25,50] as $size)<option value="{{ $size }}" @selected(\App\Services\Records::pageSize()===$size)>{{ $size }} per page</option>@endforeach</select></label>
```

### `C:\laragon\www\veritas-core\resources\views\components\stat.blade.php`

```blade
@props(['label','value','note'=>'','icon'=>'grid','tone'=>'info'])<div class="col-sm-6 col-xl-3"><div class="panel stat"><div><div class="stat-label">{{ $label }}</div><div class="stat-value">{{ $value }}</div><div class="stat-note">{{ $note }}</div></div><span class="stat-icon tone-{{ $tone }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span></div></div>
```

### `C:\laragon\www\veritas-core\resources\views\dashboard\index.blade.php`

```blade
@extends('layouts.app')
@section('title','Overview')
@section('content')<section class="hero"><div class="eyebrow">A CLEARER VIEW OF YOUR PRACTICE</div><h2 class="hero-title">Welcome back, {{ auth()->user()->name }}.</h2><div class="hero-date">{{ now()->format('l, F j, Y') }}</div><p class="hero-summary">{{ $stats['overdue'] }} overdue filings need attention. {{ $stats['upcoming'] }} more deadlines are approaching in the next 10 days.</p><div class="hero-bottom"><span class="hero-note"><i class="bi bi-shield-check" aria-hidden="true"></i>One workspace. Every detail accounted for.</span><a class="btn btn-primary" href="{{ route('compliance.index') }}">Review deadlines ↗</a></div></section>
<div class="toolbar"><h2 class="section-title">{{ $role }} overview</h2><div class="actions"><a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route('clients.create') }}">New client</a><a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route('documents.create') }}">Add document</a><a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route('billing.create') }}">New invoice</a></div></div>
<div class="row g-3 mb-4">@if($userCount!==null)<x-stat label="User accounts" :value="$userCount" icon="people-fill"/>@endif @if($noticeCount!==null)<x-stat label="Published notices" :value="$noticeCount" icon="megaphone"/>@endif</div>
<div class="actions mb-4">@can('document.validate')<a class="btn btn-outline-secondary" href="{{ route('documents.index',['status'=>'Submitted']) }}">Documents for validation</a>@endcan @can('bookkeeping.create')<a class="btn btn-outline-secondary" href="{{ route('ledger.create') }}">New bookkeeping entry</a>@endcan @can('bookkeeping.review')<a class="btn btn-outline-secondary" href="{{ route('ledger.index',['status'=>'For Review']) }}">Bookkeeping review queue</a>@endcan @can('notice.view')<a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">Notices</a>@endcan</div>
<div class="row g-3 mb-4"><x-stat :label="auth()->user()->hasRole('bookkeeper')?'Assigned active clients':'Active clients'" :value="$stats['active']" :note="$stats['clients'].' total · '.$stats['new'].' new this month'" icon="people"/><x-stat label="Documents in progress" :value="$stats['pending']+$stats['review']+$stats['clarification']" :note="$stats['clarification'].' need clarification'" icon="folder2-open"/><x-stat label="Due within 10 days" :value="$stats['upcoming']" note="Prepare early" icon="calendar2-week" tone="warning"/><x-stat label="Overdue filings" :value="$stats['overdue']" note="Follow-up required" icon="exclamation-circle" :tone="$stats['overdue']?'danger':'success'"/></div>
<div class="row g-3 mb-4"><div class="col-xl-8"><section class="panel table-panel"><div class="panel-heading"><div><h2 class="section-title">Compliance watch</h2><p class="section-description">Nearest unresolved filings</p></div><a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.index') }}">View all</a></div><div class="table-responsive"><table class="table"><thead><tr><th>Client & requirement</th><th>Agency</th><th>Due date</th><th>Status</th></tr></thead><tbody>@forelse($deadlines as $item)<tr><td><a href="{{ route('compliance.show',$item) }}">{{ $item->requirement }}</a><div class="subtext">{{ $item->client->business_name }}</div></td><td>{{ $item->agency }}</td><td>{{ $item->due_date->format('M j, Y') }}</td><td><x-badge :status="$item->urgency"/></td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h3>No open deadlines</h3><p>Your filing queue is clear.</p>@can('create',\App\Models\ComplianceRecord::class)<a data-modal class="btn btn-primary" href="{{ route('compliance.create') }}">Add deadline</a>@endcan</div></td></tr>@endforelse</tbody></table></div></section></div>
<div class="col-xl-4"><section class="panel panel-pad"><h2 class="section-title">Billing snapshot</h2><div class="billing-total">{{ \App\Support\Money::format($stats['billing']['outstanding']) }}</div><p class="subtext">Outstanding balance</p><div class="metric-label"><span>Invoices paid</span><span>{{ $stats['billing']['paid'] }} paid · {{ $stats['billing']['open'] }} open</span></div><progress class="metric-progress" aria-label="Invoices paid" value="{{ $stats['billing']['paid'] }}" max="{{ max(1,$stats['billing']['paid']+$stats['billing']['open']) }}"></progress><p class="subtext mt-4">Collected: {{ \App\Support\Money::format($stats['billing']['collected']) }}<br>Total billed: {{ \App\Support\Money::format($stats['billing']['billed']) }}<br>Overdue: {{ \App\Support\Money::format($stats['billing']['overdue']) }}</p><a class="btn btn-sm btn-outline-secondary" href="{{ route('billing.index') }}">Open billing</a></section></div></div>
<div class="row g-3 mb-4"><x-stat label="Submitted documents" :value="$stats['pending']" icon="file-earmark"/><x-stat label="Documents under review" :value="$stats['review']" icon="file-earmark-check"/><x-stat label="Need clarification" :value="$stats['clarification']" icon="question-circle" tone="warning"/><x-stat label="Ledger entries for review" :value="$stats['ledger']" icon="journal-text"/></div>
<div class="row g-3">@foreach(['Recent clients'=>['clients',$clients,'business_name'],'Recent documents'=>['documents',$documents,'title'],'Recent invoices'=>['billing',$invoices,'invoice_number']] as $label=>$group)<div class="col-lg-4"><section class="panel panel-pad"><h2 class="section-title">{{ $label }}</h2><ul class="timeline">@forelse($group[1] as $item)<li><div class="timeline-content"><a href="{{ route($group[0].'.show',$item) }}">{{ $item->{$group[2]} }}</a><div class="subtext">{{ $item->created_at->format('M j, Y') }}</div></div><x-badge :status="$item->display_status??$item->status"/></li>@empty<li>No records yet.</li>@endforelse</ul></section></div>@endforeach</div>
<section class="panel panel-pad mt-4"><h2 class="section-title">Recent activity</h2><ul class="timeline">@forelse($activity as $item)<li><i class="bi bi-clock-history" aria-hidden="true"></i><div class="timeline-content">{{ $item->description }}<div class="subtext">{{ $item->user?->name }} · {{ $item->created_at->format('M j, g:i A') }}</div></div></li>@empty<li>Your activity will appear as records are saved.</li>@endforelse</ul></section>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\documents\validation.blade.php`

```blade
@can('validate',$record)
<h3 class="section-title mt-4">Validate document</h3>
<form method="post" action="{{ route('documents.validate',$record) }}">@csrf
<div class="row"><div class="col-md-6"><label class="form-label" for="validation_status">Validation decision</label><select class="form-select" name="status" id="validation_status" required>
<option>Under Review</option><option>Reviewed</option><option>Needs Clarification</option>
@can('approve',$record)<option>Approved</option>@endcan
@can('reject',$record)<option>Rejected</option>@endcan
</select></div><div class="col-md-6"><x-field name="notes" label="Validation notes" type="textarea" :value="$record->notes"/></div></div>
<button class="btn btn-primary">Save validation</button></form>
@endcan
```

### `C:\laragon\www\veritas-core\resources\views\errors\403.blade.php`

```blade
@extends('layouts.guest')
@section('title','403 — Access restricted')
@section('content')<div class="eyebrow">ERROR 403</div><h2 class="modal-title">Access restricted</h2><p class="subtext">Your account does not have access to this page.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\errors\404.blade.php`

```blade
@extends('layouts.guest')
@section('title','404 — Page not found')
@section('content')<div class="eyebrow">ERROR 404</div><h2 class="modal-title">Page not found</h2><p class="subtext">This record may have been archived, or the address may be incorrect.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\errors\409.blade.php`

```blade
@extends('layouts.guest')
@section('title','409 — Record changed')
@section('content')<div class="eyebrow">ERROR 409</div><h2 class="modal-title">Record changed</h2><p class="subtext">This action is not available in the record’s current state. Reload and try again.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\errors\419.blade.php`

```blade
@extends('layouts.guest')
@section('title','419 — Session expired')
@section('content')<div class="eyebrow">ERROR 419</div><h2 class="modal-title">Session expired</h2><p class="subtext">Reload the page and sign in again before submitting your changes.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\errors\500.blade.php`

```blade
@extends('layouts.guest')
@section('title','500 — Something went wrong')
@section('content')<div class="eyebrow">ERROR 500</div><h2 class="modal-title">Something went wrong</h2><p class="subtext">The request could not be completed. Please try again or contact your administrator.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\layouts\app.blade.php`

```blade
<!doctype html><html lang="en"><head>@include('layouts.head')</head>
<body data-search-url="{{ route('search') }}" data-notifications-url="{{ route('notifications.index') }}" data-read-all-url="{{ route('notifications.all') }}">
<a class="skip-link" href="#viewRoot">Skip to content</a>
<div class="app-shell">@include('partials.sidebar')<button id="sidebarOverlay" class="sidebar-overlay" aria-label="Close navigation" hidden></button>
<main class="main-area" id="mainArea">@include('partials.topbar')<div class="content-wrap"><div id="viewRoot" tabindex="-1">@include('partials.alerts')@yield('content')</div></div>
<footer class="workspace-footer"><span>VERITAS CORE</span><span>{{ $firm?->firm_name ?? 'RBCIA Accounting Firm' }}</span></footer></main></div>
<div class="modal fade" id="formModal" tabindex="-1" aria-labelledby="formModalTitle"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 id="formModalTitle" class="modal-title">VERITAS CORE</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close form"></button></div><div class="modal-body" id="formModalBody"></div></div></div></div>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 id="confirmTitle" class="modal-title">Confirm action</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close confirmation"></button></div><div class="modal-body" id="confirmText"></div><div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep record</button><button class="btn btn-outline-danger" id="confirmAccept">Continue</button></div></div></div></div>
<dialog id="commandPalette" class="command-palette" aria-labelledby="commandTitle"><h2 id="commandTitle" class="visually-hidden">Search workspace</h2><div class="command-input-wrap"><i class="bi bi-search" aria-hidden="true"></i><input id="commandInput" type="search" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="commandResults" aria-label="Search workspace" placeholder="Search clients, documents, or invoices…"><button id="commandClose" class="key-button" aria-label="Close search">Esc</button></div><div class="command-results" id="commandResults" role="listbox" aria-label="Search results"></div><div class="command-footer"><span>↑ ↓ navigate · Enter open · Esc close</span><span>VERITAS CORE</span></div><div id="commandStatus" class="visually-hidden" aria-live="polite"></div></dialog>
<div id="toastStack" class="toast-stack" aria-live="polite"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="{{ asset('app.js') }}" defer></script>
</body></html>
```

### `C:\laragon\www\veritas-core\resources\views\layouts\guest.blade.php`

```blade
<!doctype html><html lang="en"><head>@include('layouts.head')</head><body><main class="auth-shell"><section class="auth-brand"><div class="brand-mark">V</div><h1>VERITAS CORE</h1><p>RBCIA Accounting Firm</p><p class="hero-summary">Clarity in every detail.<br>Your practice, connected.</p></section><section class="auth-card panel panel-pad">@include('partials.alerts')@yield('content')</section></main></body></html>
```

### `C:\laragon\www\veritas-core\resources\views\layouts\head.blade.php`

```blade
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#101d30">
<title>@yield('title','Overview') · VERITAS CORE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23101d30'/%3E%3Ctext x='32' y='46' text-anchor='middle' font-family='sans-serif' font-size='46' fill='%23c9a55f'%3EV%3C/text%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="{{ asset('styles.css') }}" rel="stylesheet"><link href="{{ asset('laravel.css') }}" rel="stylesheet">
```

### `C:\laragon\www\veritas-core\resources\views\ledger\detail.blade.php`

```blade
<h3 class="section-title mb-3">Transaction lines</h3><div class="table-responsive"><table class="table"><thead><tr><th>Account</th><th class="numeric">Debit</th><th class="numeric">Credit</th></tr></thead><tbody>@foreach($record->items as $item)<tr><td>{{ $item->account_name }}</td><td class="numeric">{{ \App\Support\Money::format($item->debit) }}</td><td class="numeric">{{ \App\Support\Money::format($item->credit) }}</td></tr>@endforeach</tbody><tfoot><tr><th>Total</th><th class="numeric">{{ \App\Support\Money::format($record->total_debit) }}</th><th class="numeric">{{ \App\Support\Money::format($record->total_credit) }}</th></tr></tfoot></table></div>
<p class="system-alert mt-3">{{ abs($record->total_debit-$record->total_credit)<0.001?'This transaction is balanced.':'This transaction is unbalanced and cannot be submitted for review.' }}</p>
@can('submit',$record)<form method="post" action="{{ route('ledger.transition',$record) }}">@csrf<input type="hidden" name="action" value="submit"><button class="btn btn-primary">Submit for review</button></form>@endcan
@can('review',$record)@if($record->status==='For Review')<form method="post" action="{{ route('ledger.transition',$record) }}">@csrf<x-field name="notes" label="Review notes" type="textarea" :value="$record->notes"/><div class="actions">@can('approve',$record)<button name="action" value="review" class="btn btn-primary">Approve review</button>@endcan<button name="action" value="return" class="btn btn-outline-danger">Return for correction</button></div><p class="form-text">A different employee must review the transaction.</p></form>@endif
@endcan
@if($record->reviewed_by)<p class="subtext mt-3">Reviewed by {{ $record->reviewer?->name }} on {{ $record->reviewed_at?->format('M j, Y g:i A') }}.</p>@endif
```

### `C:\laragon\www\veritas-core\resources\views\notices\form.blade.php`

```blade
@extends('layouts.app')
@section('title',$notice->exists?'Edit notice':'Create notice')
@section('content')
<section class="panel panel-pad"><p class="subtext">Save a draft, review it, then publish it in the internal workspace. Editing a published notice returns it to Draft.</p>
<form method="post" action="{{ $notice->exists?route('notices.update',$notice):route('notices.store') }}">@csrf
@if($notice->exists)@method('PUT')@endif
<x-field name="title" label="Title" :value="$notice->title" :required="true"/>
<x-field name="client_id" label="Related client (optional)" type="select" :value="$notice->client_id" :options="[''=>'General notice']+$clients->pluck('business_name','id')->all()"/>
<x-field name="body" label="Notice content" type="textarea" :value="$notice->body" :required="true"/>
<div class="actions"><a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">Cancel</a><button class="btn btn-primary">Save draft</button></div></form></section>
@endsection
```

### `C:\laragon\www\veritas-core\resources\views\notices\index.blade.php`

```blade
@extends('layouts.app')
@section('title','Notices')
@section('content')
<div class="toolbar"><p class="toolbar-copy">Internal notices for Owners and Office Managers.</p>@can('create',\App\Models\Notice::class)<a class="btn btn-primary" href="{{ route('notices.create') }}">Create notice</a>@endcan</div>
<form class="filters" method="get"><label>Search<input class="form-control" name="q" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach(['Draft','Published','Archived'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">Clear</a></form>
<section class="panel table-panel"><div class="table-responsive"><table class="table"><thead><tr><th>Notice</th><th>Client</th><th>Status</th><th>Updated</th></tr></thead><tbody>@forelse($notices as $notice)<tr><td><a href="{{ route('notices.show',$notice) }}">{{ $notice->title }}</a></td><td>{{ $notice->client?->business_name??'General notice' }}</td><td><x-badge :status="$notice->status"/></td><td>{{ $notice->updated_at->format('M j, Y') }}</td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h2>No notices found</h2><p>Create a notice or clear your filters.</p></div></td></tr>@endforelse</tbody></table></div></section><x-pagination :records="$notices"/>
@endsection
```

### `C:\laragon\www\veritas-core\resources\views\notices\show.blade.php`

```blade
@extends('layouts.app')
@section('title','Notice')
@section('content')
<div class="toolbar"><a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">Back to notices</a><div class="actions">
@can('update',$notice)<a class="btn btn-primary" href="{{ route('notices.edit',$notice) }}">Edit notice</a>@endcan
@can('publish',$notice)@if($notice->status==='Draft')<form method="post" action="{{ route('notices.publish',$notice) }}" data-confirm="Publish this notice in the internal workspace?">@csrf<button class="btn btn-primary">Publish notice</button></form>@endif
@endcan
@can('delete',$notice)@if($notice->status!=='Archived')<form method="post" action="{{ route('notices.archive',$notice) }}" data-confirm="Archive this notice?">@csrf<button class="btn btn-outline-danger">Archive</button></form>@endif
@endcan
</div></div>
<section class="panel panel-pad"><div class="toolbar"><h2 class="section-title">{{ $notice->title }}</h2><x-badge :status="$notice->status"/></div><p class="subtext">{{ $notice->client?->business_name??'General notice' }} · Created by {{ $notice->creator?->name }}@if($notice->published_at) · Published {{ $notice->published_at->format('M j, Y g:i A') }}@endif</p><div class="prose">{{ $notice->body }}</div></section>
@endsection
```

### `C:\laragon\www\veritas-core\resources\views\pagination\veritas.blade.php`

```blade
@if($paginator->hasPages())
<nav aria-label="Results pagination"><ul class="pagination">
@if($paginator->onFirstPage())<li class="page-item disabled"><span class="page-link" aria-disabled="true">Previous</span></li>@else<li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a></li>@endif
@foreach($elements as $element)
@if(is_string($element))<li class="page-item disabled"><span class="page-link" aria-disabled="true">{{ $element }}</span></li>@endif
@if(is_array($element))@foreach($element as $page=>$url)
@if($page==$paginator->currentPage())<li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>@else<li class="page-item"><a class="page-link" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a></li>@endif
@endforeach
@endif
@endforeach
@if($paginator->hasMorePages())<li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a></li>@else<li class="page-item disabled"><span class="page-link" aria-disabled="true">Next</span></li>@endif
</ul></nav>
@endif
```

### `C:\laragon\www\veritas-core\resources\views\partials\alerts.blade.php`

```blade
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please check the following:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
```

### `C:\laragon\www\veritas-core\resources\views\partials\sidebar.blade.php`

```blade
<aside class="sidebar" id="sidebar" aria-label="Workspace navigation"><a class="brand" href="{{ route('dashboard') }}">@if($firm?->logo_path)<img class="brand-mark" style="object-fit:contain" src="{{ asset('storage/'.$firm->logo_path) }}" alt="Firm logo">@else<span class="brand-mark" aria-hidden="true">V</span>@endif<span><span class="brand-name">VERITAS CORE</span><span class="brand-caption">{{ $firm?->firm_name ?? 'RBCIA Accounting Firm' }}</span></span></a>
<button class="icon-button sidebar-close" id="sidebarClose" aria-label="Close navigation"><i class="bi bi-x-lg" aria-hidden="true"></i></button><div class="nav-label">YOUR WORKSPACE</div><nav id="mainNav" aria-label="Primary navigation">
<a class="nav-item {{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif><i class="bi bi-grid-1x2" aria-hidden="true"></i>Overview</a>
@foreach(\App\Support\Modules::all() as $key=>$item)@can('viewAny',$item['model'])<a class="nav-item {{ request()->routeIs($key.'.*')?'active':'' }}" href="{{ route($key.'.index') }}" @if(request()->routeIs($key.'.*')) aria-current="page" @endif><i class="bi bi-{{ $item['icon'] }}" aria-hidden="true"></i>{{ $item['title'] }}</a>@endcan
@endforeach
@can('notice.view')<a class="nav-item {{ request()->routeIs('notices.*')?'active':'' }}" href="{{ route('notices.index') }}"><i class="bi bi-megaphone" aria-hidden="true"></i>Notices</a>@endcan
@can('report.view')<a class="nav-item {{ request()->routeIs('reports.*')?'active':'' }}" href="{{ route('reports.index') }}"><i class="bi bi-bar-chart" aria-hidden="true"></i>Reports</a>@endcan
@if(auth()->user()->hasRole('owner'))<div class="nav-divider"></div>
@can('workspace.manage')<a class="nav-item {{ request()->routeIs('workspace.*')?'active':'' }}" href="{{ route('workspace.edit') }}"><i class="bi bi-sliders2" aria-hidden="true"></i>Workspace</a>@endcan
@can('viewAny',\App\Models\User::class)<a class="nav-item {{ request()->routeIs('admin.users.*')?'active':'' }}" href="{{ route('admin.users.index') }}"><i class="bi bi-person-gear" aria-hidden="true"></i>User Management</a>@endcan
@can('audit.view')<a class="nav-item {{ request()->routeIs('admin.audit.*')?'active':'' }}" href="{{ route('admin.audit.index') }}"><i class="bi bi-clock-history" aria-hidden="true"></i>Audit Logs</a>@endcan
@endif
</nav></aside>
```

### `C:\laragon\www\veritas-core\resources\views\partials\topbar.blade.php`

```blade
<header class="topbar"><div class="topbar-heading"><button id="sidebarToggle" class="icon-button mobile-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i></button><div><div class="eyebrow">RBCIA / WORKSPACE</div><h1>@yield('title','Overview')</h1></div></div>
<div class="topbar-tools"><button id="commandTrigger" class="search-trigger" aria-haspopup="dialog" aria-controls="commandPalette"><i class="bi bi-search" aria-hidden="true"></i><span>Search workspace</span><kbd>Ctrl K</kbd></button><div class="notification-wrap"><button id="notificationTrigger" class="icon-button" aria-label="Notifications" aria-expanded="false" aria-controls="notificationPanel"><i class="bi bi-bell" aria-hidden="true"></i><span class="notification-dot" id="notificationDot" hidden></span></button><section id="notificationPanel" class="notification-panel" aria-label="Notifications" hidden></section></div>
<a href="{{ route('profile.edit') }}" class="profile-button"><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="profile-copy"><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->role?->name }}</span></span></a>
<form method="post" action="{{ route('logout') }}">@csrf<button class="icon-button" aria-label="Sign out" title="Sign out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form></div></header>
```

### `C:\laragon\www\veritas-core\resources\views\records\filters.blade.php`

```blade
<form method="get" class="filters" aria-label="Filter records">
@if(request()->routeIs('reports.*'))<input type="hidden" name="module" value="{{ $module }}">@endif
@if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
@if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
<label class="search-filter">Search<input type="search" name="q" class="form-control" value="{{ \App\Services\Records::searchText(request()) }}" placeholder="Name, reference, or details"></label>
<label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach($config['statuses'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label>
@if(!in_array($module,['clients','knowledge']))<label>Client<select class="form-select" name="client_id"><option value="">All assigned clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>@endif
@php($typeKey=['clients'=>'business_type','documents'=>'document_type','compliance'=>'agency','knowledge'=>'category'][$module]??null)
@if($typeKey)<label>{{ $config['fields'][$typeKey][0] }}<select class="form-select" name="type"><option value="">All types</option>@foreach($config['fields'][$typeKey][1] as $type)<option @selected(request('type')===$type)>{{ $type }}</option>@endforeach</select></label>@endif
<label>From<input type="date" class="form-control" name="from" value="{{ request('from') }}"></label><label>To<input type="date" class="form-control" name="to" value="{{ request('to') }}"></label><x-per-page/><button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ request()->url() }}{{ request()->routeIs('reports.*')?'?module='.$module:'' }}">Clear</a></form>
```

### `C:\laragon\www\veritas-core\resources\views\records\form-content.blade.php`

```blade
<h2 class="section-title mb-4">{{ $record->exists?'Edit':'New' }} {{ $config['singular'] }}</h2>
<form action="{{ $record->exists?route($module.'.update',$record):route($module.'.store') }}" method="post" enctype="multipart/form-data">@csrf @if($record->exists)@method('PUT')@endif
<p class="form-text">Fields marked * are required.</p><div class="row">
@if(!in_array($module,['clients','knowledge']) && !($module==='compliance' && auth()->user()->hasRole('bookkeeper')))<div class="col-md-6"><x-field name="client_id" label="Client" type="select" :required="true" :value="$record->client_id??request('client_id')" :options="[''=>'Choose a client']+$clients->pluck('business_name','id')->all()"/></div>@endif
@foreach($config['fields'] as $name=>$field)

@php($value=$record->$name)
@php($value=$value instanceof \DateTimeInterface?$value->format('Y-m-d'):(is_array($value)?implode(', ',$value):($value??($field[1]==='date' && ($field[2]??false)?today()->toDateString():($field[1]==='number'?'0':'')))))
<div class="{{ $field[1]==='textarea'?'col-12':'col-md-6' }}"><x-field :name="$name" :label="$field[0]" :type="is_array($field[1])?'select':$field[1]" :options="is_array($field[1])?array_combine($field[1],$field[1]):[]" :required="$field[2]??false" :value="$value"/></div>
@endforeach
@if(($module==='clients' && auth()->user()->hasPermission('client.assign')) || ($module==='compliance' && auth()->user()->hasPermission('compliance.assign')))<div class="col-md-6"><x-field name="assigned_to" label="Assigned employee" type="select" :value="$record->assigned_to" :options="[''=>'Unassigned']+$users->pluck('name','id')->all()"/></div>@endif
@if($module==='documents' && auth()->user()->hasPermission('document.upload'))<div class="col-12"><x-field name="file" label="Attachment" type="file"/><p class="form-text">PDF, Word, Excel, JPG or PNG. Maximum 20 MB. Downloads require authorized access. @if($record->file_path)Current file: {{ $record->original_file_name }}. Uploading replaces it.@endif</p></div>@endif
</div>
@if(in_array($module,['ledger','billing']))@include('records.lines')@endif
<div class="actions mt-4"><a href="{{ $record->exists?route($module.'.show',$record):route($module.'.index') }}" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary" type="submit">Save {{ $config['singular'] }}</button></div></form>
```

### `C:\laragon\www\veritas-core\resources\views\records\form.blade.php`

```blade
@extends('layouts.app')
@section('title',($record->exists?'Edit ':'New ').$config['singular'])
@section('content')<section class="panel panel-pad">@include('records.form-content')</section>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\records\index.blade.php`

```blade
@extends('layouts.app')
@section('title',$config['title'])
@section('content')
@if(isset($billingSummary))@include('billing.summary')@endif
<div class="toolbar"><p class="toolbar-copy">{{ $records->total() }} records · {{ $config['title'] === 'Ledger Review' ? 'Review entries before posting in your general ledger system' : 'Your team’s work, in one place' }}</p>@can('create',$config['model'])<a data-modal class="btn btn-primary" href="{{ route($module.'.create') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i>New {{ $config['singular'] }}</a>@endcan</div>
@include('records.filters')
@if(in_array($module,['compliance','knowledge']))<div class="row g-3">@forelse($records as $record)<div class="col-md-6 col-xl-4"><article class="panel {{ $module==='compliance'?'deadline-card '.($record->urgency==='Overdue'?'urgent':($record->urgency==='Due Soon'?'soon':'')):'article-card' }}"><div class="toolbar mb-0"><span class="tag">{{ $record->agency??$record->category }}</span><x-badge :status="$record->display_status??$record->status"/></div><h2><a class="text-button" href="{{ route($module.'.show',$record) }}">{{ $record->{$config['label']} }}</a></h2>
@if($module==='compliance')<p class="subtext">{{ $record->client->business_name }}</p><p class="deadline-date">Due {{ $record->due_date->format('M j, Y') }}</p><p class="section-description">{{ $record->urgency }} · Preparation begins {{ $record->due_date->copy()->subDays(10)->format('M j') }}</p>@else<p class="article-preview">{{ $record->content }}</p><div class="actions">@foreach($record->tags??[] as $tag)<span class="tag">#{{ $tag }}</span>@endforeach</div>@endif<div class="actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.show',$record) }}">View details</a>@can('update',$record)<a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.edit',$record) }}">{{ $module==='compliance' && auth()->user()->hasPermission('compliance.file')?'Update / mark filed':'Edit' }}</a>@endcan</div></article></div>
@empty<div class="col-12"><div class="panel empty-state"><div class="empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></div><h2>No matching records</h2><p>Clear your filters or add your first {{ $config['singular'] }}.</p><a class="btn btn-primary" href="{{ route($module.'.index') }}">Clear filters</a></div></div>@endforelse</div><x-pagination :records="$records"/>
@else @include('records.table') @endif
@endsection
```

### `C:\laragon\www\veritas-core\resources\views\records\lines.blade.php`

```blade
@php($lineKeys=$module==='ledger'?['account_name'=>'Account','debit'=>'Debit','credit'=>'Credit']:['description'=>'Service description','quantity'=>'Quantity','unit_price'=>'Unit price'])
@php($lines=old('items',$record->exists?$record->items->toArray():($module==='ledger'?[['account_name'=>'','debit'=>'0','credit'=>'0'],['account_name'=>'','debit'=>'0','credit'=>'0']]:[['description'=>'','quantity'=>'1','unit_price'=>'0']])))
<section data-lines="{{ $module }}"><div class="toolbar"><h3 class="section-title">{{ $module==='ledger'?'Debit and credit lines':'Invoice items' }}</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line>Add line</button></div><div class="table-responsive"><table class="table"><thead><tr>@foreach($lineKeys as $label)<th>{{ $label }}</th>@endforeach<th>Remove</th></tr></thead><tbody data-line-body>
@foreach($lines as $index=>$line)<tr>@foreach($lineKeys as $key=>$label)<td><label class="visually-hidden" for="item_{{ $index }}_{{ $key }}">{{ $label }} line {{ $index+1 }}</label><input class="form-control" id="item_{{ $index }}_{{ $key }}" name="items[{{ $index }}][{{ $key }}]" value="{{ $line[$key]??'' }}" type="{{ in_array($key,['account_name','description'])?'text':'number' }}" @if(!in_array($key,['account_name','description']))min="0" step="0.01"@endif required>@error('items.'.$index.'.'.$key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</td>@endforeach<td><button type="button" class="icon-button" data-remove-line aria-label="Remove line"><i class="bi bi-trash" aria-hidden="true"></i></button></td></tr>@endforeach
</tbody></table></div><p data-line-total class="system-alert mt-3" aria-live="polite">Totals are calculated from the lines above. Final validation is performed on save.</p></section>
```

### `C:\laragon\www\veritas-core\resources\views\records\show.blade.php`

```blade
@extends('layouts.app')
@section('title',$config['title'])
@section('content')<div class="toolbar"><a href="{{ route($module.'.index') }}" class="btn btn-outline-secondary">← Back to {{ strtolower($config['title']) }}</a><div class="actions">@can('update',$record)<a data-modal class="btn btn-primary" href="{{ route($module.'.edit',$record) }}">Edit {{ $config['singular'] }}</a>@endcan
@can('delete',$record)<form method="post" action="{{ route($module.'.destroy',$record) }}" data-confirm="{{ $module==='ledger'?'Delete this draft transaction?':'Archive this record? It will leave the active list.' }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">{{ $module==='ledger'?'Delete draft':'Archive' }}</button></form>@endcan</div></div>
<section class="panel panel-pad"><div class="toolbar"><h2 class="modal-title">{{ $record->{$config['label']} }}</h2><x-badge :status="$record->display_status??$record->status"/></div><dl class="detail-grid">@if($record->client_id)<div><dt>Client</dt><dd><a href="{{ route('clients.show',$record->client) }}">{{ $record->client->business_name }}</a></dd></div>@endif
@foreach($config['fields'] as $key=>$field)<div><dt>{{ $field[0] }}</dt><dd class="{{ $field[1]==='textarea'?'prose':'' }}">{{ \App\Support\Display::value($record,$key) }}</dd></div>@endforeach</dl>
@if($module==='documents')@include('documents.validation')@if($record->file_path)@can('download',$record)<a class="btn btn-primary" href="{{ route('documents.download',$record) }}"><i class="bi bi-download" aria-hidden="true"></i>Download {{ $record->original_file_name }}</a>@endcan
@else<p class="subtext">No attachment has been added. Edit this record to upload a file.</p>@endif @endif
@if($module==='ledger')@include('ledger.detail')@endif
@if($module==='billing')@include('billing.detail')@endif
</section>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\records\table.blade.php`

```blade
<div class="panel table-panel"><div class="table-responsive"><table class="table table-hover"><caption class="visually-hidden">{{ $config['title'] }} records</caption><thead><tr>
@foreach($config['columns'] as $key=>$label)<th scope="col">@if(in_array($key,array_keys($config['fields'])) || in_array($key,['id',$config['date']]))<a class="sort-button" href="{{ request()->fullUrlWithQuery(['page'=>1,'sort'=>$key,'direction'=>request('sort')===$key && request('direction')==='asc'?'desc':'asc']) }}">{{ $label }} <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a>@else{{ $label }}@endif</th>@endforeach<th scope="col">Actions</th></tr></thead><tbody>
@forelse($records as $record)<tr>@foreach($config['columns'] as $key=>$label)<td class="{{ in_array($key,['total_amount','amount_paid','balance','tax'])?'numeric':'' }}">@if($key==='status')<x-badge :status="$record->display_status??$record->status"/>@elseif($key===$config['label'])<a class="text-button" href="{{ route($module.'.show',$record) }}">{{ \App\Support\Display::value($record,$key) }}</a>@else{{ \App\Support\Display::value($record,$key) }}@endif</td>@endforeach
<td><div class="actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.show',$record) }}">View</a>@can('update',$record)<a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.edit',$record) }}">Edit</a>@endcan</div></td></tr>
@empty<tr><td colspan="{{ count($config['columns'])+1 }}"><div class="empty-state"><div class="empty-icon"><i class="bi bi-{{ $config['icon'] }}" aria-hidden="true"></i></div><h2>No {{ strtolower($config['title']) }} found</h2><p>Try another filter or add a new {{ $config['singular'] }}.</p>@can('create',$config['model'])<a data-modal class="btn btn-primary" href="{{ route($module.'.create') }}">New {{ $config['singular'] }}</a>@endcan</div></td></tr>@endforelse
</tbody></table></div></div><x-pagination :records="$records"/>
```

### `C:\laragon\www\veritas-core\resources\views\reports\index.blade.php`

```blade
@extends('layouts.app')
@section('title','Reports')
@section('content')<div class="toolbar"><div class="category-chips mb-0">@foreach(\App\Support\Modules::all() as $key=>$item)@can('viewAny',$item['model'])<a class="chip {{ $key===$module?'active':'' }}" href="{{ route('reports.index',['module'=>$key]) }}">{{ $item['title'] }}</a>@endcan
@endforeach</div><div class="actions">@can('report.export')<a class="btn btn-primary" href="{{ route('reports.csv',request()->query()+['module'=>$module]) }}">Export CSV</a>@endcan
@can('report.print')<button class="btn btn-outline-secondary" data-print>Print page</button>@endcan</div></div>@include('records.filters')<div class="row g-3 mb-4">@foreach($summary as $label=>$value)<x-stat :label="ucfirst($label)" :value="in_array($label,['billed','collected','outstanding','overdue','Total debit','Total credit'])?\App\Support\Money::format($value):$value"/>@endforeach</div>@include('records.table')@endsection
```

### `C:\laragon\www\veritas-core\resources\views\settings\edit.blade.php`

```blade
@extends('layouts.app')
@section('title','Workspace')
@section('content')<section class="panel panel-pad"><h2 class="section-title mb-4">Firm settings</h2><form method="post" action="{{ route('workspace.update') }}" enctype="multipart/form-data">@csrf @method('PUT')<div class="row"><div class="col-md-6"><x-field name="firm_name" label="Firm name" :value="$setting->firm_name" :required="true"/></div><div class="col-md-6"><x-field name="firm_email" label="Firm email" type="email" :value="$setting->firm_email"/></div><div class="col-md-6"><x-field name="contact_number" label="Contact number" :value="$setting->contact_number"/></div><div class="col-md-6"><x-field name="logo" label="Firm logo (PNG or JPG, up to 2 MB)" type="file"/></div><div class="col-12"><x-field name="firm_address" label="Firm address" type="textarea" :value="$setting->firm_address"/></div><div class="col-md-6"><x-field name="currency" label="Default currency" type="select" :value="$setting->currency" :options="['PHP'=>'PHP — Philippine Peso (₱)']"/></div><div class="col-md-6"><x-field name="page_size" label="Records per page" type="select" :value="$setting->page_size" :options="[10=>10,25=>25,50=>50]"/></div></div><div class="form-check mb-4"><input class="form-check-input" id="notificationsEnabled" name="notifications_enabled" type="checkbox" value="1" @checked(old('notifications_enabled',$setting->notifications_enabled))><label for="notificationsEnabled" class="form-check-label">Generate workspace notifications</label></div><button class="btn btn-primary">Save workspace settings</button></form></section>@endsection
```

## 6. Regression tests

### `C:\laragon\www\veritas-core\tests\Feature\RbacTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Notice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bookkeeper;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
    }

    private function documentData(Document $document, array $extra = []): array
    {
        return array_merge($document->only(['client_id', 'title', 'document_type', 'status', 'notes']), ['received_date' => $document->received_date->toDateString()], $extra);
    }

    private function accountData(User $user, array $extra = []): array
    {
        return array_merge($user->only(['name', 'email', 'role_id', 'status']), $extra);
    }

    public function test_database_permissions_and_helpers_are_not_role_name_only_checks(): void
    {
        $this->assertDatabaseCount('roles', 3);
        $this->assertTrue($this->owner->hasRole('owner'));
        $this->assertTrue($this->manager->hasAnyRole(['owner', 'office-manager']));
        $this->assertTrue($this->bookkeeper->hasAnyPermission(['user.create', 'billing.payment']));
        $this->assertFalse($this->bookkeeper->hasPermission('document.approve'));
        $id = Permission::where('name', 'billing.payment')->value('id');
        $this->bookkeeper->role->permissions()->detach($id);
        $this->assertFalse($this->bookkeeper->hasPermission('billing.payment'));
        $invoice = Invoice::where('status', 'Open')->first();
        $this->actingAs($this->bookkeeper)->post('/billing/'.$invoice->id.'/payments', [])->assertForbidden();
    }

    public function test_only_owner_can_reach_admin_routes_or_change_accounts(): void
    {
        foreach ([$this->bookkeeper, $this->manager] as $user) {
            $this->actingAs($user);
            foreach (['/admin/users', '/admin/users/create', '/admin/users/'.$this->owner->id.'/edit', '/workspace', '/admin/audit-logs'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/admin/users', [])->assertForbidden();
            $this->put('/admin/users/'.$this->owner->id, $this->accountData($this->owner, ['role_id' => $user->role_id]))->assertForbidden();
            $this->post('/admin/users/'.$this->owner->id.'/reset-password')->assertForbidden();
            $this->put('/workspace', [])->assertForbidden();
        }
        $this->assertTrue($this->owner->fresh()->hasRole('owner'));
    }

    public function test_owner_creates_all_roles_and_cannot_demote_or_deactivate_self(): void
    {
        $this->actingAs($this->owner);
        foreach (Role::all() as $role) {
            $this->post('/admin/users', ['name' => 'New '.$role->name, 'email' => $role->slug.'@example.com', 'role_id' => $role->id, 'status' => 'Active', 'password' => 'password12345', 'password_confirmation' => 'password12345'])->assertRedirect('/admin/users');
        }
        $this->put('/admin/users/'.$this->owner->id, $this->accountData($this->owner, ['status' => 'Inactive']))->assertForbidden();
        $this->put('/admin/users/'.$this->owner->id, $this->accountData($this->owner, ['role_id' => $this->bookkeeper->role_id]))->assertForbidden();
        $this->assertTrue($this->owner->fresh()->hasRole('owner'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.changed']);
    }

    public function test_owner_role_and_account_changes_are_audited(): void
    {
        $this->actingAs($this->owner)->put('/admin/users/'.$this->bookkeeper->id, $this->accountData($this->bookkeeper, ['role_id' => $this->manager->role_id, 'status' => 'Inactive']))->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.deactivated', 'record_id' => $this->bookkeeper->id]);
        $this->assertFalse($this->bookkeeper->fresh()->hasPermission('client.view'));
        $this->actingAs($this->bookkeeper->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_bookkeeper_cannot_validate_documents_through_urls_or_forged_forms(): void
    {
        $document = Document::where('status', 'Submitted')->first();
        $this->actingAs($this->bookkeeper);
        $this->get('/documents/'.$document->id.'/validate')->assertForbidden();
        foreach (['Approved', 'Rejected', 'Reviewed', 'Under Review', 'Needs Clarification'] as $status) {
            $this->post('/documents/'.$document->id.'/validate', ['status' => $status])->assertForbidden();
            $this->put('/documents/'.$document->id, $this->documentData($document, ['status' => $status]))->assertForbidden();
            $this->post('/documents', $this->documentData($document, ['status' => $status]))->assertForbidden();
        }
        $this->put('/documents/'.$document->id, $this->documentData($document, ['title' => 'Updated metadata']))->assertRedirect();
        $this->assertSame('Submitted', $document->fresh()->status);
        $this->get('/documents/'.$document->id)->assertDontSee('Validate document');
        $this->get('/documents/'.$document->id.'/edit')->assertDontSee('<option value="Approved"', false);
    }

    public function test_manager_validates_and_bookkeeper_cannot_replace_approved_documents(): void
    {
        $document = Document::where('status', 'Submitted')->first();
        $this->actingAs($this->manager)->get('/documents/'.$document->id)->assertOk()->assertSee('Validate document');
        $this->post('/documents/'.$document->id.'/validate', ['status' => 'Approved', 'notes' => 'Checked source'])->assertRedirect();
        $this->assertSame('Approved', $document->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.validated', 'record_id' => $document->id]);
        $this->actingAs($this->bookkeeper)->put('/documents/'.$document->id, $this->documentData($document, ['status' => 'Submitted']))->assertForbidden();
    }

    public function test_manager_reviews_but_cannot_create_or_edit_original_ledger_entries(): void
    {
        $entry = LedgerEntry::first();
        $this->actingAs($this->manager);
        $this->get('/ledger/create')->assertForbidden();
        $this->post('/ledger', [])->assertForbidden();
        $this->get('/ledger')->assertOk()->assertDontSee('New transaction');
        $this->get('/ledger/'.$entry->id)->assertOk()->assertSee('Approve review');
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'return', 'notes' => 'Correct source reference'])->assertRedirect();
        $this->get('/ledger/'.$entry->id.'/edit')->assertForbidden();
        $this->put('/ledger/'.$entry->id, [])->assertForbidden();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertForbidden();
        $this->actingAs($this->bookkeeper)->get('/ledger/'.$entry->id.'/edit')->assertOk();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertRedirect();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertRedirect();
        $this->assertSame('Reviewed', $entry->fresh()->status);
    }

    public function test_even_owner_cannot_approve_their_own_ledger_entry(): void
    {
        $entry = LedgerEntry::first();
        $entry->update(['created_by' => $this->owner->id]);
        $this->actingAs($this->owner)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertRedirect();
    }

    public function test_bookkeeper_can_only_update_compliance_preparation_and_notes(): void
    {
        $record = ComplianceRecord::first();
        $this->actingAs($this->bookkeeper);
        $this->get('/compliance/create')->assertForbidden();
        $this->post('/compliance', [])->assertForbidden();
        $this->put('/compliance/'.$record->id, ['status' => 'In Preparation', 'notes' => 'Source documents requested'])->assertRedirect();
        foreach ([['status' => 'Filed'], ['due_date' => today()->addYear()->toDateString()], ['assigned_to' => $this->owner->id], ['requirement' => 'Changed obligation'], ['reference_number' => 'forged']] as $extra) {
            $this->put('/compliance/'.$record->id, array_merge(['status' => 'In Preparation', 'notes' => 'Note'], $extra))->assertForbidden();
        }
        $this->assertSame('In Preparation', $record->fresh()->status);
        $this->get('/compliance/'.$record->id.'/edit')->assertOk()->assertDontSee('name="due_date"', false)->assertDontSee('name="assigned_to"', false);
        $this->actingAs($this->manager)->put('/compliance/'.$record->id, array_merge($record->only(['client_id', 'agency', 'requirement', 'reporting_period']), ['status' => 'Filed', 'due_date' => $record->due_date->toDateString(), 'filed_date' => today()->toDateString(), 'reference_number' => 'ACK-100', 'assigned_to' => $this->bookkeeper->id]))->assertRedirect();
        $this->assertSame('Filed', $record->fresh()->status);
    }

    public function test_bookkeeper_articles_are_own_drafts_and_manager_can_publish(): void
    {
        $data = ['title' => 'Bookkeeper draft', 'category' => 'Accounting', 'status' => 'Draft', 'content' => '<script>bad()</script>', 'tags' => 'process'];
        $this->actingAs($this->bookkeeper)->post('/knowledge', $data)->assertRedirect();
        $article = KnowledgeArticle::where('title', $data['title'])->firstOrFail();
        $this->put('/knowledge/'.$article->id, array_merge($data, ['status' => 'Published']))->assertForbidden();
        $this->get('/knowledge/'.$article->id.'/edit')->assertOk();
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password123', 'status' => 'Active', 'role_id' => $this->bookkeeper->role_id]);
        $this->actingAs($other)->get('/knowledge/'.$article->id)->assertForbidden();
        $this->get('/search?q=Bookkeeper%20draft')->assertJsonCount(0);
        $this->actingAs($this->manager)->put('/knowledge/'.$article->id, array_merge($data, ['status' => 'Published']))->assertRedirect();
        $this->actingAs($this->bookkeeper)->get('/knowledge/'.$article->id)->assertOk()->assertSee('&lt;script&gt;', false);
        $this->get('/knowledge/'.$article->id.'/edit')->assertForbidden();
    }

    public function test_notice_workflow_is_managerial_internal_and_audited(): void
    {
        $this->actingAs($this->manager)->get('/notices')->assertOk();
        $this->get('/notices/create')->assertOk();
        $this->post('/notices', ['title' => 'Missing records', 'body' => 'Please supply the receipts.', 'client_id' => Client::first()->id])->assertRedirect();
        $notice = Notice::firstOrFail();
        $this->get('/notices/'.$notice->id)->assertOk();
        $this->get('/notices/'.$notice->id.'/edit')->assertOk();
        $this->post('/notices/'.$notice->id.'/publish')->assertRedirect();
        $this->assertSame('Published', $notice->fresh()->status);
        $this->put('/notices/'.$notice->id, ['title' => 'Updated notice', 'body' => 'Updated content', 'client_id' => $notice->client_id])->assertRedirect();
        $this->assertSame('Draft', $notice->fresh()->status);
        $this->post('/notices/'.$notice->id.'/publish')->assertRedirect();
        $this->actingAs($this->bookkeeper);
        $this->get('/dashboard')->assertOk()->assertDontSee('Updated notice');
        $this->get('/clients/'.$notice->client_id)->assertOk()->assertDontSee('Updated notice');
        foreach (['/notices', '/notices/create', '/notices/'.$notice->id, '/notices/'.$notice->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/notices', [])->assertForbidden();
        $this->put('/notices/'.$notice->id, [])->assertForbidden();
        $this->post('/notices/'.$notice->id.'/publish')->assertForbidden();
        $this->post('/notices/'.$notice->id.'/archive')->assertForbidden();
        $this->actingAs($this->owner)->post('/notices/'.$notice->id.'/archive')->assertRedirect();
        $this->assertSame('Archived', $notice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notice.published']);
    }

    public function test_bookkeeper_cannot_archive_reassign_or_access_unassigned_clients(): void
    {
        $client = Client::first();
        $this->actingAs($this->bookkeeper)->post('/clients/'.$client->id.'/archive')->assertForbidden();
        $this->put('/clients/'.$client->id, ['assigned_to' => $this->owner->id])->assertForbidden();
        $this->actingAs($this->manager)->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $this->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $client->update(['assigned_to' => $this->manager->id]);
        $this->actingAs($this->bookkeeper)->get('/clients/'.$client->id)->assertForbidden();
        $this->get('/reports?module=clients')->assertDontSee($client->business_name);
        $this->actingAs($this->manager)->get('/clients/'.$client->id)->assertOk();
    }

    public function test_role_dashboards_and_permissions_render_for_all_three_roles(): void
    {
        foreach ([$this->owner, $this->bookkeeper, $this->manager] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee($user->role->name.' overview');
            foreach (['/clients', '/documents', '/ledger', '/compliance', '/billing', '/knowledge', '/reports', '/profile'] as $url) {
                $this->get($url)->assertOk();
            }
            $this->get('/reports/csv?module=ledger')->assertOk();
            if (! $user->hasRole('owner')) {
                $this->get('/dashboard')->assertDontSee('User Management')->assertDontSee('Audit Logs');
            }
            if ($user->hasRole('bookkeeper')) {
                $this->get('/dashboard')->assertDontSee('href="'.route('notices.index').'"', false);
            }
        }
    }

    public function test_revoked_module_visibility_also_removes_profile_and_search_data(): void
    {
        $doc = Document::first();
        $doc->update(['title' => 'RestrictedDocumentTitle']);
        $this->bookkeeper->role->permissions()->detach(Permission::where('name', 'document.view')->value('id'));
        $this->actingAs($this->bookkeeper);
        $this->get('/documents')->assertForbidden();
        $this->get('/documents/'.$doc->id)->assertForbidden();
        $this->get('/reports?module=documents')->assertForbidden();
        $this->get('/reports/csv?module=documents')->assertForbidden();
        $this->get('/search?q=RestrictedDocumentTitle')->assertJsonCount(0);
        $this->get('/clients/'.$doc->client_id)->assertOk()->assertDontSee('RestrictedDocumentTitle');
    }
}
```

### `C:\laragon\www\veritas-core\tests\Feature\WorkspaceTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->staff = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    private function clientData(array $extra = []): array
    {
        return array_merge(['business_name' => 'Test Trading', 'business_type' => 'Corporation', 'email' => 'owner@example.com', 'registration_status' => 'On file', 'business_license_status' => 'On file', 'status' => 'Active'], $extra);
    }

    private function ledgerData(string $credit = '100.00'): array
    {
        return ['client_id' => Client::first()->id, 'transaction_date' => today()->toDateString(), 'reference_number' => 'TEST-LEDGER', 'description' => 'Test balanced entry', 'items' => [['account_name' => 'Cash', 'debit' => '100.00', 'credit' => '0.00'], ['account_name' => 'Revenue', 'debit' => '0.00', 'credit' => $credit]]];
    }

    private function invoiceData(): array
    {
        return ['client_id' => Client::first()->id, 'invoice_date' => today()->toDateString(), 'due_date' => today()->addDays(10)->toDateString(), 'tax' => '24.06', 'items' => [['description' => 'Accounting review', 'quantity' => '2.00', 'unit_price' => '100.25']]];
    }

    public function test_guests_are_redirected_and_login_logout_work(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->admin);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->admin->update(['status' => 'Inactive']);
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password123'])->assertSessionHasErrors('email');
    }

    public function test_all_pages_forms_and_details_render(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->admin);
        foreach (['/dashboard', '/clients', '/documents', '/ledger', '/compliance', '/billing', '/knowledge', '/workspace', '/reports', '/admin/users', '/admin/audit-logs', '/profile', '/admin/users/create', '/admin/users/'.$this->staff->id.'/edit'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['clients' => Client::class, 'documents' => Document::class, 'ledger' => LedgerEntry::class, 'compliance' => ComplianceRecord::class, 'billing' => Invoice::class, 'knowledge' => KnowledgeArticle::class] as $module => $model) {
            $record = $model::first();
            $this->get('/'.$module.'/create')->assertOk();
            $this->get('/'.$module.'/'.$record->id)->assertOk();
            $this->get('/reports?module='.$module)->assertOk();
            if (! in_array($module, ['ledger', 'billing'])) {
                $this->get('/'.$module.'/'.$record->id.'/edit')->assertOk();
            }
            $this->get('/'.$module.'/create', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('name="_token"', false);
        }
        $this->withExceptionHandling();
        $this->get('/missing-page')->assertNotFound()->assertSee('Page not found');
    }

    public function test_staff_cannot_bypass_admin_or_assigned_client_boundaries(): void
    {
        $private = Client::create($this->clientData(['client_code' => 'PRIVATE', 'business_name' => 'PrivateClientSecret', 'created_by' => $this->admin->id, 'assigned_to' => $this->admin->id]));
        $document = Document::create(['client_id' => $private->id, 'document_number' => 'PRIVATE-DOC', 'title' => 'PrivateDocumentSecret', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => today(), 'uploaded_by' => $this->admin->id]);
        $this->actingAs($this->staff);
        foreach (['/workspace', '/admin/users', '/admin/users/create', '/admin/audit-logs', '/clients/'.$private->id, '/documents/'.$document->id, '/documents/'.$document->id.'/download'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/clients')->assertDontSee('PrivateClientSecret');
        $this->get('/search?q=Secret')->assertOk()->assertJsonCount(0);
        $this->get('/reports?module=clients')->assertDontSee('PrivateClientSecret');
        $this->put('/clients/'.$private->id, $this->clientData())->assertForbidden();
        $this->post('/documents', ['client_id' => $private->id, 'title' => 'Blocked', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => today()->toDateString()])->assertNotFound();
        $this->post('/clients/'.$private->id.'/archive')->assertForbidden();
        $this->post('/admin/users', [])->assertForbidden();
    }

    public function test_staff_client_crud_preserves_assignment_and_admin_archives(): void
    {
        $this->actingAs($this->staff)->post('/clients', $this->clientData(['assigned_to' => $this->admin->id, 'status' => 'Archived']))->assertForbidden();
        $this->post('/clients', $this->clientData())->assertRedirect();
        $client = Client::where('business_name', 'Test Trading')->firstOrFail();
        $this->assertEquals($this->staff->id, $client->assigned_to);
        $this->assertSame('Active', $client->status);
        $this->put('/clients/'.$client->id, $this->clientData(['business_name' => 'Updated Trading']))->assertRedirect();
        $this->assertSame('Updated Trading', $client->fresh()->business_name);
        $this->delete('/clients/'.$client->id)->assertForbidden();
        $this->actingAs($this->admin)->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $this->assertSame('Archived', $client->fresh()->status);
        $this->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $this->assertSame('Active', $client->fresh()->status);
        $this->post('/clients', $this->clientData(['email' => 'not-an-email', 'tin' => 'letters']))->assertSessionHasErrors(['email', 'tin']);
    }

    public function test_documents_upload_download_validation_and_soft_delete(): void
    {
        Storage::fake('local');
        $this->actingAs($this->staff);
        $data = ['client_id' => Client::first()->id, 'title' => 'Uploaded receipt', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => today()->toDateString()];
        $this->post('/documents', $data + ['file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')])->assertRedirect();
        $document = Document::where('title', 'Uploaded receipt')->firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);
        $this->get('/documents/'.$document->id.'/download')->assertDownload('receipt.pdf');
        $this->actingAs($this->admin)->put('/documents/'.$document->id, array_merge($data, ['status' => 'Needs Clarification', 'notes' => 'Please provide a clearer copy.']))->assertRedirect();
        $this->assertSame('Needs Clarification', $document->fresh()->status);
        $this->actingAs($this->staff)->post('/documents', $data + ['file' => UploadedFile::fake()->create('bad.exe', 1, 'application/octet-stream')])->assertSessionHasErrors('file');
        $this->delete('/documents/'.$document->id)->assertForbidden();
        $this->actingAs($this->admin)->delete('/documents/'.$document->id)->assertRedirect();
        $this->assertSoftDeleted($document);
    }

    public function test_ledger_balance_and_independent_review(): void
    {
        $this->actingAs($this->staff)->post('/ledger', $this->ledgerData('99.99'))->assertRedirect();
        $entry = LedgerEntry::where('reference_number', 'TEST-LEDGER')->firstOrFail();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasErrors('items');
        $this->put('/ledger/'.$entry->id, $this->ledgerData())->assertRedirect();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertRedirect();
        $this->assertSame('For Review', $entry->fresh()->status);
        $this->put('/ledger/'.$entry->id, $this->ledgerData())->assertForbidden();
        $this->delete('/ledger/'.$entry->id)->assertForbidden();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs($this->admin)->post('/ledger/'.$entry->id.'/transition', ['action' => 'return', 'notes' => 'Check source'])->assertRedirect();
        $this->actingAs($this->staff)->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertRedirect();
        $this->actingAs($this->admin)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertRedirect();
        $this->assertSame('Reviewed', $entry->fresh()->status);
        $this->assertEquals($this->admin->id, $entry->fresh()->reviewed_by);
    }

    public function test_invoice_calculation_partial_payments_and_pdf(): void
    {
        $this->actingAs($this->staff)->post('/billing', $this->invoiceData())->assertRedirect();
        $invoice = Invoice::latest('id')->first();
        $this->assertSame('224.56', $invoice->total_amount);
        $this->get('/billing/'.$invoice->id.'/edit')->assertOk();
        $this->post('/billing/'.$invoice->id.'/transition', ['action' => 'issue'])->assertRedirect();
        $this->put('/billing/'.$invoice->id, $this->invoiceData())->assertForbidden();
        $pay = ['payment_date' => today()->toDateString(), 'amount' => '100.00', 'payment_method' => 'Bank Transfer'];
        $this->post('/billing/'.$invoice->id.'/payments', $pay)->assertRedirect();
        $this->assertSame('124.56', $invoice->fresh()->balance);
        $this->assertSame('Partially Paid', $invoice->fresh()->display_status);
        $this->post('/billing/'.$invoice->id.'/payments', array_merge($pay, ['amount' => '125.00']))->assertSessionHasErrors('amount');
        $this->post('/billing/'.$invoice->id.'/payments', array_merge($pay, ['amount' => '124.56']))->assertRedirect();
        $this->assertSame('Paid', $invoice->fresh()->display_status);
        $this->actingAs($this->admin)->post('/billing/'.$invoice->id.'/transition', ['action' => 'cancel'])->assertSessionHasErrors('invoice');
        $this->get('/billing/'.$invoice->id.'/print')->assertOk()->assertSee('224.56');
        $pdf = $this->get('/billing/'.$invoice->id.'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/billing?status=Paid')->assertOk()->assertSee($invoice->invoice_number);
    }

    public function test_compliance_filing_requires_date_and_reference(): void
    {
        $record = ComplianceRecord::first();
        $data = $record->only(['client_id', 'agency', 'requirement', 'reporting_period', 'status', 'assigned_to']);
        $data['due_date'] = today()->subDay()->toDateString();
        $this->actingAs($this->admin)->put('/compliance/'.$record->id, array_merge($data, ['status' => 'Filed']))->assertSessionHasErrors(['filed_date', 'reference_number']);
        $this->put('/compliance/'.$record->id, array_merge($data, ['status' => 'Filed', 'filed_date' => today()->toDateString(), 'reference_number' => 'ACK-2026']))->assertRedirect();
        $this->assertSame('Filed', $record->fresh()->display_status);
        $this->put('/compliance/'.$record->id, array_merge($data, ['status' => 'Pending']))->assertRedirect();
        $this->assertSame('Overdue', $record->fresh()->display_status);
    }

    public function test_knowledge_authorization_and_escaped_content(): void
    {
        $data = ['title' => 'Knowledge test', 'category' => 'Accounting', 'status' => 'Published', 'tags' => 'audit,review', 'content' => '<script>alert(1)</script>'];
        $this->actingAs($this->staff)->post('/knowledge', $data)->assertForbidden();
        $this->actingAs($this->admin)->post('/knowledge', $data)->assertRedirect();
        $article = KnowledgeArticle::where('title', 'Knowledge test')->firstOrFail();
        $this->actingAs($this->staff)->get('/knowledge/'.$article->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $article->update(['status' => 'Draft']);
        $this->get('/knowledge/'.$article->id)->assertForbidden();
    }

    public function test_notifications_are_private_and_idempotent(): void
    {
        $this->actingAs($this->staff);
        $first = $this->get('/notifications')->assertOk();
        $count = $this->staff->notifications()->count();
        $this->assertGreaterThan(0, $count);
        $this->get('/notifications')->assertOk();
        $this->assertSame($count, $this->staff->notifications()->count());
        $id = $first->json('items.0.id');
        $this->actingAs($this->admin)->post('/notifications/'.$id.'/read')->assertNotFound();
        $this->actingAs($this->staff)->post('/notifications/'.$id.'/read')->assertOk();
        $this->post('/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->staff->unreadNotifications()->count());
    }

    public function test_reports_filters_search_and_csv(): void
    {
        $this->actingAs($this->admin);
        $this->get('/clients?q=Davao&sort=business_name&direction=asc')->assertOk()->assertSee('Davao Prime Trading')->assertDontSee('Lanang Caf? Group');
        $this->get('/search?q=Davao')->assertOk()->assertJsonFragment(['title' => 'Davao Prime Trading']);
        $csv = $this->get('/reports/csv?module=clients')->assertOk();
        $this->assertStringContainsString('Davao Prime Trading', $csv->streamedContent());
        foreach (['Open', 'Overdue', 'Partially Paid', 'Paid', 'Draft', 'Cancelled'] as $status) {
            $this->get('/reports?module=billing&status='.urlencode($status))->assertOk();
        }
    }

    public function test_password_reset_uses_tokens_and_profile_requires_password(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => $this->staff->email])->assertRedirect();
        Notification::assertSentTo($this->staff, ResetPassword::class);
        $token = Password::createToken($this->staff);
        $this->post('/reset-password', ['email' => $this->staff->email, 'token' => $token, 'password' => 'newPassword456', 'password_confirmation' => 'newPassword456'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('newPassword456', $this->staff->fresh()->password));
        $this->actingAs($this->staff->fresh())->patch('/profile', ['name' => 'New Name', 'email' => $this->staff->email, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->patch('/profile', ['name' => 'New Name', 'email' => $this->staff->email, 'current_password' => 'newPassword456'])->assertRedirect();
        $this->assertSame('New Name', $this->staff->fresh()->name);
    }

    public function test_account_management_and_workspace_settings(): void
    {
        $this->actingAs($this->admin);
        $data = ['name' => 'Employee Two', 'email' => 'two@example.com', 'role_id' => $this->staff->role_id, 'status' => 'Active', 'password' => 'safePassword123', 'password_confirmation' => 'safePassword123'];
        $this->post('/admin/users', $data)->assertRedirect();
        $user = User::where('email', 'two@example.com')->firstOrFail();
        $this->put('/admin/users/'.$user->id, array_merge($data, ['status' => 'Inactive']))->assertRedirect();
        $this->assertSame('Inactive', $user->fresh()->status);
        $this->put('/admin/users/'.$this->admin->id, array_merge($data, ['email' => $this->admin->email, 'role_id' => $this->admin->role_id, 'status' => 'Inactive']))->assertForbidden();
        $this->put('/workspace', ['firm_name' => 'RBCIA Accounting Firm', 'firm_email' => 'firm@example.com', 'currency' => 'PHP', 'page_size' => 25, 'notifications_enabled' => 1])->assertRedirect();
        $this->assertDatabaseHas('settings', ['page_size' => 25]);
        $this->assertGreaterThan(0, AuditLog::count());
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    }
}
```
