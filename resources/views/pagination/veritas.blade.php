@if($paginator->hasPages())
<nav aria-label="Results pagination"><ul class="pagination">
@if($paginator->onFirstPage())<li class="page-item disabled"><span class="page-link" aria-disabled="true">Previous</span></li>@else<li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a></li>@endif
@foreach($elements as $element)
@if(is_string($element))<li class="page-item disabled"><span class="page-link" aria-disabled="true">{{ $element }}</span></li>@endif
@if(is_array($element))@foreach($element as $page=>$url)
@if($page==$paginator->currentPage())<li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>@else<li class="page-item"><a class="page-link" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a></li>@endif
@endforeach
@endif
@endforeach
@if($paginator->hasMorePages())<li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a></li>@else<li class="page-item disabled"><span class="page-link" aria-disabled="true">Next</span></li>@endif
</ul></nav>
@endif
