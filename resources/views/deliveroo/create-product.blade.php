@extends('layouts.app')

@section('title', __('deliveroo.manual_product.title'))
@section('section', 'Deliveroo')
@section('heading', __('deliveroo.manual_product.heading'))

@section('content')
    @php
        $selectedBrandMode = old('brand_mode', ($prefillBrandSlug ? 'existing' : 'existing'));
        $selectedBrandSlug = old('brand_slug', $prefillBrandSlug);
        $selectedBrandNewLabel = old('brand_new_label', '');
        $selectedBrandNewCategory = old('brand_new_category', 'Other');
        $selectedFamilyExisting = old('family_existing', $prefillFamilyName ?? '');
        $selectedFamilyMode = old('family_link', ($selectedFamilyExisting !== '' ? 'existing' : 'none'));
    @endphp
    <section
        class="deliveroo-manual-form-page"
        data-deliveroo-manual-form
        data-families-url="{{ route('deliveroo-products.families') }}"
        data-old-brand-mode="{{ $selectedBrandMode }}"
        data-old-brand-new-label="{{ $selectedBrandNewLabel }}"
        data-old-brand-new-category="{{ $selectedBrandNewCategory }}"
        data-old-family-existing="{{ $selectedFamilyExisting }}"
    >
        <nav class="deliveroo-product-breadcrumbs" aria-label="Breadcrumb">
            <a href="{{ route('deliveroo-products.index') }}">{{ __('deliveroo.manual_product.back_to_index') }}</a>
            <span class="deliveroo-product-breadcrumbs-sep" aria-hidden="true">/</span>
            <span class="deliveroo-product-breadcrumbs-current">{{ __('deliveroo.manual_product.heading') }}</span>
        </nav>

        <div class="deliveroo-manual-form-shell">
            <header class="deliveroo-manual-form-head">
                <p class="eyebrow">Deliveroo</p>
                <h2>{{ __('deliveroo.manual_product.heading') }}</h2>
                <p class="page-note">{{ __('deliveroo.manual_product.intro') }}</p>
            </header>

            <form method="POST" action="{{ route('deliveroo-products.store') }}" class="deliveroo-manual-form">
                @csrf

                <fieldset class="deliveroo-manual-family-fieldset">
                    <legend class="deliveroo-manual-legend">{{ __('deliveroo.manual_product.brand_label') }}</legend>
                    <div class="deliveroo-manual-radio-row">
                        <label class="deliveroo-manual-radio">
                            <input type="radio" name="brand_mode" value="existing" @checked($selectedBrandMode === 'existing')>
                            Existing brand
                        </label>
                        <label class="deliveroo-manual-radio">
                            <input type="radio" name="brand_mode" value="new" @checked($selectedBrandMode === 'new')>
                            New brand
                        </label>
                    </div>

                    <div data-deliveroo-brand-existing-panel>
                        <label class="deliveroo-field deliveroo-field--full">
                            <span>{{ __('deliveroo.manual_product.brand_label') }}</span>
                            <select name="brand_slug" id="deliveroo-manual-brand">
                                <option value="">{{ __('deliveroo.manual_product.brand_placeholder') }}</option>
                                @foreach ($brands as $b)
                                    <option value="{{ $b['slug'] }}" @selected($selectedBrandSlug === $b['slug'])>{{ $b['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div data-deliveroo-brand-new-panel hidden>
                        <label class="deliveroo-field deliveroo-field--full">
                            <span>New brand name</span>
                            <input type="text" name="brand_new_label" value="{{ $selectedBrandNewLabel }}" maxlength="255" placeholder="e.g. Xtreme Gel">
                        </label>

                        <label class="deliveroo-field deliveroo-field--full">
                            <span>Brand category</span>
                            <select name="brand_new_category">
                                @foreach ($brandCategories as $category)
                                    <option value="{{ $category }}" @selected($selectedBrandNewCategory === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </fieldset>

                <label class="deliveroo-field deliveroo-field--full">
                    <span>{{ __('deliveroo.manual_product.official_name_label') }}</span>
                    <input type="text" name="official_name" value="{{ old('official_name') }}" required maxlength="255" placeholder="{{ __('deliveroo.manual_product.official_name_placeholder') }}">
                </label>

                <label class="deliveroo-field deliveroo-field--full">
                    <span>{{ __('deliveroo.manual_product.variant_label') }}</span>
                    <input type="text" name="variant_name" value="{{ old('variant_name') }}" maxlength="255" placeholder="{{ __('deliveroo.manual_product.variant_placeholder') }}">
                </label>

                <label class="deliveroo-field">
                    <span>Price</span>
                    <input type="number" name="price" value="{{ old('price') }}" min="0" max="999999.99" step="0.01" placeholder="0.00">
                </label>

                <fieldset class="deliveroo-manual-family-fieldset">
                    <legend class="deliveroo-manual-legend">{{ __('deliveroo.manual_product.family_link_label') }}</legend>
                    <div class="deliveroo-manual-radio-row">
                        <label class="deliveroo-manual-radio">
                            <input type="radio" name="family_link" value="none" @checked($selectedFamilyMode === 'none')>
                            {{ __('deliveroo.manual_product.family_link_none') }}
                        </label>
                        <label class="deliveroo-manual-radio">
                            <input type="radio" name="family_link" value="existing" @checked($selectedFamilyMode === 'existing')>
                            {{ __('deliveroo.manual_product.family_link_existing') }}
                        </label>
                        <label class="deliveroo-manual-radio">
                            <input type="radio" name="family_link" value="new" @checked($selectedFamilyMode === 'new')>
                            {{ __('deliveroo.manual_product.family_link_new') }}
                        </label>
                    </div>

                    <div class="deliveroo-manual-family-existing" data-deliveroo-family-existing-panel hidden>
                        <label class="deliveroo-field deliveroo-field--full">
                            <span>{{ __('deliveroo.manual_product.family_existing_label') }}</span>
                            <select name="family_existing" id="deliveroo-manual-family-select" data-placeholder="{{ __('deliveroo.manual_product.family_existing_placeholder') }}">
                                <option value="">{{ __('deliveroo.manual_product.family_existing_placeholder') }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="deliveroo-manual-family-new" data-deliveroo-family-new-panel hidden>
                        <label class="deliveroo-field deliveroo-field--full">
                            <span>{{ __('deliveroo.manual_product.family_new_label') }}</span>
                            <input type="text" name="family_new" value="{{ old('family_new') }}" maxlength="255" placeholder="{{ __('deliveroo.manual_product.family_new_placeholder') }}">
                        </label>
                    </div>
                </fieldset>

                <label class="deliveroo-field deliveroo-field--full">
                    <span>{{ __('deliveroo.manual_product.description_label') }}</span>
                    <textarea name="description" rows="6" placeholder="{{ __('deliveroo.manual_product.description_placeholder') }}">{{ old('description') }}</textarea>
                </label>

                <label class="deliveroo-field deliveroo-field--full">
                    <span>{{ __('deliveroo.manual_product.official_url_label') }}</span>
                    <input type="text" name="official_url" value="{{ old('official_url') }}" maxlength="255" placeholder="https://… (optional)">
                    <span class="deliveroo-manual-hint">{{ __('deliveroo.manual_product.official_url_help') }}</span>
                </label>

                @include('deliveroo.partials.image-url-rows', [
                    'initialImageUrls' => [],
                    'imageUploadUrl' => null,
                ])

                <div class="deliveroo-manual-actions">
                    <a href="{{ route('deliveroo-products.index') }}" class="button">{{ __('deliveroo.manual_product.cancel') }}</a>
                    <button type="submit" class="button button-primary">{{ __('deliveroo.manual_product.submit') }}</button>
                </div>
            </form>
        </div>
    </section>
@endsection
