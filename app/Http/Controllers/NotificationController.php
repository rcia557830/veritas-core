<?php

namespace App\Http\Controllers;

use App\Services\Notify;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        Notify::due($request->user());
        $items = [];
        $unread = 0;
        // Authorization is rechecked when assignment changes after notification creation.
        foreach ($request->user()->notifications()->latest()->limit(100)->get() as $notification) {
            $data = $notification->data;
            $module = $data['module'] ?? '';
            if (! isset(Modules::all()[$module])) {
                continue;
            }
            $model = Modules::get($module)['model'];
            $record = $model::find($data['record_id']);
            if (! $record || ! Gate::allows('view', $record) || ! Notify::isCurrent($record, $data)) {
                continue;
            }
            if (! $notification->read_at) {
                $unread++;
            }
            $items[] = ['id' => $notification->id, 'title' => $data['title'], 'client' => $data['client'], 'url' => route($module.'.show', $record), 'read' => (bool) $notification->read_at];
        }

        return response()->json(compact('items', 'unread'));
    }

    public function read(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function all(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
