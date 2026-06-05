<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategoryAssignment;
use App\Models\ProductEcommerceProfile;
use App\Models\ProductFamily;
use App\Models\ProductMedia;
use App\Models\ProductSource;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Absorb (merge) one product family into another, de-duplicating by
 * Length + Colour and keeping the richer record (barcode/photos win).
 *
 * Both families are expected to already use the role model (Length=main,
 * Colour=sub-main, Bundle=common). Each source SKU is mapped onto the target's
 * groups by name, photos/prices/barcodes are preserved, and the emptied source
 * family is deleted.
 *
 *   php artisan retail:absorb-family 12107 12110            # DRY RUN (default)
 *   php artisan retail:absorb-family 12107 12110 --execute  # perform it
 *
 * De-dup rule for a Length+Colour that already exists in the target:
 *   - incoming is empty (no barcode, no photo) -> SKIP it (drop the redundant empty)
 *   - existing is empty, incoming has data       -> REPLACE (move incoming, delete the empty)
 *   - both have data                             -> KEEP BOTH and report (never auto-delete real data)
 */
class AbsorbFamilyCommand extends Command
{
    protected $signature = 'retail:absorb-family
        {target : family id to KEEP}
        {source : family id to absorb into the target, then delete}
        {--execute : perform it (default is a dry run that changes nothing)}';

    protected $description = 'Merge one product family into another (dedup by Length+Colour, keep barcode/photos), then delete the source.';

    /** @var array<string, array{action: string, key: string, length: ?string, colour: ?string, incomingEmpty: bool, existingId: ?int}> */
    private array $decisions = [];

    public function handle(): int
    {
        $targetId = (int) $this->argument('target');
        $sourceId = (int) $this->argument('source');

        if ($targetId === $sourceId) {
            $this->error('Target and source must be different families.');

            return self::FAILURE;
        }

        $load = ['variantGroups.options', 'products.variantValues.option', 'products.variantValues.group', 'products.media', 'products.price', 'media'];
        $target = ProductFamily::with($load)->find($targetId);
        $source = ProductFamily::with($load)->find($sourceId);

        if (! $target || ! $source) {
            $this->error('Target or source family not found.');

            return self::FAILURE;
        }

        // Index the target's existing SKUs by Length+Colour.
        $targetIndex = [];
        foreach ($target->products as $tp) {
            $targetIndex[$this->skuKey($tp)] = $tp;
        }

        $counts = ['MOVE_NEW' => 0, 'REPLACE' => 0, 'SKIP' => 0, 'CONFLICT' => 0];

        $this->info("Absorb plan: #{$source->id} \"{$source->family_name}\" -> #{$target->id} \"{$target->family_name}\"");
        $this->newLine();

        foreach ($source->products as $sp) {
            $key = $this->skuKey($sp);
            [$length, $colour] = $this->lengthColour($sp);
            $incomingEmpty = $this->isEmpty($sp);
            $existing = $targetIndex[$key] ?? null;

            if ($existing) {
                if ($incomingEmpty) {
                    $action = 'SKIP';
                } elseif ($this->isEmpty($existing)) {
                    $action = 'REPLACE';
                    $targetIndex[$key] = $sp; // the incoming will take its place
                } else {
                    $action = 'CONFLICT'; // both real -> keep both
                }
            } else {
                $action = 'MOVE_NEW';
                $targetIndex[$key] = $sp;
            }

            $counts[$action]++;
            $this->decisions[(string) $sp->id] = [
                'action' => $action,
                'key' => $key,
                'length' => $length,
                'colour' => $colour,
                'incomingEmpty' => $incomingEmpty,
                'existingId' => $existing?->id,
            ];

            $bc = $sp->barcode ?: '-';
            $img = $sp->media->count();
            $this->line("  [{$action}] {$length} · {$colour}  (bc={$bc} img={$img})"
                .($existing ? " vs existing #{$existing->id}" : ''));
        }

        $photos = $source->products->sum(fn (Product $p): int => $p->media->count()) + $source->media->count();

        $this->newLine();
        $this->line("MOVE_NEW={$counts['MOVE_NEW']}  REPLACE={$counts['REPLACE']}  SKIP(empty dup)={$counts['SKIP']}  CONFLICT(keep both)={$counts['CONFLICT']}");
        $this->line("Photos in source: {$photos} — all real ones are MOVED to #{$target->id}; only empty duplicate SKUs are dropped (no photos on them).");

        if ($counts['CONFLICT'] > 0) {
            $this->warn("{$counts['CONFLICT']} Length+Colour already exist with real data in BOTH — kept both for you to resolve manually.");
        }

        if (! $this->option('execute')) {
            $this->newLine();
            $this->info('DRY RUN — nothing changed. Re-run with --execute to perform it.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->absorb($target, $source));

        $this->newLine();
        $this->info("Absorbed #{$source->id} into #{$target->id} and deleted the empty source.");

        return self::SUCCESS;
    }

    private function absorb(ProductFamily $target, ProductFamily $source): void
    {
        $target->load('variantGroups.options');

        foreach ($source->products as $sp) {
            $decision = $this->decisions[(string) $sp->id] ?? ['action' => 'MOVE_NEW', 'existingId' => null];

            if ($decision['action'] === 'SKIP') {
                $this->deleteProduct($sp); // redundant empty incoming
                continue;
            }

            if ($decision['action'] === 'REPLACE' && $decision['existingId']) {
                $existing = $target->products->firstWhere('id', $decision['existingId']);
                if ($existing) {
                    $this->deleteProduct($existing); // empty existing, replaced by the rich incoming
                }
            }

            // Move the source product into the target, remapping its variant values.
            $sp->update(['product_family_id' => $target->id]);

            foreach ($sp->variantValues as $value) {
                $sourceGroup = $value->group;
                $sourceOption = $value->option;
                if (! $sourceGroup || ! $sourceOption) {
                    $value->delete();
                    continue;
                }

                $targetGroup = $this->matchTargetGroup($target, $sourceGroup);
                if (! $targetGroup) {
                    $value->delete();
                    continue;
                }

                $targetOption = $this->ensureOption($targetGroup, (string) $sourceOption->label, (int) $sourceOption->sort_order);
                $value->update([
                    'product_variant_group_id' => $targetGroup->id,
                    'product_variant_option_id' => $targetOption->id,
                ]);
            }

            ProductCategoryAssignment::query()->where('product_id', $sp->id)->update(['product_family_id' => $target->id]);
            ProductSource::query()->where('product_id', $sp->id)->update(['product_family_id' => $target->id]);
            ProductEcommerceProfile::query()->where('product_id', $sp->id)->update(['product_family_id' => $target->id]);
            ProductMedia::query()->where('product_id', $sp->id)->update(['product_family_id' => $target->id]);
        }

        // Re-point any remaining source media (family images) before deletion.
        ProductMedia::query()->where('product_family_id', $source->id)->update(['product_family_id' => $target->id]);

        $source->delete();
    }

    /** Build the Length+Colour key used for de-duplication. */
    private function skuKey(Product $product): string
    {
        [$length, $colour] = $this->lengthColour($product);

        return mb_strtolower(trim((string) $length)).'|'.mb_strtolower(trim((string) $colour));
    }

    /**
     * @return array{0: ?string, 1: ?string} [lengthLabel, colourLabel]
     */
    private function lengthColour(Product $product): array
    {
        $length = null;
        $colour = null;

        foreach ($product->variantValues as $value) {
            $group = $value->group;
            $label = $value->option?->label;
            if (! $group || $label === null) {
                continue;
            }

            if ($this->isLengthGroup($group)) {
                $length = $label;
            } elseif (! $this->isCountConcept($group)) {
                $colour = $label; // the sub-main differentiator (Colour)
            }
        }

        return [$length, $colour];
    }

    private function matchTargetGroup(ProductFamily $target, ProductVariantGroup $sourceGroup): ?ProductVariantGroup
    {
        return $target->variantGroups
            ->first(fn (ProductVariantGroup $g): bool => mb_strtolower($g->name) === mb_strtolower($sourceGroup->name))
            ?? $target->variantGroups->first(function (ProductVariantGroup $g) use ($sourceGroup): bool {
                if ($this->isLengthGroup($sourceGroup)) {
                    return $this->isLengthGroup($g);
                }
                if ($this->isCountConcept($sourceGroup)) {
                    return $this->isCountConcept($g);
                }

                return ! $this->isLengthGroup($g) && ! $this->isCountConcept($g);
            });
    }

    private function ensureOption(ProductVariantGroup $group, string $label, int $sortOrder = 0): ProductVariantOption
    {
        $existing = $group->options
            ->first(fn (ProductVariantOption $o): bool => mb_strtolower($o->label) === mb_strtolower($label));
        if ($existing) {
            return $existing;
        }

        $option = ProductVariantOption::query()->create([
            'product_variant_group_id' => $group->id,
            'label' => $label,
            'value' => $label,
            'sort_order' => $sortOrder,
        ]);
        $group->options->push($option);

        return $option;
    }

    private function isEmpty(Product $product): bool
    {
        return empty($product->barcode) && $product->media->count() === 0;
    }

    private function deleteProduct(Product $product): void
    {
        // Empty product (no barcode/photos) — its variant values cascade with it.
        $product->delete();
    }

    private function isLengthGroup(ProductVariantGroup $group): bool
    {
        return mb_strtolower((string) $group->variant_type) === 'measurement'
            || str_contains(mb_strtolower((string) $group->name), 'length');
    }

    private function isCountConcept(ProductVariantGroup $group): bool
    {
        foreach ([mb_strtolower((string) $group->variant_type), mb_strtolower((string) $group->name)] as $haystack) {
            foreach (['count', 'pack', 'bundle', 'quantity'] as $needle) {
                if ($haystack !== '' && str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
