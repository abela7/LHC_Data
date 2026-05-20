<div class="rfm-display-name-overlay"
     data-rfm-display-name-modal
     hidden
     aria-hidden="true">
    <button type="button" class="rfm-display-name-backdrop" data-rfm-display-name-close aria-label="{{ __('retail.family.display_name.cancel') }}"></button>
    <section class="rfm-display-name-dialog" role="dialog" aria-modal="true" aria-labelledby="rfm-display-name-title">
        <header class="rfm-display-name-head">
            <div>
                <p class="rfm-eyebrow">{{ __('retail.family.display_name.edit') }}</p>
                <h2 id="rfm-display-name-title">{{ __('retail.family.display_name.label') }}</h2>
            </div>
            <button type="button" class="rfm-display-name-close" data-rfm-display-name-close aria-label="{{ __('retail.family.display_name.cancel') }}">×</button>
        </header>

        <form class="rfm-display-name-form" data-rfm-display-name-form novalidate>
            <label class="rfm-display-name-field">
                <span>{{ __('retail.family.display_name.label') }}</span>
                <input type="text"
                       name="display_name"
                       data-rfm-display-name-input
                       value="{{ $family->display_family_name }}"
                       maxlength="255"
                       required
                       autocomplete="off"
                       placeholder="{{ __('retail.family.display_name.placeholder') }}">
            </label>

            <label class="rfm-display-name-universal">
                <input type="checkbox" name="apply_to_matching_sellables" value="1" data-rfm-display-name-universal>
                <span>
                    <strong>{{ __('retail.family.display_name.universal_label') }}</strong>
                    <small data-rfm-display-name-universal-hint>
                        @if ($familySharedDetails['retail_price'])
                            {{ __('retail.family.display_name.universal_hint_price', ['price' => number_format((float) $familySharedDetails['retail_price'], 2)]) }}
                        @else
                            {{ __('retail.family.display_name.universal_hint_all') }}
                        @endif
                    </small>
                </span>
            </label>

            <footer class="rfm-display-name-actions">
                <button type="button" class="rfm-display-name-cancel" data-rfm-display-name-close>{{ __('retail.family.display_name.cancel') }}</button>
                <button type="submit" class="rfm-display-name-save" data-rfm-display-name-save>{{ __('retail.family.display_name.save') }}</button>
            </footer>
        </form>
    </section>
</div>
