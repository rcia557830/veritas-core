<?php

namespace App\Http\Controllers;

use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SecurityLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('security.view');
        $events = DB::table('security_events')->orderByDesc('id')->paginate(Records::pageSize($request));

        return view('admin.security', compact('events'));
    }
}
