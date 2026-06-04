<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategoryAssignment;
use App\Models\ProductEcommerceProfile;
use App\Models\ProductFamily;
use App\Models\ProductSource;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use App\Models\ProductVariantValue;
use App\Support\RetailStyleFamilyCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Collapse the per-length "style split" families of one catalogue style into a
 * SINGLE family that carries Length as a real MAIN variant axis.
 *
 * Today each length is its own ProductFamily (catalogue_scope_key = split-...-20,
 * shown as the "THIS STYLE: Main / 16" / 20"" tabs). This merges them so you get
 * one family with: Length (main) x Colour (sub-main), Pack/Bundle (common).
 *
 *   php artisan retail:merge-length-families 12110                 # DRY RUN (default)
 *   php artisan retail:merge-length-families 12110 --main-length=20"  # how to label the NULL-scope "Main" family's SKUs
 *   php artisan retail:merge-length-families 12110 --drop-empty-main  # delete the "Main" family's SKUs instead
 *   php artisan retail:merge-length-families 12110 --main-length=20" --execute   # actually do it
 *
 * Safe by default: without --execute it only prints the plan (and the otherwise
 * invisible "Main" SKUs) and changes nothing.
 */
class MergeLengthFamiliesCommand extends Command
{
    protected $signature = 'retail:merge-length-families
        {family : a ProductFamily id on the catalogue style to merge}
        {--target= : family id to KEEP as the merged family (default: the one with most SKUs)}
        {--length-group=Length : name for the Length variant group}
        {--main-length= : length label to assign to the NULL-scope "Main" family SKUs (e.g. 20")}
        {--drop-empty-main : delete the NULL-scope "Main" family SKUs instead of giving them a length}
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
            ->with(['variantGroups.options', 'products.variantValues'])
            ->orderByDesc('products_count')
            ->get();

        if ($families->count() < 2) {
            $this->info('Only one family on this style — nothing to merge.');

            return self::SUCCESS;
        }

        // Target = family we keep. Default: most SKUs.
        $targetId = (int) ($this->option('target') ?: $families->first()->id);
        $target = $families->firstWhere('id', $targetId);
        if (! $target) {
            $this->error("Target family #{$targetId} is not on this style.");

            return self::FAILURE;
        }

        // Resolve a length label for every family (null = unresolved -> needs a flag).
        $mainLength = $this->normalizeLength((string) ($this->option('main-length') ?? ''));
        $dropMain = (bool) $this->option('drop-empty-main');
        $lengthFor = [];
        $unresolved = [];

        foreach ($families as $family) {
            if (filled($family->catalogue_scope_key)) {
                $lengthFor[$family->id] = $this->normalizeLength(
                    RetailStyleFamilyCatalogue::scopeLabel($family->catalogue_scope_key)
                );

                continue;
            }

            // NULL-scope "Main" family.
            if ($dropMain) {
                $lengthFor[$family->id] = self::DROP;
            } elseif ($mainLength !== '') {
                $lengthFor[$family->id] = $mainLength;
            } else {
                $lengthFor[$family->id] = null;
                $unresolved[] = $family;
            }
        }

        $this->printPlan($families, $target, $lengthFor);

        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('The NULL-scope "Main" family needs a decision. Re-run with ONE of:');
            $this->line('   --main-length=20"     (give its SKUs a length)');
            $this->line('   --drop-empty-main     (delete its SKUs)');

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->newLine();
            $this->info('DRY RUN — nothing changed. Re-run with --execute to perform the merge.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->merge($families, $target, $lengthFor));

        $this->newLine();
        $this->info("Merged into family #{$target->id}. Reload the page — Length is now the main axis.");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductFamily>  $families
     * @param  array<int, string|null>  $lengthFor
     */
    private function printPlan($families, ProductFamily $target, array $lengthFor): void
    {
        $this->info("Catalogue style #{$target->brand_catalogue_style_id} — merge plan");
        $this->line("Target (kept): #{$target->id} \"{$target->family_name}\"");
        $this->newLine();

        foreach ($families as $family) {
            $length = $lengthFor[$family->id];
            $tag = $family->id === $target->id ? ' [TARGET]' : '';
            $lengthText = $length === self::DROP ? 'DROP SKUs' : ($length ?? 'UNRESOLVED');
            $this->line("FAM #{$family->id} scope=".var_export($family->catalogue_scope_key, true)
                ." length={$lengthText} skus={$family->products_count}{$tag}");

            foreach ($family->variantGroups as $group) {
                $this->line("    grp {$group->name} [{$group->variant_type}]: "
                    .$group->options->pluck('label')->implode(', '));
            }

            // Always reveal the NULL-scope family's SKUs — they are otherwise invisible.
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

        $lengths = collect($lengthFor)->reject(fn ($l) => $l === null || $l === self::DROP)->unique()->values();
        $this->newLine();
        $this->line('Length axis (MAIN) will hold: '.$lengths->implode(', '));
        $this->line('Roles after merge: Length=Main, count/pack axis=Common, others (Colour)=Sub-main.');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductFamily>  $families
     * @param  array<int, string|null>  $lengthFor
     */
    private function merge($families, ProductFamily $target, array $lengthFor): void
    {
        $lengthGroup = $this->ensureLengthGroup($target);

        // Build a length option per distinct label.
        $lengthOptions = [];
        foreach ($lengthFor as $label) {
            if ($label === null || $label === self::DROP || isset($lengthOptions[$label])) {
                continue;
            }
            $lengthOptions[$label] = $this->ensureOption($lengthGroup, $label);
        }

        // Target's own SKUs get their own length.
        $targetLength = $lengthFor[$target->id];
        if ($targetLength !== self::DROP && $targetLength !== null) {
            foreach ($target->products as $product) {
                $this->setLengthValue($product, $lengthGroup, $lengthOptions[$targetLength]);
            }
        }

        foreach ($families as $family) {
            if ($family->id === $target->id) {
                continue;
            }

            $length = $lengthFor[$family->id];

            if ($length === self::DROP) {
                foreach ($family->products as $product) {
                    $this->deleteProduct($product);
                }
                $family->delete();

                continue;
            }

            [$groupMap, $optionMap] = $this->mapVariants($family, $target, (int) $lengthGroup->id);

            foreach ($family->products as $product) {
                $product->update(['product_family_id' => $target->id]);

                foreach ($product->variantValues as $value) {
                    $mappedGroup = $groupMap[(int) $value->product_variant_group_id] ?? null;
                    $mappedOption = $optionMap[(int) $value->product_variant_option_id] ?? null;

                    if ($mappedGroup === null || $mappedOption === null) {
                        $value->delete();

                        continue;
                    }

                    $value->update([
                        'product_variant_group_id' => $mappedGroup,
                        'product_variant_option_id' => $mappedOption,
                    ]);
                }

                $this->setLengthValue($product, $lengthGroup, $lengthOptions[$length]);

                ProductCategoryAssignment::query()->where('product_id', $product->id)
                    ->update(['product_family_id' => $target->id]);
                ProductSource::query()->where('product_id', $product->id)
                    ->update(['product_family_id' => $target->id]);
                ProductEcommerceProfile::query()->where('product_id', $product->id)
                    ->update(['product_family_id' => $target->id]);
            }

            // Source family now has no products; deleting it cascades its variant groups.
            $family->delete();
        }

        $this->assignRoles($target, $lengthGroup);

        if (Schema::hasColumn('product_families', 'catalogue_scope_key') && filled($target->catalogue_scope_key)) {
            $target->update(['catalogue_scope_key' => null]);
        }
    }

    private function ensureLengthGroup(ProductFamily $target): ProductVariantGroup
    {
        $name = trim((string) ($this->option('length-group') ?: 'Length'));

        $existing = $target->variantGroups()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return $existing;
        }

        return ProductVariantGroup::query()->create([
            'product_family_id' => $target->id,
            'name' => $name,
            'variant_type' => 'measurement',
            'axis_role' => ProductVariantGroup::AXIS_ROLE_MAIN,
            'sort_order' => 0, // main axis sorts first
        ]);
    }

    private function ensureOption(ProductVariantGroup $group, string $label): ProductVariantOption
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
            'sort_order' => $this->lengthSortKey($label),
        ]);
    }

    /**
     * Map a source family's groups/options onto the target family's, creating any
     * that are missing. The Length group is skipped (handled separately).
     *
     * @return array{0: array<int,int>, 1: array<int,int>}
     */
    private function mapVariants(ProductFamily $source, ProductFamily $target, int $lengthGroupId): array
    {
        $target->load('variantGroups.options');
        $groupMap = [];
        $optionMap = [];

        foreach ($source->variantGroups as $sourceGroup) {
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

            $role = $this->isCountConcept($group)
                ? ProductVariantGroup::AXIS_ROLE_COMMON
                : ProductVariantGroup::AXIS_ROLE_SUB_MAIN;

            $group->update(['axis_role' => $role]);
        }
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

        // "20" or "20 inch" -> 20"; leave already-suffixed and non-numeric labels as-is.
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
