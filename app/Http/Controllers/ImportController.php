<?php

namespace App\Http\Controllers;

use App\Services\ExternalJsonImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function create(): View
    {
        return view('imports.create');
    }

    public function store(Request $request, ExternalJsonImportService $importService): RedirectResponse
    {
        $validated = $request->validate([
            'json_payload' => ['nullable', 'string', 'required_without:json_file'],
            'json_file' => ['nullable', 'file', 'required_without:json_payload', 'mimes:json,txt'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'shop_photos' => ['nullable', 'array'],
            'shop_photos.*' => ['image', 'max:5120'],
        ]);

        $payload = $validated['json_payload'] ?? null;

        if (! $payload && $request->hasFile('json_file')) {
            $payload = file_get_contents($request->file('json_file')->getRealPath()) ?: '';
        }

        $payloadPreview = $importService->previewPayloadCleanup($payload ?? '');
        $sourceLabel = filled($validated['source_label'] ?? null)
            ? trim((string) $validated['source_label'])
            : $importService->inferSourceLabel($payloadPreview['cleaned_payload']);

        try {
            $batch = $importService->import(
                payload: $payloadPreview['cleaned_payload'],
                channel: $request->hasFile('json_file') ? 'file_upload' : 'paste',
                originalFilename: $request->file('json_file')?->getClientOriginalName(),
                sourceLabel: $sourceLabel,
                notes: $validated['notes'] ?? null,
                shopPhotos: $request->file('shop_photos', []),
                actor: $request->user(),
            );
        } catch (ValidationException $exception) {
            $response = redirect()
                ->route('imports.create')
                ->withErrors($exception->errors())
                ->withInput(array_merge(
                    $request->except(['json_file', 'shop_photos']),
                    [
                        'json_payload' => $payloadPreview['cleaned_payload'],
                        'source_label' => $sourceLabel,
                    ],
                ));

            if ($payloadPreview['changed']) {
                $response->with('cleaned_json_preview', $payloadPreview['cleaned_payload']);
                $response->with('payload_cleanup_notes', $payloadPreview['cleanup_notes']);
            }

            return $response;
        }

        $status = "Imported {$batch->accepted_records} draft record(s) in batch {$batch->batch_uuid}.";

        if ($payloadPreview['changed']) {
            $status .= ' Auto-cleaned formatting issues before decoding.';
        }

        return redirect()
            ->route('review.index')
            ->with('status', $status);
    }
}
