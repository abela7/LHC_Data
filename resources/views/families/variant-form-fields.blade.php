<div class="form-grid">
    <label class="grow">
        <span>Display name</span>
        <input type="text" name="variant_display_name" value="{{ old('variant_display_name', $variant?->variant_display_name) }}">
    </label>
    <label>
        <span>Type</span>
        <select name="catalogue_type_id">
            <option value="">Directly under family</option>
            @foreach ($family->types as $type)
                <option value="{{ $type->id }}" @selected(($variant?->catalogue_type_id ?? null) === $type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
    </label>
    <label>
        <span>Status</span>
        <select name="status">
            @foreach ($variantStatuses as $status)
                <option value="{{ $status }}" @selected(($variant?->status ?? 'draft') === $status)>{{ $status }}</option>
            @endforeach
        </select>
    </label>
</div>

<div class="form-grid">
    <label><span>Color code</span><input type="text" name="color_code" value="{{ old('color_code', $variant?->color_code) }}"></label>
    <label><span>Color name</span><input type="text" name="color_name" value="{{ old('color_name', $variant?->color_name) }}"></label>
    <label><span>Size</span><input type="text" name="size" value="{{ old('size', $variant?->size) }}"></label>
    <label><span>Length</span><input type="text" name="length" value="{{ old('length', $variant?->length) }}"></label>
</div>

<div class="form-grid">
    <label><span>Bundle count</span><input type="number" name="bundle_count" value="{{ old('bundle_count', $variant?->bundle_count) }}"></label>
    <label><span>Pack size</span><input type="text" name="pack_size" value="{{ old('pack_size', $variant?->pack_size) }}"></label>
    <label><span>Texture</span><input type="text" name="texture" value="{{ old('texture', $variant?->texture) }}"></label>
    <label><span>Shade</span><input type="text" name="shade" value="{{ old('shade', $variant?->shade) }}"></label>
</div>

<div class="form-grid">
    <label><span>Finish</span><input type="text" name="finish" value="{{ old('finish', $variant?->finish) }}"></label>
    <label><span>Style</span><input type="text" name="style" value="{{ old('style', $variant?->style) }}"></label>
    <label><span>Weight</span><input type="text" name="weight" value="{{ old('weight', $variant?->weight) }}"></label>
    <label><span>Volume</span><input type="text" name="volume" value="{{ old('volume', $variant?->volume) }}"></label>
</div>

<label>
    <span>Attributes JSON</span>
    <textarea name="attributes_json" rows="3">{{ old('attributes_json', $variant ? json_encode($variant->attributes_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '{}') }}</textarea>
</label>

<label>
    <span>Notes</span>
    <textarea name="notes" rows="2">{{ old('notes', $variant?->notes) }}</textarea>
</label>

<div class="form-grid">
    <label>
        <span>Shop match</span>
        <select name="shop_match_status">
            <option value="">No change</option>
            @foreach ($shopMatchStatuses as $status)
                <option value="{{ $status }}" @selected(optional($variant?->shopMatch)->shop_match_status === $status)>{{ $status }}</option>
            @endforeach
        </select>
    </label>
    <label><span>Match confidence</span><input type="number" step="0.01" name="shop_match_confidence" value="{{ old('shop_match_confidence', optional($variant?->shopMatch)->confidence) }}"></label>
    <label>
        <span>Method</span>
        <select name="confirmation_method">
            <option value="">Unspecified</option>
            @foreach ($confirmationMethods as $method)
                <option value="{{ $method }}" @selected(optional($variant?->shopMatch)->confirmation_method === $method)>{{ $method }}</option>
            @endforeach
        </select>
    </label>
    <label>
        <span>Confirmed by</span>
        <select name="confirmed_by">
            <option value="">Unassigned</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected(optional($variant?->shopMatch)->confirmed_by === $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
    </label>
</div>

<label>
    <span>Confirmed at</span>
    <input type="datetime-local" name="confirmed_at" value="{{ old('confirmed_at', optional(optional($variant?->shopMatch)->confirmed_at)->format('Y-m-d\TH:i')) }}">
</label>

<label>
    <span>Shop notes</span>
    <textarea name="shop_match_notes" rows="2">{{ old('shop_match_notes', optional($variant?->shopMatch)->notes) }}</textarea>
</label>
