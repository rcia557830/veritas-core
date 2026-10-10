<?php

namespace App\Http\Requests;

use App\Services\RecordInput;
use App\Support\Modules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('record');
        $model = Modules::get($this->module())['model'];
        Gate::authorize($record ? 'update' : 'create', $record ?? $model);
        RecordInput::authorize($this->module(), $this->user(), $this->all(), $record);

        return true;
    }

    public function module(): string
    {
        return explode('.', $this->route()->getName())[0];
    }

    protected function getRedirectUrl()
    {
        $record = $this->route('record');

        return $record ? route($this->module().'.edit', $record) : route($this->module().'.create');
    }

    public function rules(): array
    {
        $module = $this->module();
        if ($module === 'compliance' && $this->user()->hasRole('bookkeeper')) {
            return ['status' => 'required|in:Pending,In Preparation,Awaiting Client Documents,Ready for Filing', 'notes' => 'nullable|string|max:30000'];
        }
        $config = Modules::get($module);
        $rules = [];
        foreach ($config['fields'] as $name => $field) {
            $rule = [($field[2] ?? false) ? 'required' : 'nullable'];
            if (is_array($field[1])) {
                $rule[] = Rule::in($field[1]);
            } elseif ($field[1] === 'date') {
                $rule[] = 'date_format:Y-m-d';
            } elseif ($field[1] === 'number') {
                $rule = array_merge($rule, ['numeric', 'min:0', 'max:999999999.99', 'decimal:0,2']);
            } else {
                $rule = array_merge($rule, ['string', 'max:'.($field[1] === 'textarea' ? 30000 : 255)]);
            }
            $rules[$name] = $rule;
        }
        if (! in_array($module, ['clients', 'knowledge'])) {
            $rules['client_id'] = ['required', 'integer', 'exists:clients,id'];
        }
        if ($module === 'clients') {
            $rules['email'] = ['nullable', 'email', 'max:255'];
            $rules['phone'] = ['nullable', 'string', 'max:60', 'regex:/^[0-9+()\-. ]{7,20}$/'];
            $rules['tin'] = ['nullable', 'string', 'max:30', 'regex:/^\d{3}[ -]?\d{3}[ -]?\d{3}([ -]?\d{0,3})?$/'];
            if ($this->user()->hasPermission('client.assign')) {
                $rules['assigned_to'] = ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'Active')];
            }
        }
        if ($module === 'documents') {
            $rules['file'] = ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:20480'];
        }
        if ($module === 'compliance') {
            $rules['assigned_to'] = ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'Active')];
            $rules['filed_date'] = ['nullable', 'required_if:status,Filed', 'date_format:Y-m-d', 'before_or_equal:today'];
            $rules['reference_number'] = ['nullable', 'required_if:status,Filed', 'string', 'max:255'];
            $rules['submission_deadline'] = ['nullable', 'date_format:Y-m-d'];
            $rules['submission_deadline_override_reason'] = ['nullable', 'string', 'max:30000'];
        }
        if (in_array($module, ['ledger', 'billing'])) {
            $rules['items'] = ['required', 'array', 'min:'.($module === 'ledger' ? 2 : 1), 'max:100'];
            if ($module === 'ledger') {
                $rules['items'][] = 'list';
                $rules['items.*'] = ['required', 'array:id,account_id,debit,credit'];
                $rules['items.*.id'] = ['nullable', 'integer', 'min:1', 'distinct'];
                $rules['items.*.account_id'] = ['required', 'integer', 'min:1'];
                $rules['accounting_period_id'] = ['nullable', 'integer', 'min:1'];
                $rules['document_ids'] = ['sometimes', 'array', 'list', 'max:100'];
                $rules['document_ids.*'] = ['required', 'integer', 'min:1', 'distinct'];
                $rules['refresh_document_ids'] = ['sometimes', 'array', 'list', 'max:100'];
                $rules['refresh_document_ids.*'] = ['required', 'integer', 'min:1', 'distinct'];
                foreach (['debit', 'credit'] as $key) {
                    $rules['items.*.'.$key] = ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'];
                }
            } else {
                $rules['due_date'] = ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'];
                $rules['items.*.description'] = ['required', 'string', 'max:255'];
                $rules['items.*.quantity'] = ['required', 'numeric', 'min:0.01', 'max:100000', 'decimal:0,2'];
                $rules['items.*.unit_price'] = ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'];
            }
        }

        if ($module === 'clients' && ! $this->user()->hasPermission('client.archive')) {
            unset($rules['status']);
        }

        return $rules;
    }
}
