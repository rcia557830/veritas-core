<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\KnowledgeArticle;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordPolicy
{
    protected string $permission;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->permission.'.view');
    }

    public function view(User $user, Model $record): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }
        if ($user->hasAnyRole(['owner', 'office-manager'])) {
            return true;
        }
        if (! $user->hasRole('bookkeeper')) {
            return false;
        }
        if ($record instanceof KnowledgeArticle) {
            return $record->status === 'Published' || ($record->author_id === $user->id && $record->status === 'Draft');
        }
        if ($record instanceof Notice) {
            return false;
        }
        $client = $record instanceof Client ? $record : $record->client;

        return $client && $client->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission($this->permission.'.create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission($this->permission.'.update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission($this->permission.'.archive');
    }
}
