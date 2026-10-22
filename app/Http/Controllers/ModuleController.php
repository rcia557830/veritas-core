<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Records;
use App\Services\RecordWriter;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

abstract class ModuleController extends Controller
{
    protected string $module;

    protected function context(): array
    {
        return ['module' => $this->module, 'config' => Modules::get($this->module)];
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Modules::get($this->module)['model']);
        $records = Records::query($this->module, $request)->paginate(Records::pageSize())->withQueryString();

        return view('records.index', $this->context() + compact('records') + ['clients' => Access::query(Client::class)->orderBy('business_name')->limit(500)->get()]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Modules::get($this->module)['model']);
        $model = Modules::get($this->module)['model'];

        return $this->form($request, new $model);
    }

    public function edit(Request $request, $record)
    {
        Gate::authorize('update', $record);

        return $this->form($request, $record);
    }

    protected function form(Request $request, $record)
    {
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();
        $users = User::where('status', 'Active')->whereHas('role', fn ($q) => $q->whereIn('slug', ['owner', 'office-manager', 'bookkeeper']))->orderBy('name')->get();

        $context = $this->context();
        if ($this->module === 'compliance' && $request->user()->hasRole('bookkeeper')) {
            $context['config']['fields'] = array_intersect_key($context['config']['fields'], array_flip(['status', 'notes']));
            $context['config']['fields']['status'][1] = ['Pending', 'In Preparation', 'Awaiting Client Documents', 'Ready for Filing'];
        }
        if ($this->module === 'clients' && ! $request->user()->hasPermission('client.archive')) {
            unset($context['config']['fields']['status']);
        }
        if ($this->module === 'knowledge') {
            if (! $record->exists) {
                $requestedType = $request->query('content_type');
                if (in_array($requestedType, ['article', 'procedure', 'template', 'guideline'], true)) {
                    $record->content_type = $requestedType;
                } else {
                    $record->content_type = 'article';
                }
            }
            if (! $request->user()->hasPermission('knowledge.publish')) {
                $context['config']['fields']['status'][1] = ['Draft'];
            }
        }
        if ($this->module === 'documents') {
            $context['config']['fields']['status'][1] = array_values(array_filter($context['config']['fields']['status'][1], function ($status) use ($request, $record) {
                if ($status === 'Submitted' || $status === $record->status) {
                    return true;
                }
                $permission = match ($status) {
                    'Approved' => 'document.approve','Rejected' => 'document.reject',default => 'document.validate'
                };

                return $request->user()->hasPermission($permission);
            }));
        }

        return view($request->ajax() ? 'records.form-content' : 'records.form', $context + compact('record', 'clients', 'users'));
    }

    public function store(RecordRequest $request, RecordWriter $writer)
    {
        $record = $writer->save($this->module, $request);
        $label = $this->module === 'knowledge' ? ucfirst($record->content_type ?? 'article') : ucfirst(Modules::get($this->module)['singular']);

        return redirect()->route($this->module.'.show', $record)->with('success', $label.' created successfully.');
    }

    public function update(RecordRequest $request, $record, RecordWriter $writer)
    {
        Gate::authorize('update', $record);
        $writer->save($this->module, $request, $record);

        return redirect()->route($this->module.'.show', $record)->with('success', 'Record updated successfully.');
    }

    public function show($record)
    {
        Gate::authorize('view', $record);

        return view('records.show', $this->context() + compact('record'));
    }

    public function destroy($record)
    {
        Gate::authorize('delete', $record);
        DB::transaction(function () use ($record) {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            Gate::authorize('delete', $record);
            Audit::record('archived', $this->module, $record, 'Record removed from active records.');
            $record->delete();
        });

        return redirect()->route($this->module.'.index')->with('success', 'Record archived successfully.');
    }
}
