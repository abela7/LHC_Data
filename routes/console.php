<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('shaba:import-reference {--path=} {--fresh}', function () {
    /** @var \App\Services\ShabaReferenceImporter $importer */
    $importer = app(\App\Services\ShabaReferenceImporter::class);
    $summary = $importer->import(
        path: $this->option('path') ? (string) $this->option('path') : null,
        fresh: (bool) $this->option('fresh'),
    );

    $this->table(
        ['Metric', 'Value'],
        [
            ['Path', $summary['path']],
            ['Raw rows', (string) $summary['total_rows']],
            ['Unique products', (string) $summary['unique_products']],
            ['Created products', (string) $summary['created']],
            ['Updated products', (string) $summary['updated']],
            ['Variants', (string) $summary['variants']],
            ['Images', (string) $summary['media']],
            ['Brands', (string) $summary['brands']],
            ['Invalid rows', (string) count($summary['invalid_rows'])],
        ],
    );

    if ($summary['top_brands'] !== []) {
        $this->line('Top brands:');
        $this->table(
            ['Brand', 'Products'],
            collect($summary['top_brands'])
                ->map(fn (int $count, string $brand): array => [$brand, (string) $count])
                ->values()
                ->all(),
        );
    }

    if ($summary['invalid_rows'] !== []) {
        $this->warn('Invalid JSONL rows: '.implode(', ', array_slice($summary['invalid_rows'], 0, 25)));
    }

    $this->info('Shaba reference import complete.');
})->purpose('Import the Shaba Shopify JSONL reference dataset into searchable tables.');

Artisan::command('shop-photos:import-batch {folder} {--name=} {--slug=} {--fresh}', function (string $folder) {
    $resolved = realpath($folder);

    if ($resolved === false || ! is_dir($resolved)) {
        $this->error("Photo folder was not found: {$folder}");

        return 1;
    }

    $name = trim((string) ($this->option('name') ?: basename($resolved))) ?: 'Shop photo batch';
    $slug = trim((string) ($this->option('slug') ?: \Illuminate\Support\Str::slug($name))) ?: 'shop-photo-batch';

    if ((bool) $this->option('fresh')) {
        \App\Models\ShopPhotoBatch::query()->where('slug', $slug)->delete();
    }

    $manifest = [];
    $manifestPath = $resolved.DIRECTORY_SEPARATOR.'_photo_rename_manifest.csv';
    if (is_file($manifestPath) && ($handle = fopen($manifestPath, 'rb')) !== false) {
        $headers = fgetcsv($handle) ?: [];
        while (($row = fgetcsv($handle)) !== false) {
            $record = array_combine($headers, $row);
            if (is_array($record) && ! empty($record['new_name'])) {
                $manifest[$record['new_name']] = $record;
            }
        }
        fclose($handle);
    }

    $files = collect(scandir($resolved) ?: [])
        ->filter(fn (string $name): bool => preg_match('/\.(jpe?g|png|webp|bmp)$/i', $name) === 1)
        ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))
        ->values();

    if ($files->isEmpty()) {
        $this->error("No image files were found in {$resolved}");

        return 1;
    }

    $batch = \App\Models\ShopPhotoBatch::query()->updateOrCreate(
        ['slug' => $slug],
        [
            'name' => $name,
            'source_folder' => $resolved,
            'photos_count' => $files->count(),
        ],
    );

    foreach ($files as $index => $filename) {
        preg_match('/^photo\s+(\d+)/i', $filename, $matches);
        $sequence = isset($matches[1]) ? (int) $matches[1] : ($index + 1);
        $manifestRecord = $manifest[$filename] ?? [];

        \App\Models\ShopPhotoBatchItem::query()->updateOrCreate(
            [
                'shop_photo_batch_id' => $batch->id,
                'sequence' => $sequence,
            ],
            [
                'original_filename' => $manifestRecord['original_name'] ?? null,
                'filename' => $filename,
                'source_path' => $resolved.DIRECTORY_SEPARATOR.$filename,
            ],
        );
    }

    $batch->update(['photos_count' => $files->count()]);

    $this->table(
        ['Metric', 'Value'],
        [
            ['Batch', $batch->name],
            ['Slug', $batch->slug],
            ['Folder', $resolved],
            ['Photos', (string) $files->count()],
            ['Manifest rows', (string) count($manifest)],
            ['Review page', route('shop-photo-batches.show', $batch)],
        ],
    );

    return 0;
})->purpose('Import a local shop photo folder into the editable photo-batch review page.');

Artisan::command(
    'hair-extension:v2-from-batch-photo {batch_slug} {sequence : Sequence number of the photo in the batch (e.g. 42)} '
    .'{--brand-id=} {--brand-name=} {--product-type-id=} {--product-type-name=} {--style-id=} {--style-name=} '
    .'{--classification= : Classification path: use "A > B > C" or a JSON string array} '
    .'{--main-axis=Length} {--sub-axis=Colour} '
    .'{--variants= : JSON array (or use --variants-file on Windows); [{"main_value":"20 inch","sub_values":["1B"]}]} '
    .'{--variants-file= : Path to .json file containing the variant rows array} '
    .'{--common=} {--common-file=} {--notes=} {--dry-run} {--no-photo}',
    function (string $batch_slug, string $sequence): int {
        $batch = \App\Models\ShopPhotoBatch::query()->where('slug', $batch_slug)->first();
        if (! $batch) {
            $this->error("Batch not found: {$batch_slug}");

            return 1;
        }

        $seq = (int) $sequence;
        $item = $batch->items()->where('sequence', $seq)->first();
        if (! $item) {
            $this->error("No item with sequence {$seq} in batch {$batch_slug}.");

            return 1;
        }

        $variantsFile = $this->option('variants-file');
        if ($variantsFile !== null && $variantsFile !== '') {
            $resolvedVariants = realpath((string) $variantsFile);
            if ($resolvedVariants === false || ! is_file($resolvedVariants)) {
                $this->error('Variants file not found: '.(string) $variantsFile);

                return 1;
            }
            $variantsRaw = (string) file_get_contents($resolvedVariants);
        } else {
            $variantsRaw = $this->option('variants');
        }

        if ($variantsRaw === null || trim($variantsRaw) === '') {
            $this->error('Pass --variants= with JSON or --variants-file=path/to.json (recommended on Windows).');

            return 1;
        }

        $commonFile = $this->option('common-file');
        if ($commonFile !== null && $commonFile !== '') {
            $resolvedCommon = realpath((string) $commonFile);
            if ($resolvedCommon === false || ! is_file($resolvedCommon)) {
                $this->error('Common variants file not found: '.(string) $commonFile);

                return 1;
            }
            $commonRaw = (string) file_get_contents($resolvedCommon);
        } else {
            $commonRaw = $this->option('common');
        }

        $classification = $this->option('classification');

        $data = [
            'brand_catalogue_brand_id' => $this->option('brand-id') !== null && $this->option('brand-id') !== ''
                ? (int) $this->option('brand-id')
                : null,
            'brand_catalogue_product_type_id' => $this->option('product-type-id') !== null && $this->option('product-type-id') !== ''
                ? (int) $this->option('product-type-id')
                : null,
            'brand_catalogue_style_id' => $this->option('style-id') !== null && $this->option('style-id') !== ''
                ? (int) $this->option('style-id')
                : null,
            'brand_name' => $this->option('brand-name') ? trim((string) $this->option('brand-name')) : null,
            'product_type_name' => $this->option('product-type-name') !== null && $this->option('product-type-name') !== ''
                ? trim((string) $this->option('product-type-name'))
                : null,
            'catalogue_style_status' => 'known',
            'product_type_status' => 'known',
            'style_family_status' => 'known',
            'classification_path' => ($classification !== null && $classification !== '') ? trim((string) $classification) : null,
            'shelf_location' => null,
            'store_id' => null,
            'section_id' => null,
            'subsection_id' => null,
            'style_name' => $this->option('style-name') !== null && $this->option('style-name') !== ''
                ? trim((string) $this->option('style-name'))
                : null,
            'variant_main_axis' => (string) ($this->option('main-axis') ?: 'Length'),
            'variant_sub_axis' => (string) ($this->option('sub-axis') ?: 'Colour'),
            'variant_rows' => (string) $variantsRaw,
            'common_variant_rows' => $commonRaw !== null && $commonRaw !== '' ? (string) $commonRaw : '[]',
            'visible_text_notes' => $this->option('notes') ? trim((string) $this->option('notes')) : null,
        ];

        if (($data['brand_catalogue_style_id'] ?? null) === null && ($data['style_name'] ?? '') !== '') {
            $data['catalogue_style_status'] = 'not_sure';
        }
        if (($data['brand_catalogue_product_type_id'] ?? null) === null && ($data['product_type_name'] ?? '') !== '') {
            $data['product_type_status'] = 'not_sure';
        }

        /** @var \App\Http\Controllers\HairExtensionIntakeController $controller */
        $controller = app(\App\Http\Controllers\HairExtensionIntakeController::class);
        $rules = $controller->v2FamilyRules(false);
        unset($rules['cover_photo'], $rules['remove_photo']);

        try {
            $validated = Validator::make($data, $rules)->validate();
            [$updates] = $controller->v2FamilyUpdates($validated);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->error('Validation failed:');
            foreach ($e->errors() as $field => $messages) {
                $this->line($field.': '.implode(' ', $messages));
            }

            return 1;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — payload that would be saved:');
            $this->line(json_encode($updates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        if (! $this->option('no-photo') && ! is_file($item->source_path)) {
            $this->error('Source file missing: '.$item->source_path);

            return 1;
        }

        $intake = \App\Models\HairExtensionIntake::query()->create(array_merge($updates, [
            'status' => 'submitted',
            'ai_status' => 'ready_for_ai',
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]));

        if (! $this->option('no-photo')) {
            $controller->attachIntakePhotoFromAbsolutePath(
                $intake,
                $item->source_path,
                $item->original_filename ?: $item->filename,
            );
        }

        $this->info('Created submitted intake #'.$intake->id.' ('.$intake->brand_name.' — '.$intake->style_name.').');
        $this->line('Edit URL: '.\route('hair-extension-intake.v2', ['edit_intake' => $intake->id]));
        $this->line('Submitted list: '.\route('hair-extension-intake.submitted'));

        return 0;
    }
)->purpose('Create a submitted Hair Extension V2 intake from a shop batch photo + variant JSON (fast data entry).');

/**
 * Quick text-based intake creator.
 *
 * Accepts a tiny text format (the same shape you paste in chat):
 *
 *   Photo: 019              (or "photo 019.jpg" anywhere in text)
 *   Brand: Cherish
 *   Grouping path: Cherish > BOHO            (optional)
 *   Product type: Crochet Braid              (optional)
 *   Style / family: Saniya Boho Braid
 *   Variants:
 *     Main (Length): 20"
 *     Sub (Colour): 2
 *     Common (Pack): 3 pack          (optional, repeatable)
 *   Notes: anything                          (optional)
 *
 * Usage:
 *   php artisan hei:quick path/to/text.txt
 *   php artisan hei:quick --batch=batch-two path/to/text.txt
 *   php artisan hei:quick --dry-run path/to/text.txt
 *   php artisan hei:quick --no-create-brand path/to/text.txt   (fail if brand not already in list)
 *   php artisan hei:quick --literal ...  (brand catalogue only; product type + style stay as your text)
 */
Artisan::command(
    'hei:quick {file? : Path to text file. If omitted, reads STDIN.} '
    .'{--batch=batch-two : Shop photo batch slug} '
    .'{--dry-run} {--no-photo} {--literal : Match brand only; do not link product type or style to catalogue rows} '
    .'{--no-create-brand : Do not insert a new brand_catalogue_brands row if the name is unknown}',
    function (?string $file = null): int {
        $raw = ($file !== null && $file !== '')
            ? (is_file($file) ? (string) file_get_contents($file) : '')
            : (string) stream_get_contents(STDIN);

        if (trim($raw) === '') {
            $this->error('No text provided. Pass a file path or pipe text via STDIN.');

            return 1;
        }

        $parsed = \App\Support\HeiQuickIntake::parse($raw);

        if (! $parsed['photo_number']) {
            $this->error('Could not detect a photo number. Use "Photo: 019" or "photo 019.jpg" in the text.');

            return 1;
        }
        if (! $parsed['brand']) {
            $this->error('Brand is required. Add a "Brand: ..." line.');

            return 1;
        }

        $batchSlug = (string) $this->option('batch');
        $batch = \App\Models\ShopPhotoBatch::query()->where('slug', $batchSlug)->first();
        if (! $batch) {
            $this->error("Batch not found: {$batchSlug}");

            return 1;
        }

        $item = $batch->items()->where('sequence', $parsed['photo_number'])->first();
        if (! $item) {
            $this->error("No photo with sequence {$parsed['photo_number']} in batch {$batchSlug}.");

            return 1;
        }

        $createBrand = ! $this->option('no-create-brand') && ! $this->option('dry-run');
        $resolved = \App\Support\HeiQuickIntake::resolveCatalogue(
            brandName: $parsed['brand'],
            productTypeName: $parsed['product_type'],
            styleName: $parsed['style'],
            createBrandIfMissing: $createBrand,
            linkCatalogueProductTypeAndStyle: ! $this->option('literal'),
        );

        if (! $resolved['brand']) {
            $this->error('Brand did not match your brand catalogue: '.(string) $parsed['brand']);
            $hints = \App\Support\HeiQuickIntake::suggestCatalogueBrandNames((string) $parsed['brand']);
            if ($hints !== []) {
                $this->line('Similar catalogue names: '.implode(', ', $hints));
            }
            if ($this->option('dry-run')) {
                $this->line('Remove --dry-run to auto-create this brand in brand_catalogue_brands, or add it in the admin UI first.');
            } elseif ($this->option('no-create-brand')) {
                $this->line('Omit --no-create-brand to auto-create unknown brands, or add the brand in your brand list first.');
            } else {
                $this->line('Add the brand in your brand list, or check spelling.');
            }

            return 1;
        }

        $data = [
            'brand_catalogue_brand_id' => $resolved['brand']?->id,
            'brand_catalogue_product_type_id' => $resolved['product_type']?->id,
            'brand_catalogue_style_id' => $resolved['style']?->id,
            'brand_name' => $resolved['brand']->name,
            'product_type_name' => $resolved['product_type']?->name ?? $parsed['product_type'],
            'catalogue_style_status' => $resolved['style'] ? 'known' : ($parsed['style'] ? 'not_sure' : 'not_known'),
            'product_type_status' => $resolved['product_type'] ? 'known' : ($parsed['product_type'] ? 'not_sure' : 'not_known'),
            'style_family_status' => $parsed['style'] ? 'known' : 'not_known',
            'classification_path' => $parsed['classification_path'] ? json_encode($parsed['classification_path']) : null,
            'shelf_location' => $parsed['shelf_location'],
            'store_id' => null,
            'section_id' => null,
            'subsection_id' => null,
            'style_name' => $resolved['style']?->name ?? $parsed['style'],
            'variant_main_axis' => $parsed['main_axis'] ?: 'Length',
            'variant_sub_axis' => $parsed['sub_axis'] ?: 'Colour',
            'variant_rows' => json_encode($parsed['variant_rows']),
            'common_variant_rows' => json_encode($parsed['common_rows']),
            'visible_text_notes' => $parsed['notes'],
        ];

        /** @var \App\Http\Controllers\HairExtensionIntakeController $controller */
        $controller = app(\App\Http\Controllers\HairExtensionIntakeController::class);
        $rules = $controller->v2FamilyRules(false);
        unset($rules['cover_photo'], $rules['remove_photo']);

        try {
            $validated = Validator::make($data, $rules)->validate();
            [$updates] = $controller->v2FamilyUpdates($validated);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->error('Validation failed:');
            foreach ($e->errors() as $field => $messages) {
                $this->line($field.': '.implode(' ', $messages));
            }

            return 1;
        }

        $this->table(['Field', 'Value'], [
            ['Photo', "#{$parsed['photo_number']} — ".($item->filename ?: '(unknown)')],
            ['Brand', $updates['brand_name'].' '.($updates['brand_catalogue_brand_id']
                ? '(catalogue id '.$updates['brand_catalogue_brand_id'].($resolved['brand_was_created'] ? ', newly created' : '').')'
                : '(no catalogue match)')],
            ['Product type', ($updates['product_type_name'] ?: '—').' '.($updates['brand_catalogue_product_type_id'] ? "(id {$updates['brand_catalogue_product_type_id']})" : '(text only)')],
            ['Style', ($updates['style_name'] ?: '—').' '.($updates['brand_catalogue_style_id'] ? "(id {$updates['brand_catalogue_style_id']})" : '(text only)')],
            ['Classification', $updates['classification_path'] ? implode(' > ', $updates['classification_path']) : '—'],
            ['Shelf / area', $updates['shelf_location'] ?: '—'],
            ['Variants', (string) data_get($updates, 'variant_structure.summary.sellable_combination_count', 0).' SKU(s)'],
            ['Notes', $updates['visible_text_notes'] ? \Illuminate\Support\Str::limit((string) $updates['visible_text_notes'], 80) : '—'],
        ]);

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing saved.');

            return 0;
        }

        if (! $this->option('no-photo') && ! is_file($item->source_path)) {
            $this->error('Source file missing: '.$item->source_path);

            return 1;
        }

        $intake = \App\Models\HairExtensionIntake::query()->create(array_merge($updates, [
            'status' => 'submitted',
            'ai_status' => 'ready_for_ai',
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]));

        if (! $this->option('no-photo')) {
            $controller->attachIntakePhotoFromAbsolutePath(
                $intake,
                $item->source_path,
                $item->original_filename ?: $item->filename,
            );
        }

        $this->info("Saved intake #{$intake->id}: {$intake->brand_name} — {$intake->style_name}");
        $this->line('Edit:      '.\route('hair-extension-intake.v2', ['edit_intake' => $intake->id]));
        $this->line('Submitted: '.\route('hair-extension-intake.submitted'));

        return 0;
    }
)->purpose('Quick: paste your text → submitted V2 intake with the matching batch photo attached.');

Artisan::command('catalogue:sync-ai-enrichments {--path=} {--dry-run}', function () {
    /** @var \App\Services\CatalogueAiEnrichmentImporter $importer */
    $importer = app(\App\Services\CatalogueAiEnrichmentImporter::class);
    $summary = $importer->import(
        path: $this->option('path'),
        dryRun: (bool) $this->option('dry-run'),
    );

    $this->table(
        ['Metric', 'Value'],
        [
            ['Path', $summary['path']],
            ['Total imported rows', (string) $summary['total_rows']],
            ['Created', (string) $summary['created']],
            ['Updated', (string) $summary['updated']],
            ['Needs review', (string) $summary['needs_review']],
            ['Skipped blank', (string) $summary['skipped_blank']],
            ['Skipped invalid', (string) $summary['skipped_invalid']],
        ],
    );

    if ($summary['invalid_rows'] !== []) {
        $this->warn('Invalid rows:');

        foreach ($summary['invalid_rows'] as $invalidRow) {
            $this->line(" - {$invalidRow}");
        }
    }

    $this->info($this->option('dry-run') ? 'Dry run complete.' : 'AI enrichment sync complete.');
})->purpose('Sync the catalogue AI CSV into the database safely.');

Artisan::command('mamado:import-order-json {path}', function (string $path) {
    $resolvedPath = $path;

    if (! is_file($resolvedPath)) {
        $resolvedPath = base_path($path);
    }

    if (! is_file($resolvedPath)) {
        $this->error("Mamado order JSON was not found: {$path}");

        return 1;
    }

    $payload = json_decode((string) file_get_contents($resolvedPath), true);

    if (! is_array($payload) || ! is_array($payload['products'] ?? null)) {
        $this->error('Mamado order JSON is invalid or has no products array.');

        return 1;
    }

    $created = 0;
    $updated = 0;

    foreach ($payload['products'] as $line) {
        $itemCode = trim((string) ($line['item_code'] ?? ''));
        $description = trim((string) ($line['item_description'] ?? ''));
        $grossUnitPrice = str_replace(',', '', trim((string) ($line['gross_unit_price_gbp'] ?? $line['gross_unit_price'] ?? '')));

        if ($itemCode === '' || $description === '') {
            continue;
        }

        $product = \App\Models\MamadoProduct::query()->updateOrCreate(
            ['item_code' => $itemCode],
            [
                'item_description' => $description,
                'gross_unit_price' => is_numeric($grossUnitPrice) ? (float) $grossUnitPrice : null,
                'units' => trim((string) ($line['units'] ?? '')) ?: null,
                'source_order_number' => trim((string) ($line['source_order_number'] ?? $payload['order_number'] ?? '')) ?: null,
                'source_order_date' => trim((string) ($line['source_order_date'] ?? $payload['order_date'] ?? '')) ?: null,
                'source_delivery_date' => trim((string) ($line['source_delivery_date'] ?? $payload['delivery_date'] ?? '')) ?: null,
                'raw_order_line' => $line,
                'status' => 'source_only',
            ],
        );

        $product->wasRecentlyCreated ? $created++ : $updated++;
    }

    $this->table(
        ['Metric', 'Value'],
        [
            ['Path', $resolvedPath],
            ['Order', (string) ($payload['order_number'] ?? '')],
            ['Rows in JSON', (string) count($payload['products'])],
            ['Created', (string) $created],
            ['Updated', (string) $updated],
        ],
    );

    $this->info('Mamado order import complete.');

    return 0;
})->purpose('Import extracted Mamado order lines into the Mamado product source list.');

Artisan::command('catalogue:restore-observed-products {--path=} {--skip-ai-sync}', function () {
    /** @var \App\Services\ObservedProductRestoreImporter $importer */
    $importer = app(\App\Services\ObservedProductRestoreImporter::class);
    $summary = $importer->import(
        path: $this->option('path') ? (string) $this->option('path') : null,
        syncAi: ! (bool) $this->option('skip-ai-sync'),
    );

    $this->table(
        ['Metric', 'Value'],
        [
            ['Path', $summary['path']],
            ['Pictures', (string) $summary['pictures']],
            ['Observed rows imported', (string) $summary['imported_rows']],
            ['Observed brands', (string) $summary['brands']],
            ['Category matches from AI CSV', (string) $summary['matched_categories']],
            ['Category heuristic fallbacks', (string) $summary['heuristic_categories']],
            ['Skipped blank/invalid entries', (string) $summary['skipped_entries']],
        ],
    );

    if (is_array($summary['ai_summary'])) {
        $this->line('AI enrichment sync:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Path', (string) ($summary['ai_summary']['path'] ?? '')],
                ['Imported rows', (string) ($summary['ai_summary']['total_rows'] ?? 0)],
                ['Created', (string) ($summary['ai_summary']['created'] ?? 0)],
                ['Updated', (string) ($summary['ai_summary']['updated'] ?? 0)],
                ['Needs review', (string) ($summary['ai_summary']['needs_review'] ?? 0)],
                ['Skipped blank', (string) ($summary['ai_summary']['skipped_blank'] ?? 0)],
                ['Skipped invalid', (string) ($summary['ai_summary']['skipped_invalid'] ?? 0)],
            ],
        );
    }

    $this->info('Observed product restore complete.');
})->purpose('Restore observed shelf products from the picture-product map.');

Artisan::command('catalogue:compare-brand-page {url} {--label=} {--output-dir=}', function () {
    /** @var \App\Services\ExternalBrandComparisonService $service */
    $service = app(\App\Services\ExternalBrandComparisonService::class);
    $result = $service->compare(
        url: (string) $this->argument('url'),
        label: $this->option('label') ? (string) $this->option('label') : null,
        outputDir: $this->option('output-dir') ? (string) $this->option('output-dir') : null,
    );

    $summary = $result['summary'];

    $this->table(
        ['Metric', 'Value'],
        [
            ['Site label', $summary['site_label']],
            ['Site URL', $summary['site_url']],
            ['Brand candidates', (string) $summary['brand_candidates']],
            ['Internal brands', (string) $summary['internal_brand_count']],
            ['Matched external brands', (string) $summary['matched_external_brand_count']],
            ['Unmatched external brands', (string) $summary['unmatched_external_brand_count']],
            ['Matched internal brands', (string) $summary['matched_internal_brand_count']],
            ['Unmatched internal brands', (string) $summary['unmatched_internal_brand_count']],
        ],
    );

    $this->line('Saved files:');
    $this->line(" - HTML: {$result['html_path']}");
    $this->line(" - Raw CSV: {$result['raw_path']}");
    $this->line(" - Candidates CSV: {$result['candidate_path']}");
    $this->line(" - Internal CSV: {$result['internal_path']}");
    $this->line(" - Compare CSV: {$result['comparison_path']}");
    $this->line(" - Internal compare CSV: {$result['internal_comparison_path']}");
    $this->line(" - Summary JSON: {$result['summary_path']}");
})->purpose('Extract brands from an external page and compare them with internal catalogue brands.');

Artisan::command('catalogue:import-sherrys-pdf {--path=} {--from=3} {--to=} {--fresh}', function () {
    /** @var \App\Services\SherrysPdfCatalogueImporter $importer */
    $importer = app(\App\Services\SherrysPdfCatalogueImporter::class);

    $fromPage = (int) $this->option('from');
    $toPageOption = $this->option('to');
    $toPage = $toPageOption !== null && trim((string) $toPageOption) !== ''
        ? (int) $toPageOption
        : null;

    $summary = $importer->import(
        path: $this->option('path') ? (string) $this->option('path') : null,
        fromPage: $fromPage,
        toPage: $toPage,
        fresh: (bool) $this->option('fresh'),
    );

    $this->table(
        ['Metric', 'Value'],
        [
            ['Path', $summary['path']],
            ['Source', $summary['source_name']],
            ['From page', (string) $summary['from_page']],
            ['To page', (string) $summary['to_page']],
            ['Pages imported', (string) $summary['pages_imported']],
            ['Products imported', (string) $summary['products_imported']],
            ['Needs review', (string) $summary['needs_review']],
            ['A confidence', (string) ($summary['confidence_breakdown']['A'] ?? 0)],
            ['B confidence', (string) ($summary['confidence_breakdown']['B'] ?? 0)],
            ['C confidence', (string) ($summary['confidence_breakdown']['C'] ?? 0)],
            ['D confidence', (string) ($summary['confidence_breakdown']['D'] ?? 0)],
        ],
    );

    $this->info('Sherrys PDF import complete.');
})->purpose('Extract one product-code row per sellable product from the Sherrys PDF catalogue.');

Artisan::command('catalogue:sync-sherrys-assets {--folder=} {--fresh} {--dry-run}', function () {
    /** @var \App\Services\SherrysCatalogueAssetSyncService $sync */
    $sync = app(\App\Services\SherrysCatalogueAssetSyncService::class);

    $summary = $sync->sync(
        folder: $this->option('folder') ? (string) $this->option('folder') : null,
        fresh: (bool) $this->option('fresh'),
        dryRun: (bool) $this->option('dry-run'),
    );

    $this->table(
        ['Metric', 'Value'],
        [
            ['Folder', $summary['folder']],
            ['Dry run', $summary['dry_run'] ? 'yes' : 'no'],
            ['Fresh', $summary['fresh'] ? 'yes' : 'no'],
            ['DB products', (string) $summary['db_products']],
            ['DB unique codes', (string) $summary['db_unique_codes']],
            ['Manifest rows', (string) $summary['manifest_rows']],
            ['Manifest unique codes', (string) $summary['manifest_unique_codes']],
            ['High quality files', (string) $summary['high_quality_files']],
            ['Matched unique codes', (string) $summary['matched_unique_codes']],
            ['Products touched', (string) $summary['products_touched']],
            ['Images deleted', (string) $summary['images_deleted']],
            ['Images created/planned', (string) $summary['images_created']],
            ['DB codes without image', (string) $summary['db_codes_without_manifest_image']],
            ['Manifest codes not in DB', (string) $summary['manifest_codes_not_in_db']],
        ],
    );

    if ($summary['sample_missing_codes'] !== []) {
        $this->warn('Sample DB codes without manifest image: '.implode(', ', $summary['sample_missing_codes']));
    }

    if ($summary['sample_orphan_manifest_codes'] !== []) {
        $this->warn('Sample manifest codes not in DB: '.implode(', ', $summary['sample_orphan_manifest_codes']));
    }

    $this->info($this->option('dry-run') ? 'Sherrys asset sync dry run complete.' : 'Sherrys asset sync complete.');
})->purpose('Attach extracted Sherrys catalogue product images to imported PDF product rows.');

Artisan::command('catalogue:import-hair-ornaments-pdf {--path=} {--from=1} {--to=} {--fresh}', function () {
    /** @var \App\Services\HairOrnamentsPdfCatalogueImporter $importer */
    $importer = app(\App\Services\HairOrnamentsPdfCatalogueImporter::class);

    $fromPage = (int) $this->option('from');
    $toPageOption = $this->option('to');
    $toPage = $toPageOption !== null && trim((string) $toPageOption) !== ''
        ? (int) $toPageOption
        : null;

    $summary = $importer->import(
        path: $this->option('path') ? (string) $this->option('path') : null,
        fromPage: $fromPage,
        toPage: $toPage,
        fresh: (bool) $this->option('fresh'),
    );

    $this->table(
        ['Metric', 'Value'],
        [
            ['Path', $summary['path']],
            ['Source', $summary['source_name']],
            ['From page', (string) $summary['from_page']],
            ['To page', (string) $summary['to_page']],
            ['Pages imported', (string) $summary['pages_imported']],
            ['Products imported', (string) $summary['products_imported']],
            ['Needs review', (string) $summary['needs_review']],
            ['A confidence', (string) ($summary['confidence_breakdown']['A'] ?? 0)],
            ['B confidence', (string) ($summary['confidence_breakdown']['B'] ?? 0)],
            ['C confidence', (string) ($summary['confidence_breakdown']['C'] ?? 0)],
            ['D confidence', (string) ($summary['confidence_breakdown']['D'] ?? 0)],
        ],
    );

    $this->info('Hair ornaments PDF import complete.');
})->purpose('Extract sellable product rows from the Hair Ornaments accessories PDF catalogue.');

/**
 * Rewrite every sellable product's SKU to follow the unified scheme:
 *     {DEPT}-{BRAND}-{FFFFF}{V}    e.g. HE-XPR-00012A
 *
 * Behaviour:
 *   - --dry-run   : show what would change, do not write anything.
 *   - --limit=N   : only rewrite the first N products (handy for spot-checks).
 *   - --only-missing : skip products that already match the new scheme.
 *
 * The previous SKU is preserved on products.legacy_sku before the rewrite,
 * so the migration is fully reversible.
 */
Artisan::command('sku:migrate-codes {--dry-run} {--only-missing} {--limit=}', function () {
    /** @var \App\Services\SkuCodeAllocator $allocator */
    $allocator = app(\App\Services\SkuCodeAllocator::class);

    $dryRun = (bool) $this->option('dry-run');
    $onlyMissing = (bool) $this->option('only-missing');
    $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

    $newSchemePattern = '/^[A-Z]{2}-[A-Z0-9]{3,4}-\d+[A-Z]+$/';

    $families = \App\Models\ProductFamily::query()
        ->orderBy('root_catalogue_name')
        ->orderBy('brand_id')
        ->orderBy('id')
        ->get();

    $stats = [
        'families_processed' => 0,
        'families_skipped' => 0,
        'products_renamed' => 0,
        'products_already_ok' => 0,
        'products_failed' => 0,
    ];

    $changes = [];

    // Run inside a transaction so that during --dry-run we can let the
    // allocator persist sku_family_seq for realistic numbering and then
    // roll the whole batch back at the end.
    \Illuminate\Support\Facades\DB::beginTransaction();

    try {
        foreach ($families as $family) {
            $products = \App\Models\Product::query()
                ->where('product_family_id', $family->id)
                ->orderBy('id')
                ->get();

            if ($products->isEmpty()) {
                $stats['families_skipped']++;
                continue;
            }

            try {
                $prefix = $allocator->ensureFamilyPrefix($family);
            } catch (\Throwable $e) {
                $this->error("Family #{$family->id}: ".$e->getMessage());
                $stats['families_skipped']++;
                continue;
            }

            $stats['families_processed']++;

            $variantIndex = 1;
            foreach ($products as $product) {
                if ($limit !== null && $stats['products_renamed'] >= $limit) {
                    break 2;
                }

                $currentSku = (string) $product->sku;
                $alreadyMatches = $currentSku !== '' && preg_match($newSchemePattern, $currentSku) === 1
                    && str_starts_with($currentSku, $prefix);

                if ($onlyMissing && $alreadyMatches) {
                    $stats['products_already_ok']++;
                    continue;
                }

                do {
                    $letter = $allocator->indexToLetters($variantIndex);
                    $candidate = $prefix.$letter;
                    $variantIndex++;
                    $collision = \App\Models\Product::query()
                        ->where('sku', $candidate)
                        ->where('id', '!=', $product->id)
                        ->exists();
                } while ($collision && $variantIndex < 17576);

                if ($currentSku === $candidate) {
                    $stats['products_already_ok']++;
                    continue;
                }

                $changes[] = [$product->id, $currentSku ?: '(empty)', $candidate];

                try {
                    $product->legacy_sku = $product->legacy_sku ?: ($currentSku ?: null);
                    $product->sku = $candidate;
                    $product->save();
                    $stats['products_renamed']++;
                } catch (\Throwable $e) {
                    $stats['products_failed']++;
                    $this->error("Product #{$product->id}: ".$e->getMessage());
                }
            }
        }

        if ($dryRun) {
            \Illuminate\Support\Facades\DB::rollBack();
        } else {
            \Illuminate\Support\Facades\DB::commit();
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        $this->error('Aborted: '.$e->getMessage());

        return 1;
    }

    $this->newLine();
    $this->info($dryRun ? 'Dry-run summary (no rows committed):' : 'Migration summary:');
    $this->table(
        ['Metric', 'Value'],
        [
            ['Families processed', (string) $stats['families_processed']],
            ['Families skipped (empty)', (string) $stats['families_skipped']],
            ['Products renamed', (string) $stats['products_renamed']],
            ['Products already OK', (string) $stats['products_already_ok']],
            ['Products failed', (string) $stats['products_failed']],
        ]
    );

    if ($changes !== []) {
        $this->newLine();
        $this->line($dryRun ? 'Sample of planned renames:' : 'Sample of applied renames:');
        $this->table(
            ['Product ID', 'Old SKU', 'New SKU'],
            array_slice($changes, 0, 30)
        );

        if (count($changes) > 30) {
            $this->line('... '.(count($changes) - 30).' more rows not shown.');
        }
    }

    return $stats['products_failed'] === 0 ? 0 : 1;
})->purpose('Rewrite every sellable product SKU to the unified {DEPT}-{BRAND}-{FFFFF}{V} scheme.');

/**
 * One-shot rebuild that makes the catalogue the single source of truth for
 * SKU codes (Option B). Walks the catalogue first, assigns codes there, then
 * rebuilds every retail product SKU on top — linked products inherit the
 * catalogue's code; unlinked products get fresh codes in the same
 * (dept, brand_code) namespace so the two never collide.
 *
 * Old codes are preserved on brand_catalogue_skus.legacy_sku_code and
 * products.legacy_sku, so the migration is reversible.
 *
 * Options:
 *   --dry-run        : rolls back at the end so you can review the plan.
 *   --skip-empty-codes : skip writing empty/null catalogue codes onto retail.
 */
Artisan::command('sku:migrate-catalogue-codes {--dry-run}', function () {
    /** @var \App\Services\SkuCodeAllocator $allocator */
    $allocator = app(\App\Services\SkuCodeAllocator::class);

    $dryRun = (bool) $this->option('dry-run');

    $stats = [
        'cat_brands_coded'        => 0,
        'cat_styles_seq_assigned' => 0,
        'cat_skus_recoded'        => 0,
        'retail_skus_cleared'     => 0,
        'retail_families_aligned' => 0,
        'retail_families_reseq'   => 0,
        'retail_products_recoded' => 0,
        'retail_products_unmatched' => 0,
    ];

    \Illuminate\Support\Facades\DB::beginTransaction();

    try {
        $this->line('Phase A — Ensuring every catalogue brand has a unified sku_code...');
        foreach (\App\Models\BrandCatalogueBrand::query()->cursor() as $brand) {
            $before = $brand->sku_code;
            $allocator->ensureCatalogueBrandCode($brand);
            if ($brand->sku_code !== $before) {
                $stats['cat_brands_coded']++;
            }
        }

        $this->line('Phase B — Resetting and re-allocating catalogue style sequences...');
        \App\Models\BrandCatalogueStyle::query()->update(['sku_family_seq' => null]);

        // Group styles by catalogue brand so the seq counter is per-brand,
        // deterministic by id. Cross-namespace lookups now consult retail
        // too, but at this stage retail still has the OLD (now-stale) seqs,
        // so we explicitly use the catalogue-only counter for Phase B.
        $brandIds = \App\Models\BrandCatalogueStyle::query()
            ->distinct()
            ->orderBy('brand_catalogue_brand_id')
            ->pluck('brand_catalogue_brand_id');

        foreach ($brandIds as $brandId) {
            $seq = 1;
            \App\Models\BrandCatalogueStyle::query()
                ->where('brand_catalogue_brand_id', $brandId)
                ->orderBy('id')
                ->get()
                ->each(function ($style) use (&$seq, &$stats) {
                    $style->sku_family_seq = $seq++;
                    $style->save();
                    $stats['cat_styles_seq_assigned']++;
                });
        }

        $this->line('Phase C — Re-issuing every catalogue SKU code...');
        $catStyles = \App\Models\BrandCatalogueStyle::query()
            ->with(['brand.catalogue', 'skus' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('id')
            ->get();

        foreach ($catStyles as $style) {
            if ($style->skus->isEmpty()) {
                continue;
            }

            try {
                $prefix = $allocator->ensureCatalogueStylePrefix($style);
            } catch (\Throwable $e) {
                $this->warn("  Skipping style #{$style->id}: ".$e->getMessage());
                continue;
            }

            $i = 1;
            foreach ($style->skus as $catSku) {
                $newCode = $prefix.$allocator->indexToLetters($i++);
                if ($catSku->sku_code !== $newCode) {
                    $catSku->legacy_sku_code = $catSku->legacy_sku_code ?: ($catSku->sku_code ?: null);
                    $catSku->sku_code = $newCode;
                    $catSku->save();
                    $stats['cat_skus_recoded']++;
                }
            }
        }

        $this->line('Phase D — Clearing all retail product SKUs (saved to legacy_sku)...');
        // Preserve old SKU in legacy_sku, then null out the live column so
        // the unique index has free space for Phase F. We only overwrite
        // legacy_sku when it is still null (so the original pre-unified
        // code is the one preserved).
        \App\Models\Product::query()
            ->whereNotNull('sku')
            ->where(function ($q) {
                $q->whereNull('legacy_sku');
            })
            ->update(['legacy_sku' => \Illuminate\Support\Facades\DB::raw('sku')]);

        $stats['retail_skus_cleared'] = (int) \App\Models\Product::query()
            ->whereNotNull('sku')
            ->update(['sku' => null]);

        $this->line('Phase E — Aligning linked retail families with their catalogue style seq...');
        \App\Models\ProductFamily::query()->update(['sku_family_seq' => null]);

        // Linked families inherit the catalogue's seq.
        $linkedFamilies = \App\Models\ProductFamily::query()
            ->whereNotNull('brand_catalogue_style_id')
            ->get();
        foreach ($linkedFamilies as $family) {
            $catSeq = \App\Models\BrandCatalogueStyle::query()
                ->where('id', $family->brand_catalogue_style_id)
                ->value('sku_family_seq');
            if ($catSeq) {
                $family->sku_family_seq = (int) $catSeq;
                $family->save();
                $stats['retail_families_aligned']++;
            }
        }

        $this->line('Phase F — Re-allocating unlinked retail family sequences...');
        $unlinkedFamilies = \App\Models\ProductFamily::query()
            ->with('brand')
            ->whereNull('sku_family_seq')
            ->orderBy('root_catalogue_name')
            ->orderBy('brand_id')
            ->orderBy('id')
            ->get();
        foreach ($unlinkedFamilies as $family) {
            // Ensure the retail brand has a sku_code first so cross-namespace
            // lookup against catalogue styles works correctly.
            if ($family->brand) {
                $allocator->ensureBrandCode($family->brand);
            }
            $allocator->ensureFamilySeq($family);
            $stats['retail_families_reseq']++;
        }

        $this->line('Phase G — Re-issuing every retail product SKU (linked inherit, unlinked allocate)...');
        // Process families one at a time. Within a family:
        //   1. Linked products first → inherit their catalogue SKU's code,
        //      and we record which prefix+letter slots they occupy.
        //   2. Unlinked products → fresh allocator, but skip letters the
        //      linked siblings have already claimed.
        //
        // This avoids the case where products in the same family collide
        // because the catalogue side claims letters A, B and the retail
        // allocator also starts at A.
        $familyIds = \App\Models\Product::query()
            ->select('product_family_id')
            ->distinct()
            ->orderBy('product_family_id')
            ->pluck('product_family_id');

        foreach ($familyIds as $familyId) {
            $family = \App\Models\ProductFamily::with('brand')->find($familyId);
            if ($family === null) continue;

            try {
                $prefix = $allocator->ensureFamilyPrefix($family);
            } catch (\Throwable $e) {
                $prefix = null;
            }

            $familyProducts = \App\Models\Product::query()
                ->with('catalogueSku')
                ->where('product_family_id', $familyId)
                ->orderByRaw('brand_catalogue_sku_id IS NULL ASC') // linked first
                ->orderBy('id')
                ->get();

            $takenLetters = [];

            // First pass: linked products inherit catalogue codes.
            foreach ($familyProducts as $product) {
                if ($product->brand_catalogue_sku_id && $product->catalogueSku?->sku_code) {
                    $newSku = $product->catalogueSku->sku_code;
                    $product->sku = $newSku;
                    $product->save();
                    $stats['retail_products_recoded']++;

                    if ($prefix && str_starts_with($newSku, $prefix)) {
                        $suffix = substr($newSku, strlen($prefix));
                        if ($suffix !== '' && preg_match('/^[A-Z]+$/', $suffix)) {
                            $takenLetters[$suffix] = true;
                        }
                    }
                }
            }

            // Second pass: unlinked products get fresh letters, skipping
            // the ones already taken by linked siblings.
            if ($prefix === null) {
                // No prefix available; skip unlinked.
                $stats['retail_products_unmatched'] += $familyProducts
                    ->whereNull('brand_catalogue_sku_id')
                    ->count();
                continue;
            }

            $variantIndex = 1;
            foreach ($familyProducts as $product) {
                if ($product->brand_catalogue_sku_id && $product->catalogueSku?->sku_code) {
                    continue; // already processed
                }

                do {
                    $letter = $allocator->indexToLetters($variantIndex);
                    $variantIndex++;
                } while (isset($takenLetters[$letter]) && $variantIndex < 17576);

                if (isset($takenLetters[$letter])) {
                    $stats['retail_products_unmatched']++;
                    continue;
                }

                $candidate = $prefix.$letter;

                // Final guard: make sure no OTHER product in the database has
                // this exact SKU (shouldn't happen given the per-family logic,
                // but checks against any cross-family edge cases).
                $exists = \App\Models\Product::query()
                    ->where('sku', $candidate)
                    ->where('id', '!=', $product->id)
                    ->exists();

                if ($exists) {
                    // Keep searching letters
                    while ($exists && $variantIndex < 17576) {
                        $letter = $allocator->indexToLetters($variantIndex++);
                        if (isset($takenLetters[$letter])) continue;
                        $candidate = $prefix.$letter;
                        $exists = \App\Models\Product::query()
                            ->where('sku', $candidate)
                            ->where('id', '!=', $product->id)
                            ->exists();
                    }
                }

                if ($exists) {
                    $stats['retail_products_unmatched']++;
                    continue;
                }

                $product->sku = $candidate;
                $product->save();
                $stats['retail_products_recoded']++;
                $takenLetters[$letter] = true;
            }
        }

        if ($dryRun) {
            \Illuminate\Support\Facades\DB::rollBack();
        } else {
            \Illuminate\Support\Facades\DB::commit();
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        $this->error('Aborted: '.$e->getMessage());

        return 1;
    }

    $this->newLine();
    $this->info($dryRun ? 'Dry-run summary (no rows committed):' : 'Catalogue migration summary:');
    $this->table(
        ['Metric', 'Value'],
        [
            ['Catalogue brands auto-coded',    (string) $stats['cat_brands_coded']],
            ['Catalogue styles seq-assigned',  (string) $stats['cat_styles_seq_assigned']],
            ['Catalogue SKUs re-coded',        (string) $stats['cat_skus_recoded']],
            ['Retail SKUs cleared',            (string) $stats['retail_skus_cleared']],
            ['Retail families aligned (linked)', (string) $stats['retail_families_aligned']],
            ['Retail families re-seq (unlinked)',(string) $stats['retail_families_reseq']],
            ['Retail products re-coded',       (string) $stats['retail_products_recoded']],
            ['Retail products unmatched',      (string) $stats['retail_products_unmatched']],
        ]
    );

    return $stats['retail_products_unmatched'] === 0 ? 0 : 1;
})->purpose('Rebuild the unified SKU scheme with the catalogue as the source of truth.');
