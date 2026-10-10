@extends('layouts.app')
@section('title','Account Templates')
@section('content')
<div class="toolbar"><p class="toolbar-copy">Shared definitions copied into independent client accounts. Used definitions are preserved by version.</p><a class="btn btn-primary" href="{{ route('account-templates.create') }}">Create template</a></div>
<section class="panel table-panel"><div class="table-responsive"><table class="table"><thead><tr><th>Template</th><th>Version</th><th>Items</th><th>Status</th></tr></thead><tbody>@forelse($templates as $template)<tr><td><a href="{{ route('account-templates.show',$template) }}">{{ $template->name }}</a></td><td>{{ $template->version }}</td><td>{{ $template->items_count }}</td><td><x-badge :status="$template->is_active?'Active':'Inactive'"/></td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h2>No templates yet</h2><p>Create a template using approved account definitions. No firm account data is preloaded.</p></div></td></tr>@endforelse</tbody></table></div></section><x-pagination :records="$templates"/>
@endsection
