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
