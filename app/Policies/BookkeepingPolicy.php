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
