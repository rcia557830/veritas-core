<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BookkeepingPolicy extends RecordPolicy
{
    protected string $permission = 'bookkeeping';

    public function generalLedger(User $user): bool
    {
        return $this->viewAny($user) && $user->hasAnyRole(['owner', 'office-manager', 'bookkeeper'])
            && $user->hasPermission('account.view') && $user->hasPermission('client.view');
    }

    public function financialReports(User $user): bool
    {
        return $this->generalLedger($user) && $user->hasPermission('report.view') && $user->hasPermission('report.generate');
    }

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && ! $record->is_legacy && in_array($record->status, ['Draft', 'Needs Correction']) && ($user->hasRole('owner') || $record->created_by === $user->id);
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

    public function post(User $user, LedgerEntry $record): bool
    {
        return $this->view($user, $record) && $user->hasAnyRole(['owner', 'office-manager'])
            && $user->hasPermission('bookkeeping.post') && $record->created_by !== $user->id;
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record) && ! $record->is_legacy && ! $record->voucher()->exists() && $user->hasPermission('bookkeeping.delete') && $record->status === 'Draft' && ($user->hasRole('owner') || $record->created_by === $user->id);
    }
}
