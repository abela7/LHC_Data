@extends('layouts.app')

@section('title', $family->product_family_name)

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Family Review</p>
            <h2>{{ $family->product_family_name }}</h2>
            <p class="page-note">Family = the recognised product line. Type = optional subtype or line layer. Variant = the exact sellable child item.</p>
        </div>
        <div class="header-actions">
            <span class="pill">{{ $family->status }}</span>
            <span class="pill {{ $family->needs_source_verification ? 'pill-warning' : '' }}">
                {{ $family->needs_source_verification ? 'Needs source check' : 'Source reviewed' }}
            </span>
            @if ($family->duplicate_flag)
                <span class="pill pill-warning">Duplicate flagged</span>
            @endif
        </div>
    </section>

    <section class="stack-grid">
        <article class="card">
            <div class="card-head">
                <h3>Review actions</h3>
            </div>
            <div class="button-row">
                <form method="POST" action="{{ route('families.approve', $family) }}">
                    @csrf
                    <input type="hidden" name="review_note" value="Approved from family review page">
                    <button type="submit" class="button button-primary">Approve family</button>
                </form>

                <form method="POST" action="{{ route('families.reject', $family) }}">
                    @csrf
                    <input type="hidden" name="review_note" value="Rejected from family review page">
                    <button type="submit" class="button button-danger">Reject family</button>
                </form>
            </div>

            <form method="POST" action="{{ route('families.merge', $family) }}" class="inline-form">
                @csrf
                <label>
                    <span>Merge into</span>
                    <select name="target_family_id" required>
                        <option value="">Select target family</option>
                        @foreach ($mergeTargets as $target)
                            <option value="{{ $target->id }}">{{ $target->product_family_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grow">
                    <span>Merge note</span>
                    <input type="text" name="merge_note" placeholder="Optional merge note">
                </label>
                <button type="submit" class="button">Merge</button>
            </form>
        </article>

        @if ($duplicateCandidates->isNotEmpty())
            <article class="card">
                <div class="card-head">
                    <h3>Duplicate candidates</h3>
                    <p>Review these before approving. Merge stays manual in v1.</p>
                </div>
                <div class="stack-list">
                    @foreach ($duplicateCandidates as $candidate)
                        @php
                            $otherFamily = $candidate->left_family_id === $family->id ? $candidate->rightFamily : $candidate->leftFamily;
                        @endphp
                        <article class="compact-card stack-form">
                            <div class="form-grid">
                                <div>
                                    <p class="eyebrow">Possible duplicate</p>
                                    <p><strong>{{ $otherFamily?->product_family_name }}</strong></p>
                                    <p>{{ $otherFamily?->brand?->name ?? 'Unbranded' }}</p>
                                </div>
                                <div>
                                    <p class="eyebrow">Similarity</p>
                                    <p>{{ number_format((float) $candidate->similarity_score, 2) }}</p>
                                    <p>Status: {{ $candidate->status }}</p>
                                </div>
                            </div>

                            @if ($candidate->match_basis)
                                <div class="helper-block">
                                    <p class="helper-title">Match basis</p>
                                    <pre>{{ json_encode($candidate->match_basis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            @endif

                            <div class="button-row">
                                @if ($otherFamily)
                                    <a href="{{ route('families.show', $otherFamily) }}" class="button">Open candidate</a>
                                    <form method="POST" action="{{ route('families.merge', $family) }}">
                                        @csrf
                                        <input type="hidden" name="target_family_id" value="{{ $otherFamily->id }}">
                                        <input type="hidden" name="merge_note" value="Merged from duplicate candidate {{ $candidate->id }}">
                                        <button type="submit" class="button">Merge into candidate</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </article>
        @endif

        <article class="card">
            <div class="card-head">
                <h3>Family overview</h3>
            </div>
            <form method="POST" action="{{ route('families.update', $family) }}" class="stack-form">
                @csrf
                @method('PATCH')

                <div class="helper-block">
                    <p class="helper-title">Structure rule</p>
                    <p>Keep the main market-facing line here. Use types for subtype structure. Use variants only for exact sellable child items.</p>
                </div>

                <div class="form-grid">
                    <label>
                        <span>Brand</span>
                        <select name="brand_id">
                            <option value="">Unassigned</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}" @selected($family->brand_id === $brand->id)>{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Category</span>
                        <select name="category_id">
                            <option value="">Unassigned</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected($family->category_id === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Subcategory</span>
                        <select name="subcategory_id">
                            <option value="">Unassigned</option>
                            @foreach ($subcategories as $subcategory)
                                <option value="{{ $subcategory->id }}" @selected($family->subcategory_id === $subcategory->id)>{{ $subcategory->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Status</span>
                        <select name="status">
                            @foreach ($familyStatuses as $status)
                                <option value="{{ $status }}" @selected($family->status === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="form-grid">
                    <label class="grow">
                        <span>Product family name</span>
                        <input type="text" name="product_family_name" value="{{ old('product_family_name', $family->product_family_name) }}">
                    </label>

                    <label>
                        <span>Source confidence</span>
                        <input type="number" step="0.01" name="source_confidence" value="{{ old('source_confidence', $family->source_confidence) }}">
                    </label>
                </div>

                <label>
                    <span>Short description</span>
                    <textarea name="short_description" rows="3">{{ old('short_description', $family->short_description) }}</textarea>
                </label>

                <label>
                    <span>Full description</span>
                    <textarea name="full_description" rows="5">{{ old('full_description', $family->full_description) }}</textarea>
                </label>

                <label>
                    <span>Notes</span>
                    <textarea name="notes" rows="4">{{ old('notes', $family->notes) }}</textarea>
                </label>

                <div class="form-grid">
                    <label class="checkbox-row">
                        <input type="hidden" name="needs_source_verification" value="0">
                        <input type="checkbox" name="needs_source_verification" value="1" @checked($family->needs_source_verification)>
                        <span>Needs source verification</span>
                    </label>

                    <label>
                        <span>Family shop match</span>
                        <select name="shop_match_status">
                            <option value="">No change</option>
                            @foreach ($shopMatchStatuses as $status)
                                <option value="{{ $status }}" @selected(optional($family->shopMatch)->shop_match_status === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Match confidence</span>
                        <input type="number" step="0.01" name="shop_match_confidence" value="{{ old('shop_match_confidence', optional($family->shopMatch)->confidence) }}">
                    </label>

                    <label>
                        <span>Confirmation method</span>
                        <select name="confirmation_method">
                            <option value="">Unspecified</option>
                            @foreach ($confirmationMethods as $method)
                                <option value="{{ $method }}" @selected(optional($family->shopMatch)->confirmation_method === $method)>{{ $method }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="form-grid">
                    <label>
                        <span>Confirmed by</span>
                        <select name="confirmed_by">
                            <option value="">Unassigned</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected(optional($family->shopMatch)->confirmed_by === $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Confirmed at</span>
                        <input type="datetime-local" name="confirmed_at" value="{{ old('confirmed_at', optional(optional($family->shopMatch)->confirmed_at)->format('Y-m-d\TH:i')) }}">
                    </label>
                </div>

                <label>
                    <span>Shop match notes</span>
                    <textarea name="shop_match_notes" rows="3">{{ old('shop_match_notes', optional($family->shopMatch)->notes) }}</textarea>
                </label>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Save family</button>
                </div>
            </form>
        </article>

        <section class="split-grid">
            <article class="card">
                <div class="card-head">
                    <h3>Sources</h3>
                    <p>Keep official and trusted references attached directly to the family.</p>
                </div>

                <div class="stack-list">
                    @forelse ($family->sources as $source)
                        <article class="compact-card stack-form">
                            <form method="POST" action="{{ route('sources.update', $source) }}" class="stack-form">
                                @csrf
                                @method('PATCH')
                                <div class="form-grid">
                                    <label>
                                        <span>Role</span>
                                        <select name="role">
                                            @foreach ($sourceRoles as $role)
                                                <option value="{{ $role }}" @selected($source->role === $role)>{{ $role }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>
                                        <span>Type</span>
                                        <select name="source_type">
                                            @foreach ($sourceTypes as $type)
                                                <option value="{{ $type }}" @selected($source->source_type === $type)>{{ $type }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>
                                        <span>Trust status</span>
                                        <select name="trust_status">
                                            @foreach ($sourceTrustStatuses as $status)
                                                <option value="{{ $status }}" @selected($source->trust_status === $status)>{{ $status }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>
                                        <span>Confidence</span>
                                        <input type="number" step="0.01" name="confidence" value="{{ $source->confidence }}">
                                    </label>
                                </div>

                                <label>
                                    <span>URL</span>
                                    <input type="url" name="url" value="{{ $source->url }}">
                                </label>

                                <label>
                                    <span>Title</span>
                                    <input type="text" name="title" value="{{ $source->title }}">
                                </label>

                                <label>
                                    <span>Notes</span>
                                    <textarea name="notes" rows="2">{{ $source->notes }}</textarea>
                                </label>

                                <div class="form-grid">
                                    <label class="checkbox-row">
                                        <input type="hidden" name="is_primary" value="0">
                                        <input type="checkbox" name="is_primary" value="1" @checked($source->is_primary)>
                                        <span>Primary source</span>
                                    </label>
                                    <label class="checkbox-row">
                                        <input type="hidden" name="is_verified" value="0">
                                        <input type="checkbox" name="is_verified" value="1" @checked($source->is_verified)>
                                        <span>Verified</span>
                                    </label>
                                </div>

                                <button type="submit" class="button">Update source</button>
                            </form>

                            <form method="POST" action="{{ route('sources.destroy', $source) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="button button-danger">Remove source</button>
                            </form>
                        </article>
                    @empty
                        <p>No sources attached yet.</p>
                    @endforelse
                </div>

                <details class="details-block">
                    <summary>Add source</summary>
                    <form method="POST" action="{{ route('sources.store', $family) }}" class="stack-form details-content">
                        @csrf
                        <div class="form-grid">
                            <label>
                                <span>Role</span>
                                <select name="role">
                                    @foreach ($sourceRoles as $role)
                                        <option value="{{ $role }}">{{ $role }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Type</span>
                                <select name="source_type">
                                    @foreach ($sourceTypes as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Trust status</span>
                                <select name="trust_status">
                                    @foreach ($sourceTrustStatuses as $status)
                                        <option value="{{ $status }}">{{ $status }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Confidence</span>
                                <input type="number" step="0.01" name="confidence">
                            </label>
                        </div>

                        <label>
                            <span>URL</span>
                            <input type="url" name="url">
                        </label>

                        <label>
                            <span>Title</span>
                            <input type="text" name="title">
                        </label>

                        <label>
                            <span>Notes</span>
                            <textarea name="notes" rows="3"></textarea>
                        </label>

                        <div class="form-grid">
                            <label class="checkbox-row">
                                <input type="hidden" name="is_primary" value="0">
                                <input type="checkbox" name="is_primary" value="1">
                                <span>Primary source</span>
                            </label>
                            <label class="checkbox-row">
                                <input type="hidden" name="is_verified" value="0">
                                <input type="checkbox" name="is_verified" value="1">
                                <span>Verified</span>
                            </label>
                        </div>

                        <button type="submit" class="button button-primary">Add source</button>
                    </form>
                </details>
            </article>

            <article class="card">
                <div class="card-head">
                    <h3>Images and evidence</h3>
                    <p>Keep clean family imagery separate from shop evidence tied to import records.</p>
                </div>

                <details class="details-block">
                    <summary>Add family image</summary>
                    <form method="POST" action="{{ route('images.store') }}" enctype="multipart/form-data" class="stack-form details-content">
                        @csrf
                        <input type="hidden" name="target_type" value="family">
                        <input type="hidden" name="target_id" value="{{ $family->id }}">

                        <div class="form-grid">
                            <label>
                                <span>Image role</span>
                                <input type="text" name="image_role" value="primary_image">
                            </label>
                            <label class="checkbox-row">
                                <input type="hidden" name="is_primary" value="0">
                                <input type="checkbox" name="is_primary" value="1">
                                <span>Primary image</span>
                            </label>
                        </div>

                        <div class="form-grid">
                            <label>
                                <span>Local upload</span>
                                <input type="file" name="uploaded_image" accept="image/*">
                            </label>
                            <label class="grow">
                                <span>External image URL</span>
                                <input type="url" name="external_url">
                            </label>
                        </div>

                        <label>
                            <span>Notes</span>
                            <textarea name="notes" rows="2"></textarea>
                        </label>

                        <button type="submit" class="button button-primary">Add family image</button>
                    </form>
                </details>

                <div class="stack-list">
                    <div>
                        <p class="eyebrow">Family images</p>
                        @if ($family->images->isEmpty())
                            <p>No family images attached yet.</p>
                        @endif
                    </div>

                    <div class="image-grid">
                        @foreach ($family->images as $image)
                            <article class="image-card stack-form">
                                @if ($image->displayUrl())
                                    <img src="{{ $image->displayUrl() }}" alt="">
                                @endif

                                <form method="POST" action="{{ route('images.update', $image) }}" class="stack-form">
                                    @csrf
                                    @method('PATCH')
                                    <label>
                                        <span>Role</span>
                                        <input type="text" name="image_role" value="{{ $image->image_role }}">
                                    </label>
                                    <label>
                                        <span>External URL</span>
                                        <input type="url" name="external_url" value="{{ $image->external_url }}">
                                    </label>
                                    <label>
                                        <span>Notes</span>
                                        <textarea name="notes" rows="2">{{ $image->notes }}</textarea>
                                    </label>
                                    <label class="checkbox-row">
                                        <input type="hidden" name="is_primary" value="0">
                                        <input type="checkbox" name="is_primary" value="1" @checked($image->is_primary)>
                                        <span>Primary image</span>
                                    </label>
                                    <button type="submit" class="button">Update image</button>
                                </form>

                                <form method="POST" action="{{ route('images.destroy', $image) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="button button-danger">Remove image</button>
                                </form>
                            </article>
                        @endforeach
                    </div>

                    <div>
                        <p class="eyebrow">Import evidence</p>
                        @if ($family->importRecords->isEmpty())
                            <p>No import records linked to this family.</p>
                        @endif
                    </div>

                    @foreach ($family->importRecords as $record)
                        <details class="details-block">
                            <summary>Import record {{ $record->id }} evidence</summary>
                            <div class="details-content stack-form">
                                <div class="helper-block">
                                    <p class="helper-title">Import context</p>
                                    <p>Batch {{ $record->batch?->batch_uuid ?? 'Unknown batch' }} | Record status: {{ $record->status }}</p>
                                </div>

                                <form method="POST" action="{{ route('images.store') }}" enctype="multipart/form-data" class="stack-form">
                                    @csrf
                                    <input type="hidden" name="target_type" value="import_record">
                                    <input type="hidden" name="target_id" value="{{ $record->id }}">

                                    <div class="form-grid">
                                        <label>
                                            <span>Image role</span>
                                            <input type="text" name="image_role" value="shop_photo">
                                        </label>
                                        <label class="checkbox-row">
                                            <input type="hidden" name="is_primary" value="0">
                                            <input type="checkbox" name="is_primary" value="1">
                                            <span>Primary image</span>
                                        </label>
                                    </div>

                                    <div class="form-grid">
                                        <label>
                                            <span>Local upload</span>
                                            <input type="file" name="uploaded_image" accept="image/*">
                                        </label>
                                        <label class="grow">
                                            <span>External image URL</span>
                                            <input type="url" name="external_url">
                                        </label>
                                    </div>

                                    <label>
                                        <span>Notes</span>
                                        <textarea name="notes" rows="2"></textarea>
                                    </label>

                                    <button type="submit" class="button button-primary">Add import evidence</button>
                                </form>

                                @if ($record->images->isEmpty())
                                    <p>No evidence images attached to this import record.</p>
                                @endif

                                <div class="image-grid">
                                    @foreach ($record->images as $image)
                                        <article class="image-card stack-form">
                                            @if ($image->displayUrl())
                                                <img src="{{ $image->displayUrl() }}" alt="">
                                            @endif

                                            <form method="POST" action="{{ route('images.update', $image) }}" class="stack-form">
                                                @csrf
                                                @method('PATCH')
                                                <label>
                                                    <span>Role</span>
                                                    <input type="text" name="image_role" value="{{ $image->image_role }}">
                                                </label>
                                                <label>
                                                    <span>External URL</span>
                                                    <input type="url" name="external_url" value="{{ $image->external_url }}">
                                                </label>
                                                <label>
                                                    <span>Notes</span>
                                                    <textarea name="notes" rows="2">{{ $image->notes }}</textarea>
                                                </label>
                                                <label class="checkbox-row">
                                                    <input type="hidden" name="is_primary" value="0">
                                                    <input type="checkbox" name="is_primary" value="1" @checked($image->is_primary)>
                                                    <span>Primary image</span>
                                                </label>
                                                <button type="submit" class="button">Update image</button>
                                            </form>

                                            <form method="POST" action="{{ route('images.destroy', $image) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="button button-danger">Remove image</button>
                                            </form>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        </details>
                    @endforeach
                </div>
            </article>
        </section>

        <article class="card">
            <div class="card-head">
                <h3>Type layer</h3>
                <p>Use only for subtype or line structure such as Value Pack, Professional Pack, Water Wave, or Pre-Stretched.</p>
            </div>

            <details class="details-block">
                <summary>Add type</summary>
                <form method="POST" action="{{ route('types.store', $family) }}" class="stack-form details-content">
                    @csrf
                    <div class="form-grid">
                        <label>
                            <span>Name</span>
                            <input type="text" name="name">
                        </label>
                        <label>
                            <span>Status</span>
                            <select name="status">
                                @foreach ($typeStatuses as $status)
                                    <option value="{{ $status }}">{{ $status }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span>Sort order</span>
                            <input type="number" name="sort_order" value="0">
                        </label>
                    </div>

                    <label>
                        <span>Description</span>
                        <textarea name="description" rows="2"></textarea>
                    </label>

                    <label>
                        <span>Notes</span>
                        <textarea name="notes" rows="2"></textarea>
                    </label>

                    <div class="form-grid">
                        <label>
                            <span>Shop match</span>
                            <select name="shop_match_status">
                                <option value="">No change</option>
                                @foreach ($shopMatchStatuses as $status)
                                    <option value="{{ $status }}">{{ $status }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span>Match confidence</span>
                            <input type="number" step="0.01" name="shop_match_confidence">
                        </label>
                        <label>
                            <span>Method</span>
                            <select name="confirmation_method">
                                <option value="">Unspecified</option>
                                @foreach ($confirmationMethods as $method)
                                    <option value="{{ $method }}">{{ $method }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span>Confirmed by</span>
                            <select name="confirmed_by">
                                <option value="">Unassigned</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label>
                        <span>Confirmed at</span>
                        <input type="datetime-local" name="confirmed_at">
                    </label>

                    <label>
                        <span>Shop notes</span>
                        <textarea name="shop_match_notes" rows="2"></textarea>
                    </label>

                    <button type="submit" class="button button-primary">Add type</button>
                </form>
            </details>

            <div class="stack-list">
                @forelse ($family->types as $type)
                    <article class="compact-card stack-form">
                        <form method="POST" action="{{ route('types.update', $type) }}" class="stack-form">
                            @csrf
                            @method('PATCH')
                            <div class="form-grid">
                                <label>
                                    <span>Name</span>
                                    <input type="text" name="name" value="{{ $type->name }}">
                                </label>
                                <label>
                                    <span>Status</span>
                                    <select name="status">
                                        @foreach ($typeStatuses as $status)
                                            <option value="{{ $status }}" @selected($type->status === $status)>{{ $status }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>
                                    <span>Sort order</span>
                                    <input type="number" name="sort_order" value="{{ $type->sort_order }}">
                                </label>
                            </div>

                            <label>
                                <span>Description</span>
                                <textarea name="description" rows="2">{{ $type->description }}</textarea>
                            </label>

                            <label>
                                <span>Notes</span>
                                <textarea name="notes" rows="2">{{ $type->notes }}</textarea>
                            </label>

                            <div class="form-grid">
                                <label>
                                    <span>Shop match</span>
                                    <select name="shop_match_status">
                                        <option value="">No change</option>
                                        @foreach ($shopMatchStatuses as $status)
                                            <option value="{{ $status }}" @selected(optional($type->shopMatch)->shop_match_status === $status)>{{ $status }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>
                                    <span>Match confidence</span>
                                    <input type="number" step="0.01" name="shop_match_confidence" value="{{ optional($type->shopMatch)->confidence }}">
                                </label>
                                <label>
                                    <span>Method</span>
                                    <select name="confirmation_method">
                                        <option value="">Unspecified</option>
                                        @foreach ($confirmationMethods as $method)
                                            <option value="{{ $method }}" @selected(optional($type->shopMatch)->confirmation_method === $method)>{{ $method }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>
                                    <span>Confirmed by</span>
                                    <select name="confirmed_by">
                                        <option value="">Unassigned</option>
                                        @foreach ($users as $user)
                                            <option value="{{ $user->id }}" @selected(optional($type->shopMatch)->confirmed_by === $user->id)>{{ $user->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>

                            <label>
                                <span>Confirmed at</span>
                                <input type="datetime-local" name="confirmed_at" value="{{ optional(optional($type->shopMatch)->confirmed_at)->format('Y-m-d\TH:i') }}">
                            </label>

                            <label>
                                <span>Shop notes</span>
                                <textarea name="shop_match_notes" rows="2">{{ optional($type->shopMatch)->notes }}</textarea>
                            </label>

                            <button type="submit" class="button">Update type</button>
                        </form>

                        <form method="POST" action="{{ route('types.archive', $type) }}">
                            @csrf
                            <button type="submit" class="button button-danger">Archive type</button>
                        </form>
                    </article>
                @empty
                    <p>No type layer defined for this family.</p>
                @endforelse
            </div>
        </article>

        <article class="card">
            <div class="card-head">
                <h3>Variants</h3>
                <p>This is the exact sellable child layer. Use common fields for known attributes and <code>attributes_json</code> for category-specific data.</p>
            </div>

            <details class="details-block">
                <summary>Add variant</summary>
                <form method="POST" action="{{ route('variants.store', $family) }}" class="stack-form details-content">
                    @csrf
                    @include('families.variant-form-fields', ['variant' => null, 'family' => $family, 'variantStatuses' => $variantStatuses, 'shopMatchStatuses' => $shopMatchStatuses, 'confirmationMethods' => $confirmationMethods, 'users' => $users])
                    <button type="submit" class="button button-primary">Add variant</button>
                </form>
            </details>

            <div class="stack-list">
                @forelse ($family->variants as $variant)
                    <article class="compact-card stack-form">
                        <form method="POST" action="{{ route('variants.update', $variant) }}" class="stack-form">
                            @csrf
                            @method('PATCH')
                            @include('families.variant-form-fields', ['variant' => $variant, 'family' => $family, 'variantStatuses' => $variantStatuses, 'shopMatchStatuses' => $shopMatchStatuses, 'confirmationMethods' => $confirmationMethods, 'users' => $users])
                            <button type="submit" class="button">Update variant</button>
                        </form>

                        <div class="button-row">
                            <form method="POST" action="{{ route('variants.duplicate', $variant) }}">
                                @csrf
                                <button type="submit" class="button">Duplicate variant</button>
                            </form>
                            <form method="POST" action="{{ route('variants.archive', $variant) }}">
                                @csrf
                                <button type="submit" class="button button-danger">Archive variant</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <p>No variants staged yet.</p>
                @endforelse
            </div>
        </article>

        <section class="split-grid">
            <article class="card">
                <div class="card-head">
                    <h3>Import traceability</h3>
                    <p>Use this to trace the approved record back to raw external JSON and shop evidence.</p>
                </div>

                <div class="stack-list">
                    @forelse ($family->importRecords as $record)
                        <article class="compact-card stack-form">
                            <div class="form-grid">
                                <div>
                                    <p class="eyebrow">Batch</p>
                                    <p>{{ $record->batch?->batch_uuid ?? 'Unknown batch' }}</p>
                                    <p>Status: {{ $record->batch?->status ?? 'Unknown' }}</p>
                                </div>
                                <div>
                                    <p class="eyebrow">Import record</p>
                                    <p>#{{ $record->id }}</p>
                                    <p>{{ $record->external_reference ?? 'No external reference' }}</p>
                                </div>
                                <div>
                                    <p class="eyebrow">Parse status</p>
                                    <p>{{ $record->status }}</p>
                                    <p>Confidence: {{ $record->import_confidence ?? 'n/a' }}</p>
                                </div>
                                <div>
                                    <p class="eyebrow">Links</p>
                                    <p>{{ $record->links->count() }} linked records</p>
                                    <p>{{ $record->links->pluck('relation_role')->implode(', ') ?: 'No link roles' }}</p>
                                </div>
                            </div>

                            @if (! empty($record->parse_warnings))
                                <div class="helper-block">
                                    <p class="helper-title">Warnings</p>
                                    <ul>
                                        @foreach ($record->parse_warnings as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($record->import_notes)
                                <div class="helper-block">
                                    <p class="helper-title">Import notes</p>
                                    <p>{{ $record->import_notes }}</p>
                                </div>
                            @endif
                        </article>
                    @empty
                        <p>No import records linked to this family.</p>
                    @endforelse
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h3>Review history</h3>
                </div>
                <ul class="stack-list">
                    @forelse ($family->reviewActions->sortByDesc('created_at') as $action)
                        <li class="compact-card">
                            <p><strong>{{ $action->action }}</strong></p>
                            <p>{{ $action->from_status ?: 'n/a' }} -> {{ $action->to_status ?: 'n/a' }}</p>
                            <p>{{ $action->notes ?: 'No note recorded.' }}</p>
                            <p>{{ $action->actedBy?->name ?? 'System' }} | {{ $action->created_at?->format('Y-m-d H:i') }}</p>
                        </li>
                    @empty
                        <li>No review actions logged yet.</li>
                    @endforelse
                </ul>
            </article>
        </section>

        <article class="card">
            <div class="card-head">
                <h3>Raw imported payloads</h3>
            </div>
            @forelse ($family->importRecords as $record)
                <details class="details-block">
                    <summary>Import record {{ $record->id }} ({{ $record->status }})</summary>
                    <div class="details-content stack-form">
                        <p>Batch {{ $record->batch?->batch_uuid ?? 'Unknown batch' }}</p>
                        <pre>{{ $record->raw_json }}</pre>
                    </div>
                </details>
            @empty
                <p>No raw payloads stored for this family.</p>
            @endforelse
        </article>
    </section>
@endsection
