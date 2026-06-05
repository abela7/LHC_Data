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
use App\Models\ProductVariantValue;
use App\Support\RetailStyleFamilyCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse the per-length "style split" families of one catalogue style into a
 * SINGLE family that carries Length as a real MAIN variant axis.
 *
 * Today each length is its own ProductFamily (catalogue_scope_key = split-...-20,
 * shown as the "THIS STYLE: Main / 16" / 20"" tabs). This merges them into one
 * family: Length (main) x Colour (sub-main), Pack/Bundle (common).
 *
 * It is robust to messy source families:
 *   - a SKU's length is read from its own Length group when it has one, else
 *     from the family scope (or --main-length for an unlabelled NULL family);
 *   - a source family's own Length group is folded into the one main Length axis
 *     (no duplicate Length group);
 *   - redundant pack/count axes (e.g. a stray "Pack" next to "Bundle") are
 *     dropped rather than re-created on the target.
 *
 *   php artisan retail:merge-length-families 12110            # DRY RUN (default)
 *   php artisan retail:merge-length-families 12110 --execute  # perform it
 *
 * Safe by default: without --execute it prints the plan (and the otherwise
 * invisible NULL-family SKUs) and changes nothing.
 */
class MergeLengthFamiliesCommand extends Command
{
    protected $signature = 'retail:merge-length-families
        {family : a ProductFamily id on the catalogue style to merge}
        {--target= : family id to KEEP as the merged family (default: the one with most SKUs)}
        {--length-group=Length : name for the Length variant group}
        {--main-length= : length to assign to a NULL-scope family whose SKUs have no length of their own (e.g. 20")}
        {--drop-empty-main : delete a NULL-scope family SKUs that have no resolvable length}
        {--execute : perform the merge (default is a dry run that changes nothing)}';

    protected $description = 'Merge per-length sibling families into one family with Length as the main variant axis.';

    private const DROP = '__DROP__';

    public function handle(): int
    {
        $seed = ProductFamily::find((int) $this->argument('family'));
        if (! $seed) {
            $this->error("Family #{$this->argument('family')} not found.");

            return self::FAILURE;
        }

        $styleId = (int) $seed->brand_catalogue_style_id;
        if ($styleId <= 0) {
            $this->error("Family #{$seed->id} is not linked to a catalogue style — nothing to merge.");

            return self::FAILURE;
        }

        $families = ProductFamily::query()
            ->where('brand_catalogue_style_id', $styleId)
            ->withCount('products')
            ->with(['variantGroups.options', 'products.variantValues', 'products.media', 'media'])
            ->orderByDesc('products_count')
            ->get();

        if ($families->count() < 2) {
            $this->info('Only one family on this style — nothing to merge.');

            return self::SUCCESS;
        }

        $targetId = (int) ($this->option('target') ?: $families->first()->id);
        $target = $families->firstWhere('id', $targetId);
        if (! $target) {
            $this->error("Target family #{$targetId} is not on this style.");

            return self::FAILURE;
        }

        $mainLength = $this->normalizeLength((string) ($this->option('main-length') ?? ''));
        $dropMain = (bool) $this->option('drop-empty-main');

        // Resolve how each family contributes its length.
        $plan = [];
        $unresolved = [];
        foreach ($families as $family) {
            $plan[$family->id] = $this->resolveFamilyLength($family, $mainLength, $dropMain);
            if ($plan[$family->id]['mode'] === 'unresolved') {
                $unresolved[] = $family;
            }
        }

        $this->printPlan($families, $target, $plan);

        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('One family has SKUs with no resolvable length. Re-run with ONE of:');
            $this->line('   --main-length=20"     (give those SKUs a length)');
            $this->line('   --drop-empty-main     (delete those SKUs)');

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->newLine();
            $this->info('DRY RUN — nothing changed. Re-run with --execute to perform the merge.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->merge($families, $target, $plan));

        $this->newLine();
        $this->info("Merged into family #{$target->id}. Reload the page — Length is now the main axis.");

        return self::SUCCESS;
    }

    /**
     * @return array{mode: string, length: ?string, lengthGroupId: ?int}
     */
    private function resolveFamilyLength(ProductFamily $family, string $mainLength, bool $dropMain): array
    {
        $sourceLengthGroup = $family->variantGroups->first(fn (ProductVariantGroup $g): bool => $this->isLengthGroup($g));

        // A non-null scope already names the length (split-length-20 -> 20").
        if (filled($family->catalogue_scope_key)) {
            return [
                'mode' => 'fixed',
                'length' => $this->normalizeLength(RetailStyleFamilyCatalogue::scopeLabel($family->catalogue_scope_key)),
                'lengthGroupId' => $sourceLengthGroup?->id,
            ];
        }

        // NULL scope but the SKUs carry their own Length value -> read it per product.
        if ($sourceLengthGroup && $this->everyProductHasLength($family, $sourceLengthGroup)) {
            return ['mode' => 'per_product', 'length' => null, 'lengthGroupId' => (int) $sourceLengthGroup->id];
        }

        if ($dropMain) {
            return ['mode' => 'drop', 'length' => null, 'lengthGroupId' => $sourceLengthGroup?->id];
        }

        if ($mainLength !== '') {
            return ['mode' => 'fixed', 'length' => $mainLength, 'lengthGroupId' => $sourceLengthGroup?->id];
        }

        return ['mode' => 'unresolved', 'length' => null, 'lengthGroupId' => $sourceLengthGroup?->id];
    }

    private function everyProductHasLength(ProductFamily $family, ProductVariantGroup $lengthGroup): bool
    {
        if ($family->products->isEmpty()) {
            return false;
        }

        return $family->products->every(function (Product $product) use ($lengthGroup): bool {
            return $product->variantValues
                ->firstWhere('product_variant_group_id', $lengthGroup->id) !== null;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductFamily>  $families
     * @param  array<int, array{mode: string, length: ?string, lengthGroupId: ?int}>  $plan
     */
    private function printPlan($families, ProductFamily $target, array $plan): void
    {
        $this->info("Catalogue style #{$target->brand_catalogue_style_id} — merge plan");
        $this->line("Target (kept): #{$target->id} \"{$target->family_name}\"");
        $this->newLine();

        foreach ($families as $family) {
            $p = $plan[$family->id];
            $lengthText = match ($p['mode']) {
                'fixed' => $p['length'],
                'per_product' => 'per SKU (own Length value)',
                'drop' => 'DROP SKUs',
                default => 'UNRESOLVED',
            };
            $tag = $family->id === $target->id ? ' [TARGET]' : '';
            $skuPhotos = $family->products->sum(fn (Product $product): int => $product->media->count());
            $familyPhotos = $family->media->count();
            $this->line("FAM #{$family->id} scope=".var_export($family->catalogue_scope_key, true)
                ." length={$lengthText} skus={$family->products_count} photos={$skuPhotos}+{$familyPhotos}{$tag}");

            foreach ($family->variantGroups as $group) {
                $note = $this->isLengthGroup($group) ? ' (folded into main Length)'
                    : ($this->isCountConcept($group) ? ' (count/pack)' : '');
                $this->line("    grp {$group->name} [{$group->variant_type}]{$note}: "
                    .$group->options->pluck('label')->implode(', '));
            }

            // Reveal SKUs of any NULL-scope family — otherwise invisible.
            if (! filled($family->catalogue_scope_key)) {
                foreach ($family->products as $product) {
                    $vv = $product->variantValues
                        ->map(fn (ProductVariantValue $v): string => $this->valueLabel($family, $v))
                        ->implode(' | ');
                    $this->line("      SKU #{$product->id} \"{$product->name}\" bc="
                        .($product->barcode ?: '-')." [{$vv}]");
                }
            }
        }

        $totalPhotos = $families->sum(fn (ProductFamily $f): int =>
            $f->products->sum(fn (Product $p): int => $p->media->count()) + $f->media->count());

        $this->newLine();
        $this->line('Length axis (MAIN) will hold every distinct SKU length above.');
        $this->line('Roles after merge: Length=Main, count/pack axis=Common, others (Colour)=Sub-main.');
        $this->line('Redundant Length/Pack groups on source families are folded/dropped — not duplicated.');
        $this->line("Photos: all {$totalPhotos} (per-SKU + family) are MOVED to the kept family — none deleted.");
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductFamily>  $families
     * @param  array<int, array{mode: string, length: ?string, lengthGroupId: ?int}>  $plan
     */
    private function merge($families, ProductFamily $target, array $plan): void
    {
        $lengthGroup = $this->ensureLengthGroup($target);
        $targetCountName = $this->countGroupName($target);
        $lengthOptions = [];

        // Target's own SKUs first.
        $this->applyLengthToProducts($target->products, $target, $plan[$target->id], $lengthGroup, $lengthOptions);

        foreach ($families as $family) {
            if ($family->id === $target->id) {
                continue;
            }

            if ($plan[$family->id]['mode'] === 'drop') {
                foreach ($family->products as $product) {
                    $this->deleteProduct($product);
                }
                $family->delete();

                continue;
            }

            [$groupMap, $optionMap] = $this->mapVariants($family, $target, (int) $lengthGroup->id, $targetCountName);

            foreach ($family->products as $product) {
                $length = $this->productLength($product, $family, $plan[$family->id]);
                $product->update(['product_family_id' => $target->id]);

                foreach ($product->variantValues as $value) {
                    $mappedGroup = $groupMap[(int) $value->product_variant_group_id] ?? null;
                    $mappedOption = $optionMap[(int) $value->product_variant_option_id] ?? null;

                    if ($mappedGroup === null || $mappedOption === null) {
                        $value->delete(); // dropped Length / Pack / unmapped axis

                        continue;
                    }

                    $value->update([
                        'product_variant_group_id' => $mappedGroup,
                        'product_variant_option_id' => $mappedOption,
                    ]);
                }

                if ($length !== null) {
                    $this->setLengthValue($product, $lengthGroup, $this->lengthOption($lengthGroup, $length, $lengthOptions));
                }

                ProductCategoryAssignment::query()->where('product_id', $product->id)
                    ->update(['product_family_id' => $target->id]);
                ProductSource::query()->where('product_id', $product->id)
                    ->update(['product_family_id' => $target->id]);
                ProductEcommerceProfile::query()->where('product_id', $product->id)
                    ->update(['product_family_id' => $target->id]);
                // Photos store the family id and would be cascade-deleted when the
                // source family is removed — re-point them to the kept family.
                ProductMedia::query()->where('product_id', $product->id)
                    ->update(['product_family_id' => $target->id]);
            }

            // Safety net: re-point ANY remaining media of this source family
            // (moved-product photos + the family's own Main/variant/gallery images)
            // to the target BEFORE deleting it, so nothing is cascade-deleted.
            ProductMedia::query()->where('product_family_id', $family->id)
                ->update(['product_family_id' => $target->id]);

            $family->delete();
        }

        $this->assignRoles($target, $lengthGroup);

        if (Schema::hasColumn('product_families', 'catalogue_scope_key') && filled($target->catalogue_scope_key)) {
            $target->update(['catalogue_scope_key' => null]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @param  array{mode: string, length: ?string, lengthGroupId: ?int}  $familyPlan
     * @param  array<string, ProductVariantOption>  $lengthOptions
     */
    private function applyLengthToProducts($products, ProductFamily $family, array $familyPlan, ProductVariantGroup $lengthGroup, array &$lengthOptions): void
    {
        foreach ($products as $product) {
            $length = $this->productLength($product, $family, $familyPlan);
            if ($length !== null) {
                $this->setLengthValue($product, $lengthGroup, $this->lengthOption($lengthGroup, $length, $lengthOptions));
            }
        }
    }

    /**
     * @param  array{mode: string, length: ?string, lengthGroupId: ?int}  $familyPlan
     */
    private function productLength(Product $product, ProductFamily $family, array $familyPlan): ?string
    {
        if ($familyPlan['mode'] === 'per_product' && $familyPlan['lengthGroupId']) {
            $value = $product->variantValues->firstWhere('product_variant_group_id', $familyPlan['lengthGroupId']);
            $group = $family->variantGroups->firstWhere('id', $familyPlan['lengthGroupId']);
            $option = $group?->options->firstWhere('id', $value?->product_variant_option_id);

            return $option ? $this->normalizeLength($option->label) : null;
        }

        return $familyPlan['length'];
    }

    /**
     * @param  array<string, ProductVariantOption>  $cache
     */
    private function lengthOption(ProductVariantGroup $lengthGroup, string $label, array &$cache): ProductVariantOption
    {
        if (! isset($cache[$label])) {
            $cache[$label] = $this->ensureOption($lengthGroup, $label, $this->lengthSortKey($label));
        }

        return $cache[$label];
    }

    private function ensureLengthGroup(ProductFamily $target): ProductVariantGroup
    {
        $name = trim((string) ($this->option('length-group') ?: 'Length'));

        $existing = $target->variantGroups()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            $existing->update(['axis_role' => ProductVariantGroup::AXIS_ROLE_MAIN]);

            return $existing;
        }

        return ProductVariantGroup::query()->create([
            'product_family_id' => $target->id,
            'name' => $name,
            'variant_type' => 'measurement',
            'axis_role' => ProductVariantGroup::AXIS_ROLE_MAIN,
            'sort_order' => 0,
        ]);
    }

    private function ensureOption(ProductVariantGroup $group, string $label, int $sortOrder = 0): ProductVariantOption
    {
        $existing = $group->options()
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->first();

        if ($existing) {
            return $existing;
        }

        return ProductVariantOption::query()->create([
            'product_variant_group_id' => $group->id,
            'label' => $label,
            'value' => $label,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Map a source family's groups/options onto the target's, creating any that
     * are missing. Length groups are folded into the main axis (skipped here);
     * redundant count/pack groups (a count axis whose name differs from the
     * target's) are dropped.
     *
     * @return array{0: array<int,int>, 1: array<int,int>}
     */
    private function mapVariants(ProductFamily $source, ProductFamily $target, int $lengthGroupId, ?string $targetCountName): array
    {
        $target->load('variantGroups.options');
        $groupMap = [];
        $optionMap = [];

        foreach ($source->variantGroups as $sourceGroup) {
            if ($this->isLengthGroup($sourceGroup)) {
                continue; // folded into main Length axis
            }

            if ($this->isCountConcept($sourceGroup)
                && $targetCountName !== null
                && mb_strtolower($sourceGroup->name) !== mb_strtolower($targetCountName)) {
                continue; // redundant pack/count axis -> drop
            }

            $targetGroup = $target->variantGroups
                ->reject(fn (ProductVariantGroup $g): bool => (int) $g->id === $lengthGroupId)
                ->first(fn (ProductVariantGroup $g): bool => mb_strtolower($g->name) === mb_strtolower($sourceGroup->name));

            if (! $targetGroup) {
                $targetGroup = ProductVariantGroup::query()->create([
                    'product_family_id' => $target->id,
                    'name' => $sourceGroup->name,
                    'variant_type' => $sourceGroup->variant_type,
                    'axis_role' => null,
                    'sort_order' => (int) $sourceGroup->sort_order + 10,
                ]);
                $targetGroup->setRelation('options', collect());
                $target->variantGroups->push($targetGroup);
            }

            $groupMap[(int) $sourceGroup->id] = (int) $targetGroup->id;

            foreach ($sourceGroup->options as $sourceOption) {
                $targetOption = $targetGroup->options
                    ->first(fn (ProductVariantOption $o): bool => mb_strtolower($o->label) === mb_strtolower($sourceOption->label));

                if (! $targetOption) {
                    $targetOption = ProductVariantOption::query()->create([
                        'product_variant_group_id' => $targetGroup->id,
                        'label' => $sourceOption->label,
                        'value' => $sourceOption->value ?? $sourceOption->label,
                        'sort_order' => (int) $sourceOption->sort_order,
                    ]);
                    $targetGroup->options->push($targetOption);
                }

                $optionMap[(int) $sourceOption->id] = (int) $targetOption->id;
            }
        }

        return [$groupMap, $optionMap];
    }

    private function setLengthValue(Product $product, ProductVariantGroup $lengthGroup, ProductVariantOption $option): void
    {
        ProductVariantValue::query()->updateOrCreate(
            ['product_id' => $product->id, 'product_variant_group_id' => $lengthGroup->id],
            ['product_variant_option_id' => $option->id],
        );
    }

    private function assignRoles(ProductFamily $target, ProductVariantGroup $lengthGroup): void
    {
        foreach ($target->variantGroups()->get() as $group) {
            if ((int) $group->id === (int) $lengthGroup->id) {
                $group->update(['axis_role' => ProductVariantGroup::AXIS_ROLE_MAIN]);

                continue;
            }

            $group->update([
                'axis_role' => $this->isCountConcept($group)
                    ? ProductVariantGroup::AXIS_ROLE_COMMON
                    : ProductVariantGroup::AXIS_ROLE_SUB_MAIN,
            ]);
        }
    }

    private function countGroupName(ProductFamily $target): ?string
    {
        $group = $target->variantGroups->first(fn (ProductVariantGroup $g): bool => $this->isCountConcept($g));

        return $group?->name;
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

    private function deleteProduct(Product $product): void
    {
        ProductVariantValue::query()->where('product_id', $product->id)->delete();
        $product->delete();
    }

    private function valueLabel(ProductFamily $family, ProductVariantValue $value): string
    {
        $group = $family->variantGroups->firstWhere('id', $value->product_variant_group_id);
        $option = $group?->options->firstWhere('id', $value->product_variant_option_id);

        return ($group?->name ?? '?').':'.($option?->label ?? '?');
    }

    private function normalizeLength(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        if (preg_match('/^\d+(\.\d+)?$/', $label)) {
            return $label.'"';
        }

        return $label;
    }

    private function lengthSortKey(string $label): int
    {
        return preg_match('/(\d+)/', $label, $m) ? (int) $m[1] : 999;
    }
}
