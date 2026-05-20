@extends('layouts.app')

@section('title', 'Photo Processing')
@section('section', 'Settings')
@section('heading', 'Photo Processing')

@section('content')
    <section class="pp-page">
        @php($testResult = session('photo_processing_test'))

        <header class="pp-hero">
            <div>
                <p class="pp-eyebrow">AI product photos</p>
                <h1>Background removal and white product photos</h1>
                <p>Control the local AI step that turns shop camera photos into clean product images before the watermark is applied.</p>
            </div>
            <span class="pp-status {{ $availability['available'] ? 'is-on' : 'is-off' }}">
                {{ $availability['available'] ? 'AI Ready' : 'AI Not Installed' }}
            </span>
        </header>

        <div class="pp-grid">
            <form method="POST" action="{{ route('settings.photo-processing.update') }}" class="pp-card">
                @csrf
                @method('PATCH')

                <div class="pp-card-head">
                    <h2>Processing workflow</h2>
                    <p>When enabled, mobile capture photos are saved as original first, then processed to a white-background final image, then watermarked.</p>
                </div>

                <label class="pp-toggle">
                    <input type="checkbox" name="remove_background_enabled" value="1" @checked(old('remove_background_enabled', $settings->remove_background_enabled))>
                    <span>
                        <strong>Remove background from new product photos</strong>
                        <small>Uses local AI on this PC. If AI fails, the app keeps the original and records the error.</small>
                    </span>
                </label>

                <label class="pp-toggle">
                    <input type="checkbox" name="apply_to_mobile_capture" value="1" @checked(old('apply_to_mobile_capture', $settings->apply_to_mobile_capture))>
                    <span>
                        <strong>Apply to mobile capture uploads</strong>
                        <small>Phone photos sent to desktop will be processed automatically.</small>
                    </span>
                </label>

                <label class="pp-toggle">
                    <input type="checkbox" name="keep_original" value="1" @checked(old('keep_original', $settings->keep_original))>
                    <span>
                        <strong>Keep original shop photo</strong>
                        <small>Recommended. This gives you a safe fallback if the AI cutout is wrong.</small>
                    </span>
                </label>

                <div class="pp-form-grid">
                    <label class="pp-field">
                        <span>Final background color</span>
                        <input type="color" name="background_color" value="{{ old('background_color', $settings->background_color) }}" required>
                    </label>

                    <label class="pp-field">
                        <span>Python command</span>
                        <input type="text" name="python_command" value="{{ old('python_command', $settings->python_command) }}" required>
                        <small>Default is <code>py</code>. If we install Python 3.11 later, this may become <code>py -3.11</code>.</small>
                    </label>

                    <label class="pp-field">
                        <span>Timeout seconds</span>
                        <input type="number" name="timeout_seconds" value="{{ old('timeout_seconds', $settings->timeout_seconds) }}" min="30" max="600" required>
                    </label>
                </div>

                <button type="submit" class="pp-primary">Save photo processing settings</button>
            </form>

            <aside class="pp-card">
                <div class="pp-card-head">
                    <h2>AI engine status</h2>
                    <p>The app is wired for local AI. The AI package must be installed once before this can process photos.</p>
                </div>

                <div class="pp-status-box {{ $availability['available'] ? 'is-good' : 'is-warn' }}">
                    <strong>{{ $availability['message'] }}</strong>
                    @if ($availability['python'])
                        <span>Python {{ $availability['python'] }}</span>
                    @endif
                    @if ($availability['details'])
                        <code>{{ $availability['details'] }}</code>
                    @endif
                </div>

                <div class="pp-flow">
                    <h3>Saved image flow</h3>
                    <ol>
                        <li>Phone sends the raw product photo.</li>
                        <li>App stores the untouched original photo.</li>
                        <li>AI removes the background and creates a white-background image.</li>
                        <li>Watermark is applied to the final image.</li>
                        <li>The dashboard shows the final image and keeps a link to the original.</li>
                    </ol>
                </div>
            </aside>
        </div>

        <section class="pp-card pp-test-card">
            <div class="pp-card-head">
                <h2>Test with a sample photo</h2>
                <p>Upload one sample product photo to preview the same AI white-background process before using it on real shop photos. This does not create a product record.</p>
            </div>

            <form method="POST" action="{{ route('settings.photo-processing.test') }}" enctype="multipart/form-data" class="pp-test-form">
                @csrf
                <label class="pp-file-drop">
                    <input type="file" name="sample_photo" accept="image/*" required>
                    <span>
                        <strong>Choose sample product photo</strong>
                        <small>Max 35MB. The test uses the saved Python command, timeout, background color, and current watermark settings.</small>
                    </span>
                </label>
                @error('sample_photo')
                    <div class="pp-error">{{ $message }}</div>
                @enderror
                <button type="submit" class="pp-primary">Run AI background removal test</button>
            </form>

            @if ($testResult)
                <div class="pp-test-result {{ $testResult['ok'] ? 'is-good' : 'is-failed' }}">
                    <div>
                        <strong>{{ $testResult['message'] }}</strong>
                        <span>Tested at {{ $testResult['tested_at'] }}</span>
                        @if (! empty($testResult['details']))
                            <code>{{ $testResult['details'] }}</code>
                        @endif
                    </div>
                </div>

                <div class="pp-before-after">
                    <article>
                        <span>Original sample</span>
                        <a href="{{ $testResult['original_url'] }}" target="_blank" rel="noopener">
                            <img src="{{ $testResult['original_url'] }}" alt="Original sample photo">
                        </a>
                        <small>{{ $testResult['original_path'] }}</small>
                    </article>

                    <article>
                        <span>Processed white-background result</span>
                        @if ($testResult['processed_url'])
                            <a href="{{ $testResult['processed_url'] }}" target="_blank" rel="noopener">
                                <img src="{{ $testResult['processed_url'] }}" alt="Processed sample photo">
                            </a>
                            <small>{{ $testResult['processed_path'] }}</small>
                        @else
                            <div class="pp-result-empty">No processed image was created. Check the error above.</div>
                        @endif
                    </article>
                </div>
            @endif

            <form method="POST" action="{{ route('settings.photo-processing.test-files.clear') }}" onsubmit="return confirm('Delete all photo processing test files from local storage? This only removes sample test outputs.')" class="pp-clear-tests">
                @csrf
                @method('DELETE')
                <button type="submit">Delete sample test files</button>
            </form>
        </section>
    </section>
@endsection
