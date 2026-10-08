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
