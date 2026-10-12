<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Password;

class SecurityMail
{
    public static function assertDeliveryTransport(?string $mailer = null, array $visited = []): void
    {
        $mailer ??= config('mail.default');
        if (in_array($mailer, $visited, true)) {
            throw new \RuntimeException('Invalid mail transport configuration.');
        }
        $config = config('mail.mailers.'.$mailer, []);
        $transport = $config['transport'] ?? '';
        if ($transport === 'log' || ($transport === 'array' && ! app()->environment('testing'))) {
            throw new \RuntimeException('Configure a delivery transport for security email.');
        }
        foreach ($config['mailers'] ?? [] as $child) {
            static::assertDeliveryTransport($child, [...$visited, $mailer]);
        }
    }

    public static function resetLink(User $user): bool
    {
        try {
            static::assertDeliveryTransport();
            Password::sendResetLink(['email' => $user->email]);
            AccountSecurity::record('password-link.requested', $user);

            return true;
        } catch (\Throwable $exception) {
            AccountSecurity::record('password-link.delivery-failed', $user);

            return false;
        }
    }
}
