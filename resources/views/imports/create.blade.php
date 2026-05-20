@extends('layouts.app')

@section('title', 'Import JSON')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">External JSON Intake</p>
            <h2>Import AI-generated draft JSON</h2>
            <p class="page-note">AI stays outside this application. This page only accepts pasted or uploaded JSON and stages it for human review.</p>
        </div>
    </section>

    <article class="card">
        @if (session('payload_cleanup_notes'))
            <div class="helper-block">
                <p class="helper-title">Auto-cleaned JSON preview</p>
                <p>The payload was cleaned before decoding. Review the notes below and the updated JSON payload field before retrying.</p>
                <ul>
                    @foreach (session('payload_cleanup_notes', []) as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="stack-form">
            @csrf

            <div class="form-grid">
                <label>
                    <span>Source label</span>
                    <input type="text" name="source_label" id="source_label" value="{{ old('source_label') }}" placeholder="Auto-filled from picture_id when available">
                    <small>For Vision scan JSON, this will auto-fill from <code>picture_id</code> if you leave it blank.</small>
                </label>

                <label>
                    <span>JSON file</span>
                    <input type="file" name="json_file" accept=".json,.txt,application/json,text/plain">
                </label>
            </div>

            <label>
                <span>JSON payload</span>
                <textarea name="json_payload" id="json_payload" rows="18" placeholder='Paste one draft object, an array of draft objects, or { "items": [...] }'>{{ old('json_payload') }}</textarea>
            </label>

            @if (session('cleaned_json_preview'))
                <details class="details-block" open>
                    <summary>Cleaned preview</summary>
                    <div class="details-content">
                        <pre>{{ session('cleaned_json_preview') }}</pre>
                    </div>
                </details>
            @endif

            <div class="form-grid">
                <label>
                    <span>Shop photos</span>
                    <input type="file" name="shop_photos[]" accept="image/*" multiple>
                    <small>Use this for single-record imports where the uploaded photos clearly belong to one product family.</small>
                </label>

                <label>
                    <span>Import notes</span>
                    <textarea name="notes" rows="6" placeholder="Optional import notes or context">{{ old('notes') }}</textarea>
                </label>
            </div>

            <div class="helper-block">
                <p class="helper-title">Supported payloads</p>
                <ul>
                    <li>One single draft object</li>
                    <li>An array of draft objects</li>
                    <li>A wrapper object containing `items`</li>
                    <li>A simple Vision scan object with `picture_id` and `products`</li>
                    <li>A wrapper object containing `photos` with one or more Vision scan results</li>
                </ul>
            </div>

            <details class="details-block">
                <summary>Simple Vision scan example</summary>
                <div class="details-content">
<pre>{
  "picture_id": "picture001",
  "products": [
    {
      "brand": "X-Pression",
      "product_name": "Ultra Braid Stretched"
    }
  ]
}</pre>
                </div>
            </details>

            <div class="button-row">
                <button type="submit" class="button button-primary">Stage import</button>
                <a href="{{ route('review.index') }}" class="button">Go to review queue</a>
            </div>
        </form>
    </article>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sourceLabel = document.getElementById('source_label');
            const jsonPayload = document.getElementById('json_payload');

            if (! sourceLabel || ! jsonPayload) {
                return;
            }

            const inferSourceLabel = (value) => {
                if (! value.trim()) {
                    return null;
                }

                try {
                    const parsed = JSON.parse(value);
                    const collectPictureIds = (input) => {
                        if (! input || typeof input !== 'object') {
                            return [];
                        }

                        if (! Array.isArray(input) && 'products' in input && ('picture_id' in input || 'photo_id' in input)) {
                            const pictureId = input.picture_id ?? input.photo_id ?? null;
                            return pictureId ? [String(pictureId).trim()].filter(Boolean) : [];
                        }

                        if (! Array.isArray(input) && Array.isArray(input.photos)) {
                            return [...new Set(input.photos.flatMap(collectPictureIds))];
                        }

                        if (! Array.isArray(input) && Array.isArray(input.items)) {
                            return [...new Set(input.items.flatMap(collectPictureIds))];
                        }

                        if (Array.isArray(input)) {
                            return [...new Set(input.flatMap(collectPictureIds))];
                        }

                        return [];
                    };

                    const pictureIds = collectPictureIds(parsed);

                    if (pictureIds.length === 0) {
                        return null;
                    }

                    if (pictureIds.length === 1) {
                        return pictureIds[0];
                    }

                    return `${pictureIds[0]} + ${pictureIds.length - 1} more`;
                } catch (error) {
                    return null;
                }
            };

            const maybeAutofill = () => {
                const current = sourceLabel.value.trim();

                if (current !== '' && sourceLabel.dataset.autofilled !== 'true') {
                    return;
                }

                const inferred = inferSourceLabel(jsonPayload.value);

                if (inferred) {
                    sourceLabel.value = inferred;
                    sourceLabel.dataset.autofilled = 'true';
                } else if (sourceLabel.dataset.autofilled === 'true') {
                    sourceLabel.value = '';
                    delete sourceLabel.dataset.autofilled;
                }
            };

            sourceLabel.addEventListener('input', () => {
                if (sourceLabel.value.trim() === '') {
                    sourceLabel.dataset.autofilled = 'true';
                    maybeAutofill();
                } else {
                    delete sourceLabel.dataset.autofilled;
                }
            });

            jsonPayload.addEventListener('input', maybeAutofill);
            maybeAutofill();
        });
    </script>
@endsection
