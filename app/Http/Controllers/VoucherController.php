<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\Voucher;
use App\Services\Access;
use App\Services\Accounting\VoucherWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', LedgerEntry::class);
        $filters = $request->validate(['client_id' => 'nullable|integer|min:1', 'type' => ['nullable', Rule::in(array_keys(Voucher::TYPES))],
            'status' => ['nullable', Rule::in(['Draft', 'For Review', 'Reviewed', 'Needs Correction', 'Posted'])], 'per_page' => ['nullable', Rule::in([10, 25, 50, 100])]]);
        if (! empty($filters['client_id'])) {
            Access::client((int) $filters['client_id']);
        }
        $records = Access::query(LedgerEntry::class)->with(['client', 'voucher'])->whereHas('voucher', fn ($q) => $q->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type)))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $clients = Access::query(Client::class)->orderBy('business_name')->get();

        return view('vouchers.index', compact('records', 'clients', 'filters'));
    }

    public function create(Request $request)
    {
        Gate::authorize('create', LedgerEntry::class);
        $data = $request->validate(['type' => ['nullable', Rule::in(array_keys(Voucher::TYPES))], 'client_id' => 'nullable|integer|min:1']);
        $voucher = (new Voucher)->forceFill(['type' => $data['type'] ?? 'JV']);

        return $this->form($request, $voucher, new LedgerEntry);
    }

    public function edit(Request $request, Voucher $voucher)
    {
        Gate::authorize('update', $voucher->journal);

        return $this->form($request, $voucher, $voucher->journal);
    }

    private function form(Request $request, Voucher $voucher, LedgerEntry $record)
    {
        Gate::authorize('viewAny', Account::class);
        $clients = Access::query(Client::class)->orderBy('business_name')->get();
        $clientId = old('client_id', $record->client_id ?? $request->input('client_id'));
        $options = ['accounts' => [], 'periods' => [], 'documents' => []];
        if (is_scalar($clientId) && $clientId) {
            $options = (new LedgerController)->choices(Access::client((int) $clientId));
        }
        $evidence = $record->exists ? $record->evidence()->whereNull('detached_at')->get()->filter(fn ($e) => $e->document && Gate::allows('view', $e->document)) : collect();

        return view('vouchers.form', compact('voucher', 'record', 'clients', 'options', 'evidence'));
    }

    public function store(Request $request)
    {
        $voucher = VoucherWriter::save($request->all());

        return redirect()->route('vouchers.show', $voucher)->with('success', 'Voucher saved with its journal.');
    }

    public function update(Request $request, Voucher $voucher)
    {
        VoucherWriter::save($request->all(), $voucher);

        return redirect()->route('vouchers.show', $voucher)->with('success', 'Voucher and journal updated.');
    }

    public function show(Voucher $voucher)
    {
        Gate::authorize('view', $voucher->journal);

        return view('vouchers.show', ['voucher' => $voucher, 'record' => $voucher->journal]);
    }

    public function print(Voucher $voucher)
    {
        Gate::authorize('view', $voucher->journal);
        Gate::authorize('report.print');

        return view('vouchers.print', ['voucher' => $voucher, 'record' => $voucher->journal]);
    }
}
