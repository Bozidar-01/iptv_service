@if ($paginator->hasPages())
    <nav class="pagination" aria-label="{{ __('Page navigation') }}">
        <span>{{ __('Showing :from–:to of :total', ['from' => number_format($paginator->firstItem()), 'to' => number_format($paginator->lastItem()), 'total' => number_format($paginator->total())]) }}</span>
        <div class="pages">
            @if ($paginator->onFirstPage())
                <span class="page disabled">‹</span>
            @else
                <a class="page" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous') }}">‹</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page disabled">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="page" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="page" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next') }}">›</a>
            @else
                <span class="page disabled">›</span>
            @endif
        </div>
    </nav>
@elseif ($paginator->total() > 0)
    <nav class="pagination"><span>{{ __(':total total', ['total' => number_format($paginator->total())]) }}</span></nav>
@endif
