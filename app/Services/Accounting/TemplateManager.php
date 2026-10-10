<?php

namespace App\Services\Accounting;

use App\Models\AccountTemplate;
use App\Models\AccountTemplateItem;
use App\Services\Audit;
use App\Support\AccountCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TemplateManager
{
    public static function save(array $input, ?AccountTemplate $template = null): AccountTemplate
    {
        Gate::authorize('manage', AccountTemplate::class);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:255'], 'version' => ['required', 'integer', 'min:1', 'max:2147483647'], 'is_active' => ['required', 'boolean']])->validate();

        return self::unique(function () use ($template, $data) {
            $write = function ($stored) use ($data) {
                $stored->fill($data)->save();
                Audit::record('saved', 'account-templates', $stored, 'Template '.$stored->name.' v'.$stored->version.' saved ('.($stored->is_active ? 'active' : 'inactive').').');

                return $stored;
            };

            return $template ? TemplateTransaction::run($template->id, $write) : DB::transaction(fn () => $write(new AccountTemplate));
        });
    }

    public static function item(AccountTemplate $template, array $input, ?AccountTemplateItem $item = null): AccountTemplateItem
    {
        Gate::authorize('manage', AccountTemplate::class);

        return TemplateTransaction::run($template->id, function ($locked) use ($input, $item) {
            self::editable($locked);
            $stored = $item ? $locked->items()->findOrFail($item->id) : $locked->items()->make();
            $data = ChartOfAccounts::definition($input) + Validator::make($input, ['is_active' => ['required', 'boolean']])->validate();
            $duplicate = $locked->items()->where('code_key', AccountCode::key($data['code']));
            if ($stored->exists) {
                $duplicate->whereKeyNot($stored->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['code' => 'This template version already contains this code.']);
            }
            $stored->fill($data)->save();
            Audit::record('item-saved', 'account-templates', $locked, 'Template item saved: '.json_encode($data));

            return $stored;
        });
    }

    public static function removeItem(AccountTemplate $template, AccountTemplateItem $item): void
    {
        Gate::authorize('manage', AccountTemplate::class);
        TemplateTransaction::run($template->id, function ($locked) use ($item) {
            self::editable($locked);
            $stored = $locked->items()->findOrFail($item->id);
            $stored->delete();
            Audit::record('item-removed', 'account-templates', $locked, 'Removed template item '.$stored->code.'.');
        });
    }

    public static function version(AccountTemplate $template): AccountTemplate
    {
        Gate::authorize('manage', AccountTemplate::class);

        return self::unique(fn () => TemplateTransaction::run($template->id, function ($locked) {
            $next = (int) AccountTemplate::where('name', $locked->name)->max('version') + 1;
            if ($next > 2147483647) {
                throw ValidationException::withMessages(['version' => 'Template version limit reached.']);
            }
            $copy = AccountTemplate::create(['name' => $locked->name, 'version' => $next, 'is_active' => false]);
            foreach ($locked->items()->orderBy('id')->get() as $item) {
                $copy->items()->create($item->only(['code', 'name', 'classification', 'is_active']));
            }
            Audit::record('version-created', 'account-templates', $copy, 'Copied template version '.$locked->version.' into inactive version '.$next.'.');

            return $copy;
        }));
    }

    public static function editable(AccountTemplate $template): void
    {
        if ($template->items()->whereHas('accounts')->exists()) {
            throw ValidationException::withMessages(['template' => 'This version has initialized client accounts. Create a new version to change its definitions.']);
        }
    }

    private static function unique(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['version' => 'This template name and version already exist. Refresh and try again.']);
        }
    }
}
