<?php

namespace App\Http\Controllers;

use App\Services\EmployeeInvitations;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function show(Request $request, int $invitation)
    {
        $token = $request->validate(['token' => 'required|string|size:64'])['token'];

        return response()->view('auth.invitation', compact('invitation', 'token'))
            ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, int $invitation)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'token' => 'required|string|size:64', 'password' => PasswordPolicy::rules()]);
        EmployeeInvitations::accept($invitation, $data['token'], $data['email'], $data['password']);

        return redirect()->route('login')->with('success', 'Invitation accepted. Sign in with your new password.');
    }
}
