<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountTemplate;
use App\Models\Client;
use App\Services\Audit;
use App\Support\AccountCode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ChartOfAccounts
{
    public const CLASSIFICATIONS = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];

    public static function definition(array $input): array
    {
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'classification' => ['required', Rule::in(self::CLASSIFICATIONS)],
        ])->validate();
        $data['code'] = AccountCode::display($data['code']);

        return $data;
    }

    public static function save(Client $client, array $input, ?Account $account = null): Account
    {
        Gate::authorize($account ? 'update' : 'create', $account ?? [Account::class, $client]);

        return AccountingTransaction::forClient($client->id, function () use ($client, $input, $account) {
            $stored = $account ? $client->accounts()->findOrFail($account->id) : new Account(['client_id' => $client->id]);
            $data = self::definition($input);
            $duplicate = $client->accounts()->where('code_key', AccountCode::key($data['code']));
            if ($stored->exists) {
                $duplicate->whereKeyNot($stored->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['code' => 'This client already has an account with this code.']);
            }
            $before = $stored->only(['code', 'name', 'classification']);
            $stored->fill($data)->save();
            Audit::record($account ? 'updated' : 'created', 'accounts', $stored,
                ($account ? 'Account updated from '.json_encode($before).' to ' : 'Account created: ').json_encode($data));

            return $stored;
        });
    }

    public static function status(Account $account, bool $active): void
    {
        Gate::authorize('status', $account);
        AccountingTransaction::forClient($account->client_id, function () use ($account, $active) {
            $stored = $account->fresh();
            if ($stored->is_active !== $active) {
                $stored->update(['is_active' => $active]);
                Audit::record($active ? 'activated' : 'deactivated', 'accounts', $stored, 'Account '.$stored->code.($active ? ' activated.' : ' deactivated.'));
            }
        });
    }

    public static function initialize(Client $client, AccountTemplate $template): array
    {
        Gate::authorize('initialize', [Account::class, $client]);

        // Always lock the client before the template. Every supported chart write
        // shares the client lock; template edits share the template lock.
        return AccountingTransaction::forClient($client->id, fn () => TemplateTransaction::run($template->id, function ($locked) use ($client) {
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['template_id' => 'Choose an active template.']);
            }
            $items = $locked->items()->where('is_active', true)->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['template_id' => 'The template has no active account items.']);
            }
            $created = 0;
            $existing = 0;
            foreach ($items as $item) {
                // Provenance, not the current display code, makes retries idempotent
                // even after an independent client account has been edited.
                if ($client->accounts()->where('account_template_item_id', $item->id)->exists()) {
                    $existing++;

                    continue;
                }
                if ($client->accounts()->where('code_key', $item->code_key)->exists()) {
                    throw ValidationException::withMessages(['template_id' => 'Account code '.$item->code.' already exists independently or from another version. No accounts were copied.']);
                }
                $account = $client->accounts()->create($item->only(['code', 'name', 'classification']) + ['account_template_item_id' => $item->id, 'is_active' => true]);
                Audit::record('initialized', 'accounts', $account, 'Copied from template '.$locked->name.' v'.$locked->version.': '.json_encode($item->only(['code', 'name', 'classification'])));
                $created++;
            }

            return compact('created', 'existing');
        }));
    }
}
