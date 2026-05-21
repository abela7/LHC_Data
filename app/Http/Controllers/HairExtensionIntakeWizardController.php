<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueStyle;
use App\Models\IntakeSession;
use App\Models\IntakeSessionAiCall;
use App\Models\IntakeSessionVariant;
use App\Models\IntakeSessionVariantPhoto;
use App\Models\InventoryLocation;
use App\Models\InventorySection;
use App\Models\InventorySubsection;
use App\Services\HairIntakeAiService;
use App\Services\HairIntakeBarcodeService;
use App\Services\HairIntakeCatalogueMatcher;
use App\Services\HairIntakePublishService;
use App\Services\HairIntakeReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class HairExtensionIntakeWizardController extends Controller
{
    private const MAX_PHOTO_KB = 35840;

    private const PHOTO_ROLES = [
        'family_main',
        'variant_front',
        'barcode',
        'back',
        'label_ingredients',
        'shelf_context',
        'gallery',
    ];

    public function index(): View
    {
        return view('hair-extension-intake.wizard', $this->pageData());
    }

    public function sessions(Request $request): View
    {
        $submittedStatuses = ['awaiting_match', 'match_failed'];
        $draftStatuses = ['draft', 'match_accepted', 'filling_variants', 'awaiting_review', 'review_returned', 'approved'];
        $search = $this->nullTrim($request->query('q'));
        $brandId = $request->integer('brand_id') ?: null;

        $submitted = $this->sessionSummaryQuery($submittedStatuses, $search, $brandId)
            ->paginate(24, ['*'], 'submitted_page')
            ->withQueryString();

        $drafts = $this->sessionSummaryQuery($draftStatuses, $search, $brandId)
            ->paginate(24, ['*'], 'draft_page')
            ->withQueryString();

        $submitted->getCollection()->transform(fn (IntakeSession $session): IntakeSession => $this->decorateSessionSummary($session));
        $drafts->getCollection()->transform(fn (IntakeSession $session): IntakeSession => $this->decorateSessionSummary($session));

        return view('hair-extension-intake.wizard-sessions', [
            'submitted' => $submitted,
            'drafts' => $drafts,
            'search' => $search,
            'brandId' => $brandId,
            'brands' => BrandCatalogueBrand::query()
                ->whereIn('id', $this->sessionBrandIds())
                ->orderBy('name')
                ->get(['id', 'name']),
            'counts' => [
                'submitted' => $this->sessionSummaryQuery($submittedStatuses, $search, $brandId)->count(),
                'drafts' => $this->sessionSummaryQuery($draftStatuses, $search, $brandId)->count(),
            ],
        ]);
    }

    public function show(IntakeSession $session): View
    {
        $this->authorizeSession($session);

        return view('hair-extension-intake.wizard', $this->pageData($session));
    }

    public function load(IntakeSession $session): JsonResponse
    {
        $this->authorizeSession($session);

        return response()->json([
            'session' => $this->sessionPayload($session),
            'reference' => $this->referencePayload(),
        ]);
    }

    public function destroy(IntakeSession $session)
    {
        $this->authorizeSession($session);

        if ($session->status === 'published') {
            return back()->with('error', 'Published sessions cannot be deleted from the intake manager.');
        }

        $session->load('variants.photos');
        if ($session->photo_disk && $session->photo_path) {
            Storage::disk($session->photo_disk)->delete($session->photo_path);
        }

        foreach ($session->variants as $variant) {
            foreach ($variant->photos as $photo) {
                Storage::disk($photo->storage_disk)->delete($photo->storage_path);
            }
        }

        $session->delete();

        return redirect()
            ->route('hair-extension-intake.wizard.sessions')
            ->with('status', 'Intake session deleted.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_catalogue_brand_id' => ['required', 'integer', 'exists:brand_catalogue_brands,id'],
            'photo' => ['required', 'image', 'max:'.self::MAX_PHOTO_KB],
            'style_name_hint' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'array'],
            'observations.main' => ['nullable', 'array'],
            'observations.main.*' => ['nullable', 'string', 'max:255'],
            'observations.sub' => ['nullable', 'array'],
            'observations.sub.*' => ['nullable', 'string', 'max:255'],
            'observations.common' => ['nullable', 'array'],
            'observations.common.*' => ['nullable', 'string', 'max:255'],
            'observations.axes' => ['nullable', 'array'],
            'observations.axes.main' => ['nullable', 'string', 'max:80'],
            'observations.axes.sub' => ['nullable', 'string', 'max:80'],
            'observations.axes.common' => ['nullable', 'string', 'max:80'],
            'user_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $session = IntakeSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'user_id' => Auth::id(),
            'brand_catalogue_brand_id' => (int) $data['brand_catalogue_brand_id'],
            'style_name_hint' => $this->nullTrim($data['style_name_hint'] ?? null),
            'observations_json' => $this->cleanObservations($data['observations'] ?? []),
            'user_note' => $this->nullTrim($data['user_note'] ?? null),
            'status' => 'awaiting_match',
            'current_step' => 2,
        ]);

        $this->storeSessionPhoto($session, $request->file('photo'));

        return response()->json([
            'message' => 'Session saved. Tell Codex: Check the new product.',
            'session' => $this->sessionPayload($session->fresh()),
            'redirect_url' => route('hair-extension-intake.wizard.show', $session),
        ]);
    }

    public function update(Request $request, IntakeSession $session, HairIntakeCatalogueMatcher $matcher): JsonResponse
    {
        $this->authorizeSession($session);

        $action = (string) $request->input('action');

        if ($action === 'accept_match') {
            $data = $request->validate([
                'matched_style_id' => ['required', 'integer', 'exists:brand_catalogue_styles,id'],
                'selected_sku_ids' => ['nullable', 'array'],
                'selected_sku_ids.*' => ['integer', 'exists:brand_catalogue_skus,id'],
                'family_groups' => ['nullable', 'array', 'max:40'],
                'family_groups.*.name' => ['nullable', 'string', 'max:255'],
                'family_groups.*.scope' => ['nullable', 'array'],
                'family_groups.*.sku_ids' => ['required_with:family_groups', 'array', 'min:1'],
                'family_groups.*.sku_ids.*' => ['integer', 'exists:brand_catalogue_skus,id'],
            ]);

            $style = BrandCatalogueStyle::query()
                ->where('brand_catalogue_brand_id', $session->brand_catalogue_brand_id)
                ->with('skus.optionValues.variant')
                ->findOrFail((int) $data['matched_style_id']);

            $selectedSkuIds = collect($data['selected_sku_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($selectedSkuIds !== []) {
                $styleSkuIds = $style->skus->pluck('id')->map(fn (mixed $id): int => (int) $id);
                $invalidIds = collect($selectedSkuIds)
                    ->reject(fn (int $id): bool => $styleSkuIds->contains($id))
                    ->values();

                if ($invalidIds->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'selected_sku_ids' => 'Selected variants must belong to the matched catalogue style.',
                    ]);
                }
            }

            $familyGroups = $this->cleanFamilyGroups($data['family_groups'] ?? [], $style);
            if ($familyGroups !== []) {
                $selectedSkuIds = collect($familyGroups)
                    ->flatMap(fn (array $group): array => $group['sku_ids'])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            }

            DB::transaction(function () use ($session, $style, $matcher, $selectedSkuIds, $familyGroups): void {
                $session->variants()->delete();
                $session->familyGroups()->delete();
                $session->update([
                    'matched_style_id' => $style->id,
                    'status' => 'match_accepted',
                    'current_step' => 3,
                ]);

                if ($familyGroups !== []) {
                    $matcher->createSessionFamilyGroups($session, $style, $familyGroups);
                } else {
                    $matcher->createSessionVariants($session, $style, $selectedSkuIds);
                }
            });

            return response()->json([
                'message' => 'Match accepted.',
                'session' => $this->sessionPayload($session->fresh()),
            ]);
        }

        if ($action === 'wrong_match') {
            $session->variants()->delete();

            $session->update([
                'matched_style_id' => null,
                'status' => 'draft',
                'current_step' => 2,
            ]);

            return response()->json([
                'message' => 'Match cleared.',
                'session' => $this->sessionPayload($session->fresh()),
            ]);
        }

        $data = $request->validate([
            'current_step' => ['nullable', 'integer', 'min:1', 'max:7'],
            'status' => ['nullable', Rule::in([
                'draft',
                'awaiting_match',
                'match_failed',
                'match_accepted',
                'filling_variants',
                'awaiting_review',
                'review_returned',
                'approved',
                'published',
                'abandoned',
            ])],
        ]);

        $session->update(array_filter([
            'current_step' => $data['current_step'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($value) => $value !== null));

        return response()->json([
            'message' => 'Session updated.',
            'session' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function match(
        IntakeSession $session,
        HairIntakeAiService $ai,
        HairIntakeCatalogueMatcher $matcher,
    ): JsonResponse {
        $this->authorizeSession($session);

        $requestPayload = $this->matchCallPayload($session, $matcher);
        $call = $this->createAiCall($session, 'match', $requestPayload);
        $call->update([
            'status' => 'error',
            'error_message' => 'External OpenAI matching is disabled. Codex/local review must write the match result for this session.',
            'latency_ms' => 0,
        ]);
        $session->update(['status' => 'awaiting_match', 'current_step' => 2]);

        return response()->json([
            'message' => 'External OpenAI matching is disabled. This intake is waiting for Codex/local catalogue matching.',
            'session' => $this->sessionPayload($session->fresh()),
        ], 422);
    }

    public function upsertVariant(Request $request, IntakeSession $session): JsonResponse
    {
        $this->authorizeSession($session);

        $data = $request->validate([
            'variant_id' => ['nullable', 'integer', 'exists:intake_session_variants,id'],
            'manually_added' => ['nullable', 'boolean'],
            'main_value' => ['nullable', 'string', 'max:255'],
            'sub_value' => ['nullable', 'string', 'max:255'],
            'common_value' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'barcode_source' => ['nullable', Rule::in(['scanned', 'generated_lhc', 'manual_typed'])],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'store_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'section_id' => ['nullable', 'integer', 'exists:inventory_sections,id'],
            'subsection_id' => ['nullable', 'integer', 'exists:inventory_subsections,id'],
            'status' => ['nullable', Rule::in(['empty', 'partial', 'complete', 'not_in_shop'])],
        ]);

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = $session->variants()->findOrFail((int) $data['variant_id']);
        }

        if (! $variant) {
            $axes = [
                'main' => $this->nullTrim($data['main_value'] ?? null),
                'sub' => $this->nullTrim($data['sub_value'] ?? null),
                'common' => $this->nullTrim($data['common_value'] ?? null),
            ];

            $variant = $session->variants()->create([
                'manually_added' => true,
                'manual_axes_json' => $axes,
                'display_name' => $this->displayNameFromAxes($axes, $data['display_name'] ?? null),
                'main_value' => $axes['main'],
                'sub_value' => $axes['sub'],
                'common_value' => $axes['common'],
                'status' => 'empty',
            ]);
        }

        if (array_key_exists('barcode', $data) && filled($data['barcode'])) {
            $duplicate = $session->variants()
                ->whereKeyNot($variant->id)
                ->where('barcode', trim((string) $data['barcode']))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'barcode' => 'This barcode is already used in this intake session.',
                ]);
            }
        }

        $updates = [];
        $isCatalogueVariant = ! $variant->manually_added && filled($variant->brand_catalogue_sku_id);

        if (! $isCatalogueVariant) {
            foreach (['display_name', 'main_value', 'sub_value', 'common_value'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $this->nullTrim($data[$field]);
                }
            }

            if (array_key_exists('main_value', $updates) || array_key_exists('sub_value', $updates) || array_key_exists('common_value', $updates)) {
                $updates['manual_axes_json'] = [
                    'main' => $updates['main_value'] ?? $variant->main_value,
                    'sub' => $updates['sub_value'] ?? $variant->sub_value,
                    'common' => $updates['common_value'] ?? $variant->common_value,
                ];
            }
        }

        if (array_key_exists('barcode', $data)) {
            $updates['barcode'] = $this->nullTrim($data['barcode']);
            if ($updates['barcode'] === null && ! array_key_exists('barcode_source', $data)) {
                $updates['barcode_source'] = null;
            }
        }

        if (array_key_exists('barcode_source', $data)) {
            $updates['barcode_source'] = $this->nullTrim($data['barcode_source']);
        }

        if (array_key_exists('price', $data)) {
            $updates['price'] = $data['price'];
        }

        if (array_key_exists('currency', $data)) {
            $currency = $this->nullTrim($data['currency']);
            $updates['currency'] = $currency ? strtoupper($currency) : null;
        }

        foreach (['store_id', 'section_id', 'subsection_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        $variant->fill($updates);

        $variant->status = ($data['status'] ?? null) === 'not_in_shop'
            ? 'not_in_shop'
            : $this->variantStatus($variant);
        $variant->save();

        if ($session->status === 'match_accepted') {
            $session->update(['status' => 'filling_variants', 'current_step' => max(4, (int) $session->current_step)]);
        }

        return response()->json([
            'message' => 'Variant saved.',
            'session' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function generateBarcode(IntakeSession $session, HairIntakeBarcodeService $barcodes): JsonResponse
    {
        $this->authorizeSession($session);

        return response()->json([
            'barcode' => $barcodes->generate(),
            'barcode_source' => 'generated_lhc',
        ]);
    }

    public function markUnfilledNotInShop(IntakeSession $session): JsonResponse
    {
        $this->authorizeSession($session);

        $session->variants()
            ->whereIn('status', ['empty', 'partial'])
            ->update(['status' => 'not_in_shop']);

        return response()->json([
            'message' => 'Unfilled variants marked as not in shop.',
            'session' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function storePhoto(Request $request, IntakeSession $session, IntakeSessionVariant $variant): JsonResponse
    {
        $this->authorizeSession($session);
        abort_unless((int) $variant->intake_session_id === (int) $session->id, 404);

        $data = $request->validate([
            'role' => ['required', Rule::in(self::PHOTO_ROLES)],
            'photo' => ['required', 'image', 'max:'.self::MAX_PHOTO_KB],
        ]);

        $role = (string) $data['role'];
        if ($role !== 'gallery') {
            $scope = $role === 'family_main'
                ? $session->variants()->with('photos')->get()->flatMap->photos->where('role', 'family_main')
                : $variant->photos()->where('role', $role)->get();

            foreach ($scope as $photo) {
                Storage::disk($photo->storage_disk)->delete($photo->storage_path);
                $photo->delete();
            }
        }

        $file = $request->file('photo');
        $directory = 'hair-intake-wizard/'.$session->session_uuid.'/'.$variant->id;
        $filename = Str::slug($role.' '.$variant->display_name).'_'.Str::random(8).'.'.($file->guessExtension() ?: 'jpg');
        $path = $file->storeAs($directory, $filename, 'public');

        $photo = $variant->photos()->create([
            'role' => $role,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName() ?: $filename,
            'mime_type' => Storage::disk('public')->mimeType($path) ?: $file->getClientMimeType(),
            'file_size' => Storage::disk('public')->size($path),
            'sort_order' => ((int) $variant->photos()->where('role', $role)->max('sort_order')) + 1,
        ]);

        $variant->status = $this->variantStatus($variant->fresh(['photos']));
        $variant->save();

        return response()->json([
            'message' => 'Photo uploaded.',
            'photo' => $this->photoPayload($photo),
            'session' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function destroyPhoto(IntakeSession $session, IntakeSessionVariant $variant, IntakeSessionVariantPhoto $photo): JsonResponse
    {
        $this->authorizeSession($session);
        abort_unless((int) $variant->intake_session_id === (int) $session->id && (int) $photo->intake_session_variant_id === (int) $variant->id, 404);

        Storage::disk($photo->storage_disk)->delete($photo->storage_path);
        $photo->delete();
        $variant->status = $this->variantStatus($variant->fresh(['photos']));
        $variant->save();

        return response()->json([
            'message' => 'Photo removed.',
            'session' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function review(IntakeSession $session, HairIntakeReviewService $reviewer): JsonResponse
    {
        $this->authorizeSession($session);

        $precheck = $reviewer->review($session);
        if (! ($precheck['ready_to_publish'] ?? false)) {
            $session->update(['status' => 'review_returned', 'current_step' => 6]);

            return response()->json([
                'message' => 'Fix blocker issues before final review.',
                'review' => $precheck,
                'session' => $this->sessionPayload($session->fresh()),
            ], 422);
        }

        $payload = $reviewer->call2Payload($session);
        $call = $this->createAiCall($session, 'review', $payload);
        $session->update(['status' => 'awaiting_review', 'current_step' => 5]);
        $review = $reviewer->review($session);

        $call->update([
            'response_json' => json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'status' => 'success',
            'latency_ms' => 0,
        ]);
        $session->update(['status' => 'review_returned', 'current_step' => 6]);

        return response()->json([
            'message' => 'Final review checks returned.',
            'review' => $review,
            'session' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function localReview(IntakeSession $session, HairIntakeReviewService $reviewer): JsonResponse
    {
        $this->authorizeSession($session);

        return response()->json([
            'review' => $reviewer->review($session),
            'session' => $this->sessionPayload($session),
        ]);
    }

    public function publish(IntakeSession $session, HairIntakePublishService $publisher): JsonResponse
    {
        $this->authorizeSession($session);

        try {
            $family = $publisher->publish($session);

            return response()->json([
                'message' => 'Family published.',
                'family_url' => route('retail-products.families.show', $family),
                'session' => $this->sessionPayload($session->fresh()),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'session' => $this->sessionPayload($session->fresh()),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(?IntakeSession $session = null): array
    {
        return [
            'session' => $session ? $this->sessionPayload($session) : null,
            'reference' => $this->referencePayload(),
            'routes' => [
                'index' => route('hair-extension-intake.wizard.index'),
                'store' => route('hair-extension-intake.wizard.store'),
                'sessions' => route('hair-extension-intake.wizard.sessions'),
                'v2' => route('hair-extension-intake.v2'),
            ],
        ];
    }

    private function sessionSummaryQuery(array $statuses, ?string $search, ?int $brandId)
    {
        return IntakeSession::query()
            ->with(['brand', 'matchedStyle'])
            ->withCount([
                'variants',
                'variants as complete_variants_count' => fn ($query) => $query->where('status', 'complete'),
                'variants as partial_variants_count' => fn ($query) => $query->where('status', 'partial'),
                'variants as not_in_shop_variants_count' => fn ($query) => $query->where('status', 'not_in_shop'),
            ])
            ->when(Auth::id(), fn ($query) => $query->where('user_id', Auth::id()), fn ($query) => $query->whereNull('user_id'))
            ->whereIn('status', $statuses)
            ->when($brandId, fn ($query) => $query->where('brand_catalogue_brand_id', $brandId))
            ->when($search, function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(function ($query) use ($like): void {
                    $query
                        ->where('session_uuid', 'like', $like)
                        ->orWhere('style_name_hint', 'like', $like)
                        ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', $like))
                        ->orWhereHas('matchedStyle', fn ($styleQuery) => $styleQuery->where('name', 'like', $like));
                });
            })
            ->latest('updated_at');
    }

    private function decorateSessionSummary(IntakeSession $session): IntakeSession
    {
        $session->setAttribute('summary_name', $session->matchedStyle?->name ?: ($session->style_name_hint ?: 'Unmatched product'));
        $session->setAttribute('photo_url', $session->photo_disk && $session->photo_path
            ? Storage::disk($session->photo_disk)->url($session->photo_path)
            : null);

        return $session;
    }

    private function sessionBrandIds()
    {
        return IntakeSession::query()
            ->when(Auth::id(), fn ($query) => $query->where('user_id', Auth::id()), fn ($query) => $query->whereNull('user_id'))
            ->whereNotNull('brand_catalogue_brand_id')
            ->whereNotIn('status', ['published', 'abandoned'])
            ->distinct()
            ->pluck('brand_catalogue_brand_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function referencePayload(): array
    {
        $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->first();

        return [
            'brands' => BrandCatalogueBrand::query()
                ->when($catalogue, fn ($query) => $query->where('brand_catalogue_id', $catalogue->id))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (BrandCatalogueBrand $brand): array => ['id' => $brand->id, 'name' => $brand->name])
                ->values(),
            'stores' => InventoryLocation::query()
                ->where('location_type', 'shop')
                ->with(['sections.subsections'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (InventoryLocation $store): array => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'sections' => $store->sections->map(fn (InventorySection $section): array => [
                        'id' => $section->id,
                        'name' => $section->name,
                        'subsections' => $section->subsections->map(fn (InventorySubsection $subsection): array => [
                            'id' => $subsection->id,
                            'name' => $subsection->name,
                        ])->values(),
                    ])->values(),
                ])
                ->values(),
            'photo_roles' => self::PHOTO_ROLES,
        ];
    }

    /**
     * @return array<int, array{name:string, scope:array<string, mixed>, sku_ids:array<int, int>}>
     */
    private function cleanFamilyGroups(array $groups, BrandCatalogueStyle $style): array
    {
        if ($groups === []) {
            return [];
        }

        $styleSkuIds = $style->skus->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $seenSkuIds = [];

        return collect($groups)
            ->map(function (array $group, int $index) use ($styleSkuIds, &$seenSkuIds): array {
                $skuIds = collect($group['sku_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->unique()
                    ->values();

                $invalidIds = $skuIds
                    ->reject(fn (int $id): bool => $styleSkuIds->contains($id))
                    ->values();

                if ($invalidIds->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'family_groups' => 'Every family bucket must use variants from the matched catalogue style.',
                    ]);
                }

                $duplicates = $skuIds
                    ->filter(fn (int $id): bool => isset($seenSkuIds[$id]))
                    ->values();

                if ($duplicates->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'family_groups' => 'The same variant cannot be added to more than one family bucket.',
                    ]);
                }

                foreach ($skuIds as $id) {
                    $seenSkuIds[$id] = true;
                }

                return [
                    'name' => $this->nullTrim($group['name'] ?? null) ?: 'Family '.($index + 1),
                    'scope' => is_array($group['scope'] ?? null) ? $group['scope'] : [],
                    'sku_ids' => $skuIds->all(),
                ];
            })
            ->filter(fn (array $group): bool => $group['sku_ids'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(IntakeSession $session): array
    {
        $session->load([
            'brand',
            'matchedStyle.productType.line',
            'familyGroups',
            'variants.photos',
            'variants.familyGroup',
            'variants.catalogueSku.optionValues.variant',
            'publishedFamily',
            'codexBridgeTasks',
        ]);

        $latestMatch = $session->aiCalls()->where('call_type', 'match')->latest('call_index')->first();
        $latestReview = $session->aiCalls()->where('call_type', 'review')->latest('call_index')->first();

        return [
            'id' => $session->id,
            'uuid' => $session->session_uuid,
            'url' => route('hair-extension-intake.wizard.show', $session),
            'brand_catalogue_brand_id' => $session->brand_catalogue_brand_id,
            'brand_name' => $session->brand?->name,
            'style_name_hint' => $session->style_name_hint,
            'photo_url' => $session->photo_disk && $session->photo_path ? Storage::disk($session->photo_disk)->url($session->photo_path) : null,
            'observations' => $session->observations_json ?: ['main' => [], 'sub' => [], 'common' => []],
            'user_note' => $session->user_note,
            'matched_style_id' => $session->matched_style_id,
            'matched_style' => $session->matchedStyle ? [
                'id' => $session->matchedStyle->id,
                'name' => $session->matchedStyle->name,
                'type' => $session->matchedStyle->productType?->name,
                'line' => $session->matchedStyle->productType?->line?->name,
            ] : null,
            'status' => $session->status,
            'current_step' => $session->current_step,
            'published_family_id' => $session->published_family_id,
            'published_family_url' => $session->publishedFamily ? route('retail-products.families.show', $session->publishedFamily) : null,
            'latest_match' => $latestMatch?->response(),
            'latest_review' => $latestReview?->response(),
            'family_groups' => $session->familyGroups->map(fn ($group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'scope' => $group->scope_json,
                'sort_order' => $group->sort_order,
                'variant_count' => $session->variants->where('intake_session_family_group_id', $group->id)->count(),
                'complete_count' => $session->variants
                    ->where('intake_session_family_group_id', $group->id)
                    ->where('status', 'complete')
                    ->count(),
            ])->values(),
            'codex_bridge_tasks' => $session->codexBridgeTasks->map(fn ($task): array => [
                'uuid' => $task->task_uuid,
                'task_type' => $task->task_type,
                'status' => $task->status,
                'error_message' => $task->error_message,
                'created_at' => $task->created_at?->toIso8601String(),
                'started_at' => $task->started_at?->toIso8601String(),
                'finished_at' => $task->finished_at?->toIso8601String(),
            ])->values(),
            'variants' => $session->variants->map(fn (IntakeSessionVariant $variant): array => [
                'id' => $variant->id,
                'family_group_id' => $variant->intake_session_family_group_id,
                'family_group_name' => $variant->familyGroup?->name,
                'brand_catalogue_sku_id' => $variant->brand_catalogue_sku_id,
                'manually_added' => (bool) $variant->manually_added,
                'manual_axes' => $variant->manual_axes_json,
                'display_name' => $variant->display_name,
                'main_value' => $variant->main_value,
                'sub_value' => $variant->sub_value,
                'common_value' => $variant->common_value,
                'barcode' => $variant->barcode,
                'barcode_source' => $variant->barcode_source,
                'price' => $variant->price,
                'currency' => $variant->currency ?: 'GBP',
                'store_id' => $variant->store_id,
                'section_id' => $variant->section_id,
                'subsection_id' => $variant->subsection_id,
                'status' => $variant->status,
                'photos' => $variant->photos->map(fn (IntakeSessionVariantPhoto $photo): array => $this->photoPayload($photo))->values(),
            ])->values(),
        ];
    }

    private function createAiCall(IntakeSession $session, string $type, array $payload): IntakeSessionAiCall
    {
        $index = ((int) $session->aiCalls()->where('call_type', $type)->max('call_index')) + 1;

        return $session->aiCalls()->create([
            'call_type' => $type,
            'call_index' => $index,
            'request_json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
        ]);
    }

    private function matchCallPayload(IntakeSession $session, HairIntakeCatalogueMatcher $matcher): array
    {
        $brand = $session->brand()->firstOrFail();

        return [
            'submission_id' => $session->session_uuid,
            'call_type' => 'match',
            'brand_name' => $brand->name,
            'style_name_hint' => $session->style_name_hint,
            'observations' => $session->observations_json ?: ['main' => [], 'sub' => [], 'common' => []],
            'user_note' => $session->user_note,
            'imported_catalogue_scope' => ['brand_id' => $brand->id],
            'candidate_count' => count($matcher->shortlistedStyles($brand, $session->style_name_hint, $session->observations_json ?: [])),
        ];
    }

    private function storeSessionPhoto(IntakeSession $session, mixed $file): void
    {
        $directory = 'hair-intake-wizard/'.$session->session_uuid.'/match';
        $filename = 'match_'.Str::random(10).'.'.($file->guessExtension() ?: 'jpg');
        $path = $file->storeAs($directory, $filename, 'public');

        $session->update([
            'photo_disk' => 'public',
            'photo_path' => $path,
            'photo_original_filename' => $file->getClientOriginalName() ?: $filename,
            'photo_mime_type' => Storage::disk('public')->mimeType($path) ?: $file->getClientMimeType(),
            'photo_file_size' => Storage::disk('public')->size($path),
        ]);
    }

    private function variantStatus(IntakeSessionVariant $variant): string
    {
        if ($variant->status === 'not_in_shop') {
            return 'not_in_shop';
        }

        $variant->loadMissing('photos');
        $required = filled($variant->barcode)
            && $variant->price !== null
            && filled($variant->currency)
            && filled($variant->store_id)
            && filled($variant->section_id)
            && $variant->photos->contains(fn ($photo): bool => $photo->role === 'variant_front');

        if ($required) {
            return 'complete';
        }

        $touched = filled($variant->barcode)
            || $variant->price !== null
            || filled($variant->store_id)
            || $variant->photos->isNotEmpty();

        return $touched ? 'partial' : 'empty';
    }

    private function photoPayload(IntakeSessionVariantPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'role' => $photo->role,
            'url' => $photo->displayUrl(),
            'filename' => $photo->original_filename,
            'sort_order' => $photo->sort_order,
        ];
    }

    private function displayNameFromAxes(array $axes, ?string $fallback): string
    {
        $name = $this->nullTrim($fallback);
        if ($name) {
            return $name;
        }

        return collect($axes)->filter()->implode(' / ') ?: 'Manual variant';
    }

    private function cleanObservations(array $observations): array
    {
        $clean = collect(['main', 'sub', 'common'])
            ->mapWithKeys(fn (string $key): array => [
                $key => collect($observations[$key] ?? [])
                    ->map(fn (mixed $value): string => trim((string) $value))
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->all();

        $axes = collect(['main', 'sub', 'common'])
            ->mapWithKeys(fn (string $key): array => [$key => $this->nullTrim($observations['axes'][$key] ?? null)])
            ->filter()
            ->all();

        if ($axes !== []) {
            $clean['axes'] = $axes;
        }

        return $clean;
    }

    private function nullTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function authorizeSession(IntakeSession $session): void
    {
        if ($session->user_id === null) {
            return;
        }

        abort_unless((int) $session->user_id === (int) Auth::id(), 403);
    }
}
