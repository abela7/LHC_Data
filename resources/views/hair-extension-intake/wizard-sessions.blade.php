@extends('layouts.app')

@section('title', 'Hair Extension Intake Sessions')
@section('section', 'Hair Extensions')
@section('heading', 'Intake Sessions')

@section('content')
    @php
        $statusLabels = [
            'draft' => 'Draft',
            'awaiting_match' => 'Submitted',
            'match_failed' => 'Needs check',
            'match_accepted' => 'Matched',
            'filling_variants' => 'Filling',
            'awaiting_review' => 'Reviewing',
            'review_returned' => 'Reviewed',
            'approved' => 'Approved',
        ];

        $groups = [
            [
                'key' => 'submitted',
                'title' => 'Submitted',
                'description' => 'Saved from phone and waiting for Codex/local catalogue matching.',
                'items' => $submitted,
                'count' => $counts['submitted'] ?? 0,
            ],
            [
                'key' => 'drafts',
                'title' => 'Draft sessions',
                'description' => 'Matched or in-progress sessions that can be continued.',
                'items' => $drafts,
                'count' => $counts['drafts'] ?? 0,
            ],
        ];
    @endphp

    <section class="hew-page hew-session-page">
        <div class="hew-session-hero">
            <div>
                <p class="hew-eyebrow">Shop-floor sessions</p>
                <h1>Manage intake work</h1>
                <p class="hew-muted">Keep the capture wizard clean. Use this page to find submitted products and continue draft sessions.</p>
            </div>
            <div class="hew-session-actions">
                <a class="hew-btn primary" href="{{ route('hair-extension-intake.wizard.index') }}">New intake</a>
            </div>
        </div>

        @if (session('status'))
            <div class="hew-session-alert">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="hew-session-alert danger">{{ session('error') }}</div>
        @endif

        <form class="hew-card hew-session-filter" method="GET" action="{{ route('hair-extension-intake.wizard.sessions') }}">
            <label class="hew-field">Search
                <input type="search" name="q" value="{{ $search }}" placeholder="Brand, style, or session ID">
            </label>
            <label class="hew-field">Brand
                <select name="brand_id">
                    <option value="">All brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected((string) $brandId === (string) $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="hew-session-filter-actions">
                <button class="hew-btn primary" type="submit">Filter</button>
                <a class="hew-btn" href="{{ route('hair-extension-intake.wizard.sessions') }}">Clear</a>
            </div>
        </form>

        <div class="hew-session-stats">
            <a href="#submitted" class="hew-session-stat">
                <span>Submitted</span>
                <strong>{{ number_format($counts['submitted'] ?? 0) }}</strong>
            </a>
            <a href="#drafts" class="hew-session-stat">
                <span>Draft sessions</span>
                <strong>{{ number_format($counts['drafts'] ?? 0) }}</strong>
            </a>
        </div>

        @foreach ($groups as $group)
            <section class="hew-card hew-session-group" id="{{ $group['key'] }}">
                <div class="hew-session-group-head">
                    <div>
                        <h2>{{ $group['title'] }}</h2>
                        <p class="hew-muted">{{ $group['description'] }}</p>
                    </div>
                    <span class="hew-badge">{{ number_format($group['count']) }}</span>
                </div>

                <div class="hew-session-grid">
                    @forelse ($group['items'] as $session)
                        @php
                            $status = $statusLabels[$session->status] ?? \Illuminate\Support\Str::headline($session->status);
                            $photoUrl = $session->getAttribute('photo_url');
                        @endphp
                        <article class="hew-session-card">
                            <a class="hew-session-thumb" href="{{ route('hair-extension-intake.wizard.show', $session) }}">
                                @if ($photoUrl)
                                    <img src="{{ $photoUrl }}" alt="">
                                @else
                                    <span>No photo</span>
                                @endif
                            </a>
                            <div class="hew-session-info">
                                <div class="hew-session-card-head">
                                    <span class="hew-badge">{{ $status }}</span>
                                    <span class="hew-session-step">Step {{ $session->current_step }}/7</span>
                                </div>
                                <h3>{{ $session->getAttribute('summary_name') }}</h3>
                                <p>{{ $session->brand?->name ?? 'Brand not set' }}</p>
                                <div class="hew-session-meta">
                                    <span>{{ $session->variants_count ?? 0 }} variants</span>
                                    <span>{{ $session->complete_variants_count ?? 0 }} complete</span>
                                    <span>{{ $session->updated_at?->diffForHumans() }}</span>
                                </div>
                                <div class="hew-session-card-actions">
                                    <a class="hew-btn full" href="{{ route('hair-extension-intake.wizard.show', $session) }}">Open</a>
                                    <form
                                        method="POST"
                                        action="{{ route('hair-extension-intake.wizard.destroy', $session) }}"
                                        onsubmit="return confirm('Delete this intake session? This removes the draft/submission and its uploaded photos.');"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button class="hew-btn danger full" type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="hew-session-empty">
                            <strong>No sessions found</strong>
                            <p class="hew-muted">Try clearing filters or start a new intake.</p>
                        </div>
                    @endforelse
                </div>

                @if ($group['items']->hasPages())
                    <div class="hew-session-pagination">
                        {{ $group['items']->links() }}
                    </div>
                @endif
            </section>
        @endforeach
    </section>
@endsection
