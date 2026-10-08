<?php

namespace App\Policies;

use App\Models\Notice;
use App\Models\User;

class NoticePolicy extends RecordPolicy
{
    protected string $permission = 'notice';

    public function publish(User $user, Notice $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('notice.publish');
    }
}
