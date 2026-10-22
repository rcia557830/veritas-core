# UI and usability changes - 2026-10-02

Project root: `C:\laragon\www\veritas-core`.

These changes preserve the existing design, route names, authentication, three-role RBAC, CRUD workflow, financial calculations and relationships. The preceding RBAC request is documented separately in `docs/RBAC.md` and `docs/RBAC-CODE.md`.

## Behavior

- Clients, Documents, Ledger Review, Compliance, Billing, Knowledge, Users and Reports use Laravel backend pagination. Notices and audit logs use it too. Default is 10 rows; 25 and 50 can be selected in the filter form, then applied. An Owner-configured workspace default of 25/50 remains respected. Legacy 15-row settings display as 10 without a schema change.
- Previous/numbered/Next controls retain search, status, client, date, sort and page-size query parameters with `withQueryString()`. A new filter submission or sorting change starts on page one. The range reads `Showing 1 to 10 of 57 records` and still appears for one-page/empty results.
- Both `q` and `search` URLs are supported; `q` takes precedence when both are present. Billing search matches invoice numbers, notes, client names, payment reference numbers and displayed statuses. Search uses database partial matching.
- Billing cards summarize all invoices **the signed-in user can access**, independently of table search, filters, sorting and pagination. Owner/Office Manager totals cover the full authorized database; Bookkeeper totals cover assigned clients. This preserves RBAC instead of leaking other clients' finances. Totals still update when underlying invoices or payments change; “static” means independent of table filters, not hard-coded or frozen.
- Report totals remain tied to the explicit report filters; that page is the separate filtered analytics view. CSV exports include every matching authorized record, not just the current page.
- Larger typography, darker muted text, clearer table headings/badges and stronger summary values retain Manrope and the navy/gold theme. No opacity changes were made to decorative shadows/watermarks. Responsive table wrappers remain, and pagination wraps on mobile.

## Commands

Already implemented in the project. No database migration or npm build is required for these usability changes.

```powershell
cd C:\laragon\www\veritas-core
php artisan optimize:clear
php artisan test
php vendor/bin/phpunit --configuration phpunit.mysql.xml
```

The second test configuration uses only `veritas_core_test_codex`, not the application database. The local default page size was updated from 15 to 10; existing 25/50 settings are preserved.

## Verification and visual limits

The full suite includes RBAC/CRUD/payment regressions and the new pagination/search/summary tests. Automated tests render the Blade pages. Browser inspection was unavailable because the connected browser/app inventory was empty. Check desktop and narrow-screen appearance using `docs/ACCEPTANCE.md` before sharing the UI externally.

## Complete updated files

Each path below is exact and contains the complete final file. Existing files not listed here retain their prior role in the application; shared module/report controllers already use `Records::pageSize()` and `withQueryString()`.

### `C:\laragon\www\veritas-core\app\Services\Records.php`

Keeps database search/filter/sort separate from summaries; accepts q and search, matches payment references and displayed invoice statuses, validates 10/25/50 page sizes, and maps the old 15-row setting to 10.

```php
<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class Records
{
    public static function query(string $module, Request $request): Builder
    {
        $request->validate([
            'q' => 'nullable|string|max:150', 'search' => 'nullable|string|max:150', 'status' => 'nullable|string|max:60',
            'client_id' => 'nullable|integer|min:1', 'type' => 'nullable|string|max:255',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'sort' => 'nullable|string|max:60', 'direction' => 'nullable|in:asc,desc',
        ]);
        $config = Modules::get($module);
        $query = Access::query($config['model']);
        if (! in_array($module, ['clients', 'knowledge'])) {
            $query->with('client');
        }
        if ($module === 'billing') {
            $query->with(['items', 'payments']);
        }
        if ($module === 'ledger') {
            $query->with('items');
        }
        if ($search = self::searchText($request)) {
            $query->where(function ($q) use ($search, $config, $module) {
                foreach ($config['search'] as $column) {
                    $q->orWhere($column, 'like', '%'.mb_substr($search, 0, 150).'%');
                }
                if ($module === 'billing') {
                    $q->orWhereHas('payments', fn ($payments) => $payments->where('reference_number', 'like', '%'.$search.'%'));
                    // Search the displayed financial status, not the stored Open lifecycle alone.
                    foreach ($config['statuses'] as $label) {
                        if (! str_contains(mb_strtolower($label), mb_strtolower($search))) {
                            continue;
                        }
                        $q->orWhere(function ($statusQuery) use ($label) {
                            if (in_array($label, ['Draft', 'Cancelled'])) {
                                $statusQuery->where('status', $label);
                            } else {
                                self::invoiceStatus($statusQuery, $label);
                            }
                        });
                    }
                }
                if (! in_array($module, ['clients', 'knowledge'])) {
                    $q->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', '%'.mb_substr($search, 0, 150).'%'));
                }
            });
        }
        if ($client = $request->integer('client_id')) {
            if (! in_array($module, ['clients', 'knowledge'])) {
                $query->where('client_id', $client);
            }
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, $config['statuses'])) {
            if ($module === 'compliance' && $status === 'Overdue') {
                $query->where('status', '!=', 'Filed')->whereDate('due_date', '<', today());
            } elseif ($module === 'billing' && ! in_array($status, ['Draft', 'Cancelled'])) {
                self::invoiceStatus($query, $status);
            } else {
                $query->where('status', $status);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $date = $request->query($key);
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $query->whereDate($config['date'], $operator, $date);
            }
        }
        $typeColumn = ['clients' => 'business_type', 'documents' => 'document_type', 'compliance' => 'agency', 'knowledge' => 'category'][$module] ?? null;
        if ($typeColumn && $request->filled('type')) {
            $query->where($typeColumn, (string) $request->query('type'));
        }
        $allowed = array_unique(array_merge(['id', $config['date']], array_keys($config['fields'])));
        $sort = in_array($request->query('sort'), $allowed) ? $request->query('sort') : $config['date'];

        return $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc')->orderBy('id', 'desc');
    }

    public static function invoiceStatus(Builder $query, string $status): void
    {
        // Static SQL expressions only. Filter values remain bound parameters.
        $total = '(COALESCE((SELECT SUM(ROUND(quantity * unit_price,2)) FROM invoice_items WHERE invoice_items.invoice_id = invoices.id),0) + invoices.tax)';
        $paid = 'COALESCE((SELECT SUM(amount) FROM payments WHERE payments.invoice_id = invoices.id),0)';
        $query->where('status', 'Open');
        if ($status === 'Paid') {
            $query->whereRaw("$paid >= $total");
        } else {
            $query->whereRaw("$paid < $total");
            if ($status === 'Overdue') {
                $query->whereDate('due_date', '<', today());
            } else {
                $query->whereDate('due_date', '>=', today());
                if ($status === 'Partially Paid') {
                    $query->whereHas('payments');
                } else {
                    $query->whereDoesntHave('payments');
                }
            }
        }
    }

    public static function searchText(Request $request): string
    {
        $request->validate(['q' => 'nullable|string|max:150', 'search' => 'nullable|string|max:150']);

        return trim((string) ($request->query('q') ?? $request->query('search', '')));
    }

    public static function pageSize(?Request $request = null): int
    {
        $request ??= request();
        $request->validate(['per_page' => 'nullable|integer|in:10,25,50', 'page' => 'nullable|integer|min:1']);
        $configured = (int) Setting::value('page_size');
        $default = in_array($configured, [10, 25, 50]) ? $configured : 10;

        return $request->filled('per_page') ? (int) $request->query('per_page') : $default;
    }
}
```

### `C:\laragon\www\veritas-core\app\Http\Controllers\InvoiceController.php`

Adds a billing index with separate unfiltered authorized totals and a filtered, paginated invoice query. Existing invoice/payment calculations and workflow methods are retained.

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

### `C:\laragon\www\veritas-core\app\Http\Controllers\UserController.php`

Uses shared backend page sizes and search aliases for the user listing; preserves Owner-only account authorization.

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

### `C:\laragon\www\veritas-core\app\Http\Controllers\NoticeController.php`

Uses the same page sizes and search aliases for notices; preserves the managerial posting workflow.

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

### `C:\laragon\www\veritas-core\app\Http\Controllers\AuditLogController.php`

Uses the same pagination and search behavior for Owner-only audit logs.

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

### `C:\laragon\www\veritas-core\app\Http\Controllers\SettingController.php`

Accepts 10, 25 and 50 as the workspace page-size choices.

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

### `C:\laragon\www\veritas-core\database\seeders\DatabaseSeeder.php`

New installations default to 10 records per page; existing records and accounts are retained by firstOrCreate.

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

### `C:\laragon\www\veritas-core\resources\views\components\per-page.blade.php`

Reusable server-side page-size selector submitted with the filter form.

```blade
<label class="per-page-control">Per page<select name="per_page" class="form-select" aria-label="Records per page">@foreach([10,25,50] as $size)<option value="{{ $size }}" @selected(\App\Services\Records::pageSize()===$size)>{{ $size }} per page</option>@endforeach</select></label>
```

### `C:\laragon\www\veritas-core\resources\views\components\pagination.blade.php`

Always displays the record range, including empty results, and renders pagination controls.

```blade
@props(['records'])
<div class="record-pagination" aria-label="Record counts and pages">
<p class="pagination-summary" role="status">Showing <strong>{{ $records->firstItem()??0 }}</strong> to <strong>{{ $records->lastItem()??0 }}</strong> of <strong>{{ $records->total() }}</strong> records</p>
{{ $records->onEachSide(1)->links('pagination.veritas') }}
</div>
```

### `C:\laragon\www\veritas-core\resources\views\pagination\veritas.blade.php`

Accessible Previous/numbered/Next links generated by Laravel, with a compact page window.

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

### `C:\laragon\www\veritas-core\resources\views\billing\summary.blade.php`

Shows Total billed, Total collected, Outstanding balance and Overdue amount with an explanation of their unfiltered scope.

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

### `C:\laragon\www\veritas-core\resources\views\records\index.blade.php`

Adds the billing totals and applies consistent pagination to compliance and knowledge cards.

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

### `C:\laragon\www\veritas-core\resources\views\records\table.blade.php`

Uses shared pagination, aligns monetary values and resets to page one on sort changes while retaining other parameters.

```blade
<div class="panel table-panel"><div class="table-responsive"><table class="table table-hover"><caption class="visually-hidden">{{ $config['title'] }} records</caption><thead><tr>
@foreach($config['columns'] as $key=>$label)<th scope="col">@if(in_array($key,array_keys($config['fields'])) || in_array($key,['id',$config['date']]))<a class="sort-button" href="{{ request()->fullUrlWithQuery(['page'=>1,'sort'=>$key,'direction'=>request('sort')===$key && request('direction')==='asc'?'desc':'asc']) }}">{{ $label }} <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a>@else{{ $label }}@endif</th>@endforeach<th scope="col">Actions</th></tr></thead><tbody>
@forelse($records as $record)<tr>@foreach($config['columns'] as $key=>$label)<td class="{{ in_array($key,['total_amount','amount_paid','balance','tax'])?'numeric':'' }}">@if($key==='status')<x-badge :status="$record->display_status??$record->status"/>@elseif($key===$config['label'])<a class="text-button" href="{{ route($module.'.show',$record) }}">{{ \App\Support\Display::value($record,$key) }}</a>@else{{ \App\Support\Display::value($record,$key) }}@endif</td>@endforeach
<td><div class="actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.show',$record) }}">View</a>@can('update',$record)<a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.edit',$record) }}">Edit</a>@endcan</div></td></tr>
@empty<tr><td colspan="{{ count($config['columns'])+1 }}"><div class="empty-state"><div class="empty-icon"><i class="bi bi-{{ $config['icon'] }}" aria-hidden="true"></i></div><h2>No {{ strtolower($config['title']) }} found</h2><p>Try another filter or add a new {{ $config['singular'] }}.</p>@can('create',$config['model'])<a data-modal class="btn btn-primary" href="{{ route($module.'.create') }}">New {{ $config['singular'] }}</a>@endcan</div></td></tr>@endforelse
</tbody></table></div></div><x-pagination :records="$records"/>
```

### `C:\laragon\www\veritas-core\resources\views\records\filters.blade.php`

Preserves sorting, displays legacy search aliases, and adds the page-size choice without carrying a stale page number.

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

### `C:\laragon\www\veritas-core\resources\views\admin\users\index.blade.php`

Adds record counts and page-size controls to user management.

```blade
@extends('layouts.app')
@section('title','User Management')
@section('content')<div class="toolbar"><p class="toolbar-copy">Manage employee access</p><a class="btn btn-primary" href="{{ route('admin.users.create') }}">Add user</a></div><form method="get" class="filters"><label>Search<input class="form-control" name="q" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Status<select class="form-select" name="status"><option value="">All</option>@foreach(['Active','Inactive'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button></form>
<div class="panel table-panel table-responsive"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead><tbody>@forelse($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role?->name }}</td><td><x-badge :status="$user->status"/></td><td>{{ $user->last_login_at?->format('M j, Y g:i A')??'Not yet' }}</td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.edit',$user) }}">Edit account</a><form class="d-inline" method="post" action="{{ route('admin.users.reset',$user) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Send reset link</button></form></td></tr>@empty<tr><td colspan="6"><div class="empty-state"><h2>No matching users</h2><p>Try a different search.</p><a class="btn btn-primary" href="{{ route('admin.users.index') }}">Clear search</a></div></td></tr>@endforelse</tbody></table></div><x-pagination :records="$users"/>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\admin\audit.blade.php`

Adds record counts and page-size controls to audit logs.

```blade
@extends('layouts.app')
@section('title','Audit Logs')
@section('content')<form method="get" class="filters"><label>Search<input name="q" class="form-control" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Module<select class="form-select" name="module"><option value="">All modules</option>@foreach(['auth','clients','documents','ledger','compliance','billing','knowledge','users','workspace'] as $module)<option @selected(request('module')===$module)>{{ $module }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button></form><div class="panel table-panel table-responsive"><table class="table"><thead><tr><th>Time</th><th>Employee</th><th>Action</th><th>Module</th><th>Description</th><th>IP address</th></tr></thead><tbody>@forelse($logs as $log)<tr><td>{{ $log->created_at->format('M j, Y g:i:s A') }}</td><td>{{ $log->user?->name??'System' }}</td><td>{{ $log->action }}</td><td>{{ $log->module }}</td><td>{{ $log->description }}</td><td>{{ $log->ip_address }}</td></tr>@empty<tr><td colspan="6">No matching activity. Try a different search.</td></tr>@endforelse</tbody></table></div><x-pagination :records="$logs"/>@endsection
```

### `C:\laragon\www\veritas-core\resources\views\notices\index.blade.php`

Adds record counts and page-size controls to notices.

```blade
@extends('layouts.app')
@section('title','Notices')
@section('content')
<div class="toolbar"><p class="toolbar-copy">Internal notices for Owners and Office Managers.</p>@can('create',\App\Models\Notice::class)<a class="btn btn-primary" href="{{ route('notices.create') }}">Create notice</a>@endcan</div>
<form class="filters" method="get"><label>Search<input class="form-control" name="q" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach(['Draft','Published','Archived'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">Clear</a></form>
<section class="panel table-panel"><div class="table-responsive"><table class="table"><thead><tr><th>Notice</th><th>Client</th><th>Status</th><th>Updated</th></tr></thead><tbody>@forelse($notices as $notice)<tr><td><a href="{{ route('notices.show',$notice) }}">{{ $notice->title }}</a></td><td>{{ $notice->client?->business_name??'General notice' }}</td><td><x-badge :status="$notice->status"/></td><td>{{ $notice->updated_at->format('M j, Y') }}</td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h2>No notices found</h2><p>Create a notice or clear your filters.</p></div></td></tr>@endforelse</tbody></table></div></section><x-pagination :records="$notices"/>
@endsection
```

### `C:\laragon\www\veritas-core\resources\views\settings\edit.blade.php`

Offers the new workspace page-size choices.

```blade
@extends('layouts.app')
@section('title','Workspace')
@section('content')<section class="panel panel-pad"><h2 class="section-title mb-4">Firm settings</h2><form method="post" action="{{ route('workspace.update') }}" enctype="multipart/form-data">@csrf @method('PUT')<div class="row"><div class="col-md-6"><x-field name="firm_name" label="Firm name" :value="$setting->firm_name" :required="true"/></div><div class="col-md-6"><x-field name="firm_email" label="Firm email" type="email" :value="$setting->firm_email"/></div><div class="col-md-6"><x-field name="contact_number" label="Contact number" :value="$setting->contact_number"/></div><div class="col-md-6"><x-field name="logo" label="Firm logo (PNG or JPG, up to 2 MB)" type="file"/></div><div class="col-12"><x-field name="firm_address" label="Firm address" type="textarea" :value="$setting->firm_address"/></div><div class="col-md-6"><x-field name="currency" label="Default currency" type="select" :value="$setting->currency" :options="['PHP'=>'PHP — Philippine Peso (₱)']"/></div><div class="col-md-6"><x-field name="page_size" label="Records per page" type="select" :value="$setting->page_size" :options="[10=>10,25=>25,50=>50]"/></div></div><div class="form-check mb-4"><input class="form-check-input" id="notificationsEnabled" name="notifications_enabled" type="checkbox" value="1" @checked(old('notifications_enabled',$setting->notifications_enabled))><label for="notificationsEnabled" class="form-check-label">Generate workspace notifications</label></div><button class="btn btn-primary">Save workspace settings</button></form></section>@endsection
```

### `C:\laragon\www\veritas-core\public\laravel.css`

Increases body/table/sidebar text to 15px, improves muted text contrast, strengthens badges and card values, and wraps pagination on narrow screens without changing the navy/gold layout.

```css
/* Blade integration: retain the original Veritas Core design tokens. */
a.nav-item, .profile-button, .chip { text-decoration: none; }
.sidebar .nav-item { padding-top: 9px; padding-bottom: 9px; }
.sidebar .nav-label { margin-top: var(--space-5); }
.sidebar .nav-divider { margin: var(--space-3) 0; }
.brand-caption { white-space: normal; }
.brand-logo { width: 40px; height: 44px; object-fit: contain; }
.auth-shell { min-height: 100vh; display: flex; justify-content: center; align-items: center; gap: 60px; padding: var(--space-7); background: var(--ink-950); }
.auth-brand { color: var(--on-dark); max-width: 400px; }
.auth-brand .brand-mark { margin-bottom: var(--space-6); }
.auth-brand h1 { font-size: 36px; }
.auth-card { width: 460px; max-width: 100%; height: auto; padding: var(--space-8); }
.alert-success { color: var(--success); background: var(--success-soft); border-color: var(--success-line); }
.alert-danger { color: var(--danger); background: var(--danger-soft); border-color: var(--danger-line); }
.page-link { color: var(--ink-700); }
.active > .page-link { background: var(--ink-900); border-color: var(--ink-900); }
[data-line-body] input[type=number] { min-width: 100px; }
[data-line-body] input[type=text] { min-width: 180px; }
.notification-item.is-unread { border-left: 3px solid var(--brand); }
@media(max-width: 767.98px) { .auth-shell { flex-direction: column; gap: 24px; } .auth-brand { text-align: center; } .auth-brand .brand-mark,.auth-brand .hero-summary { display: none; } }
@media print { .actions, .btn, .filters, .pagination { display: none !important; } }


/* Readability refinements: preserve the navy/gold palette and existing layout. */
:root { --ink-500: #4d6078; --muted-dark: #ccd6e3; }
body { font-size: 15px; }
h1 { font-size: 28px; font-weight: 600; }
.sidebar .nav-item { font-size: 15px; color: var(--muted-dark); }
.sidebar .nav-item.active, .sidebar .nav-item:hover { color: var(--on-dark); }
.brand-caption, .nav-label { font-size: 12px; }
.eyebrow { font-size: 11px; }
.search-trigger, .profile-copy strong, .profile-copy span { font-size: 13px; }
.hero-summary { font-size: 15px; }
.hero-date, .hero-note { font-size: 13px; }
.toolbar-copy, .section-description, .subtext, .form-text,
.empty-state p, .article-preview, .metric-label { font-size: 14px; color: var(--ink-500); }
.section-title { font-size: 16px; }
.btn { --bs-btn-font-size: 14px; --bs-btn-font-weight: 600; }
.btn-sm { font-size: 13px; }
.form-label, .filters > label { font-size: 15px; font-weight: 600; color: var(--ink-700); }
.form-control, .form-select { font-size: 15px; }
.form-control::placeholder { color: #65758a; opacity: 1; }
.table { font-size: 15px; --bs-table-hover-bg: #f0f3f7; }
.table thead th { font-size: 15px; font-weight: 700; color: var(--ink-700); padding: 12px 14px; }
.table tbody td, .table tfoot th { font-size: 15px; padding: 12px 14px; vertical-align: middle; }
.table .subtext, .timeline .subtext { font-size: 13px; }
.table .numeric { text-align: right; font-weight: 600; white-space: nowrap; }
.badge-status { font-size: 12px; font-weight: 600; padding: 5px 9px; }
.stat-label { font-size: 14px; font-weight: 600; color: var(--ink-700); }
.stat-value { font-size: clamp(28px, 2.6vw, 36px); font-weight: 700; }
.stat-note { font-size: 13px; color: var(--ink-500); }
.stat-note:empty { display: none; }
.stat-icon { font-size: 21px; }
.timeline .timeline-content, .prose, .detail-grid dd { font-size: 15px; }
.detail-grid dt, .mini-stat span, .deadline-date, .tag, .chip { font-size: 13px; }
.notification-item, .command-result { font-size: 14px; }
.command-result small, .command-footer { font-size: 12px; }
.record-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 20px; }
.pagination-summary { margin: 0; font-size: 14px; color: var(--ink-500); }
.record-pagination nav { max-width: 100%; }
.record-pagination .pagination { display: flex; flex-wrap: wrap; gap: 4px; margin: 0; }
.record-pagination .page-link { min-width: 38px; min-height: 40px; padding: 8px 12px; text-align: center; font-size: 14px; border-radius: var(--radius-sm); margin: 0; }
.record-pagination .page-link:hover { color: var(--ink-950); background: var(--brand-soft); border-color: var(--brand); }
.record-pagination .active > .page-link { color: var(--on-dark); background: var(--ink-900); border-color: var(--ink-900); }
.record-pagination .disabled > .page-link { color: var(--ink-500); background: var(--surface); opacity: 1; }
.filters .per-page-control { min-width: 130px; max-width: 155px; }
@media (max-width: 767.98px) {
  h1 { font-size: 25px; }
  .form-control, .form-select { font-size: 16px; }
  .record-pagination { align-items: flex-start; }
  .record-pagination nav { width: 100%; }
  .record-pagination .page-link { min-height: 44px; padding: 9px 11px; }
  .filters > label { min-width: min(140px, 100%); }
}
@media print { .record-pagination { display: none !important; } }
```

### `C:\laragon\www\veritas-core\tests\Feature\UsabilityTest.php`

Tests page sizes, retained filters, partial searches, payment/status search, fixed billing totals, zero matches and financial privacy.

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function addClients(): void
    {
        for ($i = 1; $i <= 37; $i++) {
            Client::create(['client_code' => 'PAG-'.$i, 'business_name' => 'ABC Pagination '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'business_type' => 'Corporation', 'status' => $i % 2 ? 'Active' : 'Inactive', 'created_by' => $this->owner->id, 'assigned_to' => User::where('email', 'bookkeeper@veritascore.local')->value('id')]);
        }
    }

    public function test_default_and_selectable_page_sizes_and_persisted_filters(): void
    {
        $this->addClients();
        $this->get('/clients')->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === 10 && $r->count() === 10 && $r->total() === 43)->assertSee('Showing')->assertSee('records');
        foreach ([10, 25, 50] as $size) {
            $this->get('/clients?per_page='.$size)->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === $size);
        }
        $response = $this->get('/clients?search=ABC&status=Active&sort=business_name&direction=asc&per_page=10&page=2')->assertOk();
        $response->assertViewHas('records', function ($records) {
            parse_str(parse_url($records->previousPageUrl(), PHP_URL_QUERY), $params);

            return $records->total() === 19 && $records->count() === 9 && $records->firstItem() === 11 && $params === ['search' => 'ABC', 'status' => 'Active', 'sort' => 'business_name', 'direction' => 'asc', 'per_page' => '10', 'page' => '1'];
        });
        $response->assertSee('name="sort" value="business_name"', false)->assertSee('name="direction" value="asc"', false);
        $this->getJson('/clients?per_page=100000')->assertUnprocessable();
        $this->getJson('/clients?per_page[]=10')->assertUnprocessable();
        $this->get('/clients?q=NoMatchingClient')->assertOk()->assertViewHas('records', fn ($r) => $r->total() === 0)->assertSee('Showing');
    }

    public function test_all_major_modules_and_reports_use_backend_pagination(): void
    {
        $map = ['documents' => [Document::class, 'document_number'], 'ledger' => [LedgerEntry::class, 'reference_number'], 'compliance' => [ComplianceRecord::class, 'requirement'], 'billing' => [Invoice::class, 'invoice_number'], 'knowledge' => [KnowledgeArticle::class, 'slug']];
        foreach ($map as $module => [$model,$unique]) {
            $original = $model::first();
            for ($i = 1; $i <= 15; $i++) {
                $copy = $original->replicate();
                $copy->$unique = 'PAG-'.$module.'-'.$i;
                $copy->save();
            }
            $this->get('/'.$module.'?page=2')->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === 10 && $r->currentPage() === 2 && $r->count() > 0);
            $this->get('/reports?module='.$module.'&per_page=10&page=2')->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === 10 && str_contains($r->previousPageUrl(), 'module='.$module));
        }
        for ($i = 1; $i <= 12; $i++) {
            User::create(['name' => 'Paged User '.$i, 'email' => 'paged'.$i.'@example.com', 'password' => 'password123', 'role_id' => $this->owner->role_id, 'status' => 'Active']);
        }
        $this->get('/admin/users?search=Paged&status=Active&per_page=10&page=2')->assertOk()->assertViewHas('users', fn ($r) => $r->total() === 12 && $r->count() === 2 && str_contains($r->previousPageUrl(), 'search=Paged'));
    }

    public function test_billing_totals_ignore_table_search_filters_sort_and_page(): void
    {
        $baseline = $this->get('/billing')->assertOk()->viewData('billingSummary');
        $this->assertSame('39000.00', $baseline['billed']);
        foreach (['?q=Davao', '?search=NoInvoiceMatches', '?status=Paid', '?client_id='.Client::first()->id, '?from=2099-01-01', '?per_page=25&sort=due_date&direction=asc&page=2'] as $query) {
            $this->get('/billing'.$query)->assertOk()->assertViewHas('billingSummary', $baseline);
        }
        $this->get('/billing?q=NoInvoiceMatches')->assertViewHas('records', fn ($r) => $r->total() === 0)->assertSee('39,000.00')->assertSee('Search and filters below affect the table only.');
        $this->get('/billing?search=PAY-001')->assertViewHas('records', fn ($r) => $r->total() === 1);
        $this->get('/billing?search=Paid')->assertViewHas('records', fn ($r) => $r->total() === 1);
        $this->get('/billing?search=Overdue')->assertViewHas('records', fn ($r) => $r->total() === 1);
        $this->get('/billing?search=INV-')->assertViewHas('records', fn ($r) => $r->total() === 6);
    }

    public function test_static_billing_totals_do_not_leak_unassigned_finances(): void
    {
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $client = Client::create(['client_code' => 'SECRET-FINANCE', 'business_name' => 'Private Finance Client', 'business_type' => 'Corporation', 'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id]);
        $invoice = Invoice::create(['invoice_number' => 'PRIVATE-INVOICE', 'client_id' => $client->id, 'invoice_date' => today(), 'due_date' => today()->addDays(10), 'tax' => 0, 'status' => 'Open', 'created_by' => $this->owner->id]);
        $invoice->items()->create(['description' => 'Confidential', 'quantity' => 1, 'unit_price' => '777777.00']);
        $this->actingAs($bookkeeper);
        foreach (['/billing', '/billing?search=PRIVATE', '/billing?client_id='.$client->id] as $url) {
            $this->get($url)->assertOk()->assertViewHas('billingSummary', fn ($s) => $s['billed'] === '39000.00')->assertDontSee('Private Finance Client')->assertDontSee('777,777');
        }
    }

    public function test_page_size_settings_accept_ten_and_legacy_fifteen_falls_back_to_ten(): void
    {
        Setting::first()->update(['page_size' => 15]);
        $this->get('/clients')->assertViewHas('records', fn ($r) => $r->perPage() === 10);
        $this->put('/workspace', ['firm_name' => 'RBCIA', 'currency' => 'PHP', 'page_size' => 10])->assertRedirect();
        $this->assertDatabaseHas('settings',['page_size' => 10]);
    }
}
```
