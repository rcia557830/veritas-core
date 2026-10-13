<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\RoleDashboard;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke()
    {
        if (auth()->user()->hasRole('super-admin')) {
            $cards = [];
            if (Gate::allows('viewAny', User::class)) {
                foreach (['Active' => 'Active employees', 'Pending Invitation' => 'Pending invitations', 'Inactive' => 'Inactive accounts'] as $status => $label) {
                    $cards[] = ['label' => $label, 'value' => User::where('status', $status)->count(), 'url' => route('admin.users.index', ['status' => $status]), 'note' => 'Employee access'];
                }
                $cards[] = ['label' => 'Roles and Permissions', 'value' => Role::count(), 'url' => route('admin.roles.index'), 'note' => 'Review access boundaries'];
            }

            return view('admin.overview', compact('cards'));
        }

        return view('dashboard.index', RoleDashboard::business());
    }
}
