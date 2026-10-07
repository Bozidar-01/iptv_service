@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        <span>Showing {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }}</span>
        <div class="pages">
            @if ($paginator->onFirstPage())
                <span class="page disabled">‹</span>
            @else
                <a class="page" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">‹</a>
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
                <a class="page" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">›</a>
            @else
                <span class="page disabled">›</span>
            @endif
        </div>
    </nav>
@elseif ($paginator->total() > 0)
    <nav class="pagination"><span>{{ number_format($paginator->total()) }} total</span></nav>
@endif
