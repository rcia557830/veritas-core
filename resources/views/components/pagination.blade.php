@props(['records'])
<div class="record-pagination" aria-label="Record counts and pages">
<p class="pagination-summary" role="status">Showing <strong>{{ $records->firstItem()??0 }}</strong> to <strong>{{ $records->lastItem()??0 }}</strong> of <strong>{{ $records->total() }}</strong> records</p>
{{ $records->onEachSide(1)->links('pagination.veritas') }}
</div>
