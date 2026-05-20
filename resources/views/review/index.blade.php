@extends('layouts.app')

@section('title', 'Review Queue')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Review Queue</p>
            <h2>Families and imports needing attention</h2>
        </div>
    </section>

    <section class="stack-grid">
        <article class="card">
            <div class="card-head">
                <h3>Pending families</h3>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Family</th>
                            <th>Brand</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Source check</th>
                            <th>Shop match</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($families as $family)
                            <tr>
                                <td><a href="{{ route('families.show', $family) }}">{{ $family->product_family_name }}</a></td>
                                <td>{{ $family->brand?->name ?? 'Unassigned' }}</td>
                                <td>{{ $family->category?->name ?? 'Unassigned' }}</td>
                                <td><span class="pill">{{ $family->status }}</span></td>
                                <td>{{ $family->needs_source_verification ? 'Needs check' : 'Okay' }}</td>
                                <td>{{ $family->shopMatch?->shop_match_status ?? 'unknown' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">No families are waiting in the review queue.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrap">
                {{ $families->links() }}
            </div>
        </article>

        <section class="split-grid">
            <article class="card">
                <div class="card-head">
                    <h3>Imports with warnings</h3>
                </div>
                <ul class="stack-list">
                    @forelse ($warningImports as $import)
                        <li>
                            <strong>{{ $import->targetFamily?->product_family_name ?? 'Unlinked import' }}</strong>
                            <p>{{ implode(' | ', $import->parse_warnings ?? []) }}</p>
                        </li>
                    @empty
                        <li>No warning imports right now.</li>
                    @endforelse
                </ul>
            </article>

            <article class="card">
                <div class="card-head">
                    <h3>Duplicate candidates</h3>
                </div>
                <ul class="stack-list">
                    @forelse ($duplicateCandidates as $candidate)
                        <li>
                            <strong>{{ $candidate->leftFamily->product_family_name }}</strong>
                            <span>vs</span>
                            <strong>{{ $candidate->rightFamily->product_family_name }}</strong>
                            <p>{{ $candidate->similarity_score }} similarity</p>
                        </li>
                    @empty
                        <li>No open duplicate candidates.</li>
                    @endforelse
                </ul>
            </article>
        </section>

        <article class="card">
            <div class="card-head">
                <div>
                    <h3>Brand cleanup</h3>
                    <p>Delete wrong or duplicate brand records from here. Linked families will stay in place and become unassigned.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Brand</th>
                            <th>Linked families</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($brands as $brand)
                            <tr>
                                <td>{{ $brand->name }}</td>
                                <td>{{ $brand->families_count }}</td>
                                <td>{{ $brand->is_active ? 'active' : 'inactive' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('brands.destroy', $brand) }}" onsubmit="return confirm('Delete brand {{ addslashes($brand->name) }}? Linked families will remain and become unassigned.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button button-danger">Delete brand</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">No brands available for cleanup.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>
@endsection
