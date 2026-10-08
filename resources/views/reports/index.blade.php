@extends('layouts.app')
@section('title','Reports')
@section('content')<div class="toolbar"><div class="category-chips mb-0">@foreach(\App\Support\Modules::all() as $key=>$item)@can('viewAny',$item['model'])<a class="chip {{ $key===$module?'active':'' }}" href="{{ route('reports.index',['module'=>$key]) }}">{{ $item['title'] }}</a>@endcan
@endforeach</div><div class="actions">@can('report.export')<a class="btn btn-primary" href="{{ route('reports.csv',request()->query()+['module'=>$module]) }}">Export CSV</a>@endcan
@can('report.print')<button class="btn btn-outline-secondary" data-print>Print page</button>@endcan</div></div>@include('records.filters')<div class="row g-3 mb-4">@foreach($summary as $label=>$value)<x-stat :label="ucfirst($label)" :value="in_array($label,['billed','collected','outstanding','overdue','Total debit','Total credit'])?\App\Support\Money::format($value):$value"/>@endforeach</div>@include('records.table')@endsection
