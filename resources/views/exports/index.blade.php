@extends('layouts.app')

@section('title', 'Exports')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Exports</p>
            <h2>Export downloads</h2>
            <p class="page-note">Use the AI research handoff now. The approved catalogue export only becomes usable after families are built and approved.</p>
        </div>
    </section>

    <article class="card">
        <div class="card-head">
            <div>
                <h3>AI research handoff</h3>
                <p>{{ number_format($aiInputCount) }} grouped products ready for external AI review</p>
            </div>
        </div>
        <form method="GET" action="{{ route('exports.index') }}" class="stack-form">
            <div class="form-grid">
                <label>
                    <span>Picture from</span>
                    <input type="text" name="picture_from" value="{{ $filters['picture_from'] }}" placeholder="381 or picture381">
                </label>

                <label>
                    <span>Picture to</span>
                    <input type="text" name="picture_to" value="{{ $filters['picture_to'] }}" placeholder="459 or picture459">
                </label>
            </div>

            <div class="button-row">
                <button type="submit" class="button button-primary">Apply range</button>
                @if ($filters['picture_from'] !== '' || $filters['picture_to'] !== '')
                    <a href="{{ route('exports.index') }}" class="button">Clear range</a>
                @endif
            </div>
        </form>

        <div class="helper-block">
            <p class="helper-title">Current export scope</p>
            <p>
                {{ number_format($aiInputStats['raw_rows']) }} raw rows from
                {{ number_format($aiInputStats['pictures']) }} pictures become
                {{ number_format($aiInputStats['grouped_products']) }} grouped products.
            </p>
            @if ($filters['picture_from'] !== '' || $filters['picture_to'] !== '')
                <p>Range: <code>{{ $filters['picture_from'] !== '' ? $filters['picture_from'] : 'start' }}</code> to <code>{{ $filters['picture_to'] !== '' ? $filters['picture_to'] : 'end' }}</code>.</p>
            @endif
        </div>
        <div class="helper-block">
            <p class="helper-title">Use this now</p>
            <p>This file contains the current product list: <code>product_id</code>, <code>category</code>, <code>name</code>, and <code>brand</code>.</p>
        </div>
        <div class="button-row">
            <a href="{{ route('exports.catalogue-ai-input.xlsx', array_filter($filters)) }}" class="button button-primary">Download XLSX</a>
            <a href="{{ route('exports.catalogue-ai-input.csv', array_filter($filters)) }}" class="button">Download CSV</a>
        </div>
    </article>

    <article class="card">
        <div class="card-head">
            <div>
                <h3>Approved catalogue export</h3>
                <p>{{ number_format($approvedCount) }} approved families ready for export</p>
            </div>
        </div>
        @if ($approvedCount > 0)
            <div class="button-row">
                <a href="{{ route('exports.approved.json') }}" class="button button-primary">Download JSON</a>
                <a href="{{ route('exports.approved.csv') }}" class="button">Download CSV</a>
            </div>
        @else
            <div class="helper-block">
                <p class="helper-title">Not ready yet</p>
                <p>You have no approved catalogue families yet. Build and approve families first, then use this export for POS or inventory mapping.</p>
            </div>
            <div class="button-row">
                <span class="button button-disabled">Download JSON</span>
                <span class="button button-disabled">Download CSV</span>
            </div>
        @endif
    </article>
@endsection
