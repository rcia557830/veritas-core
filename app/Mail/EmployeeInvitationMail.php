<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class EmployeeInvitationMail extends Mailable
{
    public function __construct(public string $acceptUrl, public string $roleName, public int $hours) {}

    public function build(): static
    {
        return $this->subject('Your Veritas Core invitation')->view('emails.employee-invitation');
    }
}
