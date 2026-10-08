<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Notice;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class NoticeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Notice::class);
        $request->validate(['q' => 'nullable|string|max:150', 'status' => 'nullable|in:Draft,Published,Archived']);
        $notices = Access::query(Notice::class)->with('client')->when(Records::searchText($request) !== '', fn ($q) => $q->where('title', 'like', '%'.Records::searchText($request).'%'))->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))->latest()->paginate(Records::pageSize($request))->withQueryString();

        return view('notices.index', compact('notices'));
    }

    public function create()
    {
        Gate::authorize('create', Notice::class);

        return $this->form(new Notice);
    }

    public function edit(Notice $notice)
    {
        Gate::authorize('update', $notice);

        return $this->form($notice);
    }

    private function form(Notice $notice)
    {
        return view('notices.form', ['notice' => $notice, 'clients' => Access::query(Client::class)->orderBy('business_name')->limit(500)->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Notice::class);

        return $this->save($request, new Notice);
    }

    public function update(Request $request, Notice $notice)
    {
        Gate::authorize('update', $notice);

        return $this->save($request, $notice);
    }

    private function save(Request $request, Notice $notice)
    {
        abort_if($request->hasAny(['status', 'created_by', 'published_at']), 403, 'Use the publish/archive action.');
        $data = $request->validate(['title' => 'required|string|max:255', 'body' => 'required|string|max:30000', 'client_id' => 'nullable|integer|exists:clients,id']);
        if (! empty($data['client_id'])) {
            Access::client($data['client_id']);
        }
        $notice = DB::transaction(function () use ($notice, $data) {
            if ($notice->exists) {
                $notice = Notice::lockForUpdate()->findOrFail($notice->id);
                Gate::authorize('update', $notice);
            } else {
                Gate::authorize('create', Notice::class);
                $notice->created_by = auth()->id();
            }
            // Changes to a posted notice become a draft until explicitly published again.
            $notice->fill($data);
            $notice->status = 'Draft';
            $notice->published_at = null;
            $notice->save();
            Audit::record('notice.saved', 'notices', $notice, 'Notice saved as draft: '.$notice->title.'.');

            return $notice;
        });

        return redirect()->route('notices.show', $notice)->with('success', 'Notice draft saved.');
    }

    public function show(Notice $notice)
    {
        Gate::authorize('view', $notice);

        return view('notices.show', compact('notice'));
    }

    public function publish(Notice $notice)
    {
        Gate::authorize('publish', $notice);
        DB::transaction(function () use ($notice) {
            $notice = Notice::lockForUpdate()->findOrFail($notice->id);
            Gate::authorize('publish', $notice);
            abort_unless($notice->status === 'Draft', 409);
            $notice->update(['status' => 'Published', 'published_at' => now()]);
            Audit::record('notice.published', 'notices', $notice, 'Notice posted in the internal workspace: '.$notice->title.'.');
        });

        return back()->with('success', 'Notice published in the internal workspace.');
    }

    public function archive(Notice $notice)
    {
        Gate::authorize('delete', $notice);
        DB::transaction(function () use ($notice) {
            $notice = Notice::lockForUpdate()->findOrFail($notice->id);
            Gate::authorize('delete', $notice);
            $notice->update(['status' => 'Archived']);
            Audit::record('notice.archived', 'notices', $notice, 'Notice archived.');
        });

        return back()->with('success', 'Notice archived.');
    }
}
