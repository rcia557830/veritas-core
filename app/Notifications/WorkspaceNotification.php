<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class WorkspaceNotification extends Notification
{
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
