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
