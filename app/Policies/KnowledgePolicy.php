<?php

namespace App\Policies;

use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class KnowledgePolicy extends RecordPolicy
{
    protected string $permission = 'knowledge';

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && (! $user->hasRole('bookkeeper') || ($record->author_id === $user->id && $record->status === 'Draft'));
    }

    public function publish(User $user, KnowledgeArticle $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('knowledge.publish');
    }
}
