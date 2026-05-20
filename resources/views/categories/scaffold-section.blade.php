@extends('layouts.app')

@section('title', $groupMeta['label'])

@section('content')
    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Scaffold', 'url' => route('categories.scaffold')],
            ['label' => $groupMeta['label'], 'current' => true],
        ],
    ])

    <div class="scaffold-page-head">
        <div>
            <p class="eyebrow">{{ $groupMeta['label'] }}</p>
            <h2 class="page-title">{{ number_format($roots->count()) }} roots &middot; {{ number_format($roots->sum('nodes_count')) }} nodes</h2>
        </div>
    </div>

    {{-- Quick-add root --}}
    <form method="POST" action="{{ route('categories.scaffold.roots.store') }}" class="scaffold-quick-add">
        @csrf
        <input type="hidden" name="group_key" value="{{ $group }}">
        <input type="text" name="name" placeholder="New root name..." required class="scaffold-quick-input">
        <input type="number" name="sort_order" value="0" min="0" class="scaffold-quick-sort" placeholder="#" title="Sort order">
        <button type="submit" class="scaffold-quick-btn">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Add root
        </button>
    </form>

    {{-- Root list --}}
    @if ($roots->isEmpty())
        <div class="scaffold-empty">
            <p>No roots yet. Add one above to get started.</p>
        </div>
    @else
        <div class="scaffold-root-list">
            @foreach ($roots as $root)
                <a href="{{ route('categories.scaffold.roots.show', ['root' => $root]) }}" class="scaffold-root-row">
                    <div class="scaffold-root-row-left">
                        <span class="scaffold-root-order">{{ $root->sort_order }}</span>
                        <div>
                            <h3 class="scaffold-root-name">{{ $root->name }}</h3>
                            <p class="scaffold-root-meta">{{ number_format($root->nodes_count) }} child nodes</p>
                        </div>
                    </div>
                    <svg class="scaffold-section-arrow" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @endif
@endsection
