@php
    $items = $items ?? [];
    $count = count($items);
    $parent = $count >= 2 ? $items[$count - 2] : null;
    $backUrl = $backUrl ?? ($parent['url'] ?? null);
    $backLabel = $backLabel ?? ($parent['label'] ?? null);
@endphp
<nav class="bc-breadcrumb scaffold-breadcrumb" aria-label="Breadcrumb">
    @if ($backUrl && $backLabel)
        <a href="{{ $backUrl }}" class="bc-breadcrumb-back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/>
            </svg>
            <span>{{ $backLabel }}</span>
        </a>
    @endif
    <div class="bc-breadcrumb-scroll">
        <ol class="bc-breadcrumb-trail">
            @foreach ($items as $crumb)
                <li @class([
                    'bc-breadcrumb-item',
                    'bc-breadcrumb-item--current' => ! empty($crumb['current']),
                    'bc-breadcrumb-item--middle' => $loop->index > 0 && $loop->index < $count - 2,
                ])>
                    @if (! empty($crumb['current']))
                        <span title="{{ $crumb['label'] }}">{{ $crumb['label'] }}</span>
                    @else
                        <a href="{{ $crumb['url'] }}" title="{{ $crumb['label'] }}">{{ $crumb['label'] }}</a>
                    @endif
                </li>
                @if ($loop->first && $count > 3)
                    <li class="bc-breadcrumb-ellipsis" aria-hidden="true"><span>…</span></li>
                @endif
            @endforeach
        </ol>
    </div>
</nav>
