<?php

namespace App\Services;

use App\Models\IntakeSession;
use App\Models\IntakeSessionVariant;
use Illuminate\Support\Collection;

class HairIntakeReviewService
{
    public function __construct(private readonly HairIntakeBarcodeService $barcodeService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function review(IntakeSession $session, ?array $aiReview = null): array
    {
        $session->loadMissing(['familyGroups', 'variants.photos', 'variants.familyGroup']);
        $issues = collect($this->deterministicIssues($session));
        $warnings = collect($aiReview['issues'] ?? [])
            ->filter(fn ($issue): bool => is_array($issue))
            ->map(fn (array $issue): array => [
                'variant_id' => (int) ($issue['variant_id'] ?? 0),
                'severity' => 'warning',
                'field' => trim((string) ($issue['field'] ?? 'review')),
                'message' => trim((string) ($issue['message'] ?? 'Review warning.')),
            ])
            ->filter(fn (array $issue): bool => $issue['message'] !== '');

        $allIssues = $issues->merge($warnings)->values()->all();
        $blockers = collect($allIssues)->where('severity', 'blocker')->count();
        $stocked = $session->variants->where('status', 'complete');
        $inactive = $session->variants->where('status', 'not_in_shop');

        return [
            'submission_id' => $session->session_uuid,
            'call_type' => 'review',
            'review_status' => $blockers === 0 ? 'ready_for_approval' : 'needs_user_fix',
            'summary' => [
                'matched_style_id' => $session->matched_style_id,
                'stocked_count' => $stocked->count(),
                'inactive_count' => $inactive->count(),
                'manual_count' => $session->variants->where('manually_added', true)->count(),
                'family_count' => $session->familyGroups->count(),
                'total_in_catalogue' => $session->variants->where('manually_added', false)->count(),
                'total_in_session' => $session->variants->count(),
            ],
            'issues' => $allIssues,
            'consistency_notes' => array_values(array_filter(array_map(
                fn (mixed $note): string => trim((string) $note),
                $aiReview['consistency_notes'] ?? $this->consistencyNotes($stocked),
            ))),
            'ready_to_publish' => $blockers === 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deterministicIssues(IntakeSession $session): array
    {
        $session->loadMissing(['familyGroups', 'variants.photos', 'variants.familyGroup']);
        $issues = [];
        $completeVariants = $session->variants->where('status', 'complete')->values();

        if ($completeVariants->isEmpty()) {
            $issues[] = $this->issue(null, 'blocker', 'stocked_variants', 'No completed stocked variants are ready to publish.');
        }

        if ($session->familyGroups->isNotEmpty()) {
            foreach ($session->familyGroups as $group) {
                $groupVariants = $session->variants
                    ->where('intake_session_family_group_id', $group->id)
                    ->where('status', 'complete');

                if ($groupVariants->isEmpty()) {
                    continue;
                }

                $hasGroupMain = $groupVariants
                    ->flatMap->photos
                    ->contains(fn ($photo): bool => $photo->role === 'family_main');

                if (! $hasGroupMain) {
                    $issues[] = $this->issue(null, 'blocker', 'photos', 'Add one family_main photo for '.$group->name.'.');
                }
            }
        } else {
            $hasFamilyMain = $session->variants
                ->flatMap->photos
                ->contains(fn ($photo): bool => $photo->role === 'family_main');

            if (! $hasFamilyMain) {
                $issues[] = $this->issue(null, 'blocker', 'photos', 'Add one family_main photo before review.');
            }
        }

        $seenBarcodes = [];
        foreach ($completeVariants as $variant) {
            $variantId = (int) $variant->id;
            if (! $variant->barcode) {
                $issues[] = $this->issue($variantId, 'blocker', 'barcode', 'Missing barcode.');
            } elseif (! $this->barcodeService->isPlausible($variant->barcode)) {
                $issues[] = $this->issue($variantId, 'blocker', 'barcode', 'Barcode format is not plausible EAN/UPC or LHC internal barcode.');
            } else {
                $key = strtoupper(trim($variant->barcode));
                if (isset($seenBarcodes[$key])) {
                    $issues[] = $this->issue($variantId, 'blocker', 'barcode', 'Duplicate barcode within this intake session.');
                }
                $seenBarcodes[$key] = true;
            }

            if ($variant->price === null || (float) $variant->price <= 0) {
                $issues[] = $this->issue($variantId, 'blocker', 'price', 'Missing or invalid price.');
            }

            if (! $variant->currency) {
                $issues[] = $this->issue($variantId, 'blocker', 'currency', 'Missing currency.');
            }

            if (! $variant->store_id || ! $variant->section_id) {
                $issues[] = $this->issue($variantId, 'blocker', 'location', 'Choose store and section.');
            }

            if (! $variant->photos->contains(fn ($photo): bool => $photo->role === 'variant_front')) {
                $issues[] = $this->issue($variantId, 'blocker', 'photos', 'Missing required variant_front photo.');
            }

            if (! $variant->photos->contains(fn ($photo): bool => $photo->role === 'barcode')) {
                $issues[] = $this->issue($variantId, 'warning', 'photos', 'Barcode photo is recommended.');
            }
        }

        $prices = $completeVariants
            ->map(fn (IntakeSessionVariant $variant): ?float => $variant->price === null ? null : (float) $variant->price)
            ->filter();

        if ($prices->count() > 1 && $prices->max() > 0 && ($prices->max() / max(0.01, $prices->min())) >= 2) {
            $issues[] = $this->issue(null, 'warning', 'price', 'Stocked variant prices vary by 2x or more. Check this is intentional.');
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    public function call2Payload(IntakeSession $session): array
    {
        $session->loadMissing(['familyGroups', 'variants.photos']);

        return [
            'submission_id' => $session->session_uuid,
            'call_type' => 'review',
            'matched_style_id' => $session->matched_style_id,
            'stocked_variants' => $session->variants
                ->where('status', 'complete')
                ->map(fn (IntakeSessionVariant $variant): array => [
                    'variant_id' => $variant->id,
                    'catalogue_variant_id' => $variant->brand_catalogue_sku_id,
                    'manually_added' => (bool) $variant->manually_added,
                    'display_name' => $variant->display_name,
                    'family_group_id' => $variant->intake_session_family_group_id,
                    'family_group_name' => $variant->familyGroup?->name,
                    'barcode' => $variant->barcode,
                    'price' => $variant->price === null ? null : (float) $variant->price,
                    'currency' => $variant->currency,
                    'photos' => $variant->photos->map(fn ($photo): array => [
                        'purpose' => $photo->role,
                        'url' => $photo->displayUrl(),
                    ])->values()->all(),
                    'location' => [
                        'store_id' => $variant->store_id,
                        'section_id' => $variant->section_id,
                        'subsection_id' => $variant->subsection_id,
                    ],
                ])
                ->values()
                ->all(),
            'inactive_variants' => $session->variants
                ->where('status', 'not_in_shop')
                ->pluck('id')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, IntakeSessionVariant>  $stocked
     * @return array<int, string>
     */
    private function consistencyNotes(Collection $stocked): array
    {
        if ($stocked->isEmpty()) {
            return [];
        }

        $notes = [];
        $currencies = $stocked->pluck('currency')->filter()->unique()->values();
        if ($currencies->count() === 1) {
            $notes[] = 'All stocked variants use '.$currencies->first().' currency.';
        }

        $prices = $stocked->pluck('price')->filter()->unique()->values();
        if ($prices->count() === 1) {
            $notes[] = 'All stocked variants share the same price.';
        }

        return $notes;
    }

    private function issue(?int $variantId, string $severity, string $field, string $message): array
    {
        return [
            'variant_id' => $variantId,
            'severity' => $severity,
            'field' => $field,
            'message' => $message,
        ];
    }
}
