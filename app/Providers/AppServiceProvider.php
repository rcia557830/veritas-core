<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AccountTemplate;
use App\Models\Client;
use App\Models\ComplianceFollowUp;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentFollowUp;
use App\Models\DocumentRequirement;
use App\Models\DocumentRequirementTemplate;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\AccountTemplatePolicy;
use App\Policies\ComplianceFollowUpPolicy;
use App\Policies\FollowUpPolicy;
use App\Policies\RequirementPolicy;
use App\Policies\RequirementTemplatePolicy;
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
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(AccountTemplate::class, AccountTemplatePolicy::class);
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
        Gate::policy(DocumentRequirement::class, RequirementPolicy::class);
        Gate::policy(DocumentRequirementTemplate::class, RequirementTemplatePolicy::class);
        Gate::policy(DocumentFollowUp::class, FollowUpPolicy::class);
        Gate::policy(ComplianceFollowUp::class, ComplianceFollowUpPolicy::class);

        View::composer(['layouts.app', 'billing.print'], function ($view) {
            $view->with('firm', Setting::first());
        });
    }
}
