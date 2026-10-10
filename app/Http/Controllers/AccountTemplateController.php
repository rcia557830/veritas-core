<?php

namespace App\Http\Controllers;

use App\Models\AccountTemplate;
use App\Models\AccountTemplateItem;
use App\Services\Accounting\TemplateManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountTemplateController extends Controller
{
    public function index()
    {
        Gate::authorize('manage', AccountTemplate::class);

        return view('account-templates.index', ['templates' => AccountTemplate::withCount('items')->orderBy('name')->orderByDesc('version')->paginate(25)]);
    }

    public function create()
    {
        Gate::authorize('manage', AccountTemplate::class);

        return view('account-templates.form', ['template' => new AccountTemplate(['version' => 1, 'is_active' => false])]);
    }

    public function store(Request $request)
    {
        $template = TemplateManager::save($request->all());

        return redirect()->route('account-templates.show', $template)->with('success', 'Template created. Add account items before initialization.');
    }

    public function show(AccountTemplate $accountTemplate)
    {
        Gate::authorize('manage', AccountTemplate::class);

        return view('account-templates.show', ['template' => $accountTemplate, 'items' => $accountTemplate->items()->orderBy('code')->paginate(25), 'used' => $accountTemplate->items()->whereHas('accounts')->exists()]);
    }

    public function edit(AccountTemplate $accountTemplate)
    {
        Gate::authorize('manage', AccountTemplate::class);

        return view('account-templates.form', ['template' => $accountTemplate]);
    }

    public function update(Request $request, AccountTemplate $accountTemplate)
    {
        TemplateManager::save($request->all(), $accountTemplate);

        return redirect()->route('account-templates.show', $accountTemplate)->with('success', 'Template saved.');
    }

    public function version(AccountTemplate $accountTemplate)
    {
        $copy = TemplateManager::version($accountTemplate);

        return redirect()->route('account-templates.show', $copy)->with('success', 'New inactive version created. Review its items before activating it.');
    }

    public function createItem(AccountTemplate $accountTemplate)
    {
        Gate::authorize('manage', AccountTemplate::class);
        TemplateManager::editable($accountTemplate);

        return view('account-templates.item', ['template' => $accountTemplate, 'item' => new AccountTemplateItem(['is_active' => true])]);
    }

    public function storeItem(Request $request, AccountTemplate $accountTemplate)
    {
        TemplateManager::item($accountTemplate, $request->all());

        return redirect()->route('account-templates.show', $accountTemplate)->with('success', 'Template item created.');
    }

    public function editItem(AccountTemplate $accountTemplate, AccountTemplateItem $item)
    {
        Gate::authorize('manage', AccountTemplate::class);
        abort_unless($item->account_template_id === $accountTemplate->id, 404);
        TemplateManager::editable($accountTemplate);

        return view('account-templates.item', ['template' => $accountTemplate, 'item' => $item]);
    }

    public function updateItem(Request $request, AccountTemplate $accountTemplate, AccountTemplateItem $item)
    {
        TemplateManager::item($accountTemplate, $request->all(), $item);

        return redirect()->route('account-templates.show', $accountTemplate)->with('success', 'Template item saved.');
    }

    public function destroyItem(AccountTemplate $accountTemplate, AccountTemplateItem $item)
    {
        TemplateManager::removeItem($accountTemplate, $item);

        return redirect()->route('account-templates.show', $accountTemplate)->with('success', 'Unused template item removed.');
    }
}
