# Hair Extension Product Matching Workflow

This workflow is for matching one physical shop-floor hair-extension product to the imported catalogue, then preparing a safe sellable product structure.

## Core Principle

The user confirms what is physically stocked. Codex only assists by matching the submitted product photo/details to the imported catalogue and returning structured data for review.

Never invent stock, variants, barcodes, prices, photos, or shelf locations.

## Batch Photo Analysis Workflow

Use this when reviewing shop photo batches such as `/shop-photo-batches/batch-two`.

The final target is the V2 intake structure at `/hair-extension-product-intake/v2`. Every analysed photo should be shaped so it can become, or help create, a V2 intake submission.

When a batch photo is identified with enough confidence, create or update the real V2 intake record directly instead of keeping the information only on the photo-batch review page.

The purpose is to identify the product family first. Variant detail is helpful, but it is secondary. A record is useful when it gives the user a reliable family structure they can later confirm with barcode, price, photos, and exact stocked variants.

For each photo, work in this order:

1. Inspect the photo visually before using any catalogue or web source.
2. Extract only what is visible: brand, product type, style/family, main variant, sub variant, common variant, and ecommerce note.
3. Decide whether the image is a primary product photo or a support photo.
4. If it is a support photo, mark it as support/review and link it conceptually to the closest previous/next product only when that relationship is visually obvious.
5. If the product family is clear, save the family-level data even if one variant field is missing.
6. If the record is identified, push it into V2 intake with the photo attached.
7. If the family itself is unclear, do not force a match. Mark it for review.

Confidence threshold for batch photos:

- Use `A` only when brand, family/style, product type, and visible variant signals are clear from the photo or verified source.
- Use `B` only when the product family is clear but one non-critical variant field is missing or inferred from nearby support images.
- Use `C` only when the product is useful but still needs user confirmation before becoming catalogue truth.
- Use review/unresolved when the brand or product family cannot be identified safely.

If certainty is below 99%, check online before finalising the family. Use the internet to verify packaging, exact style name, product type, and variant structure. Do not use internet data to claim the shop has variants that are not visible or later confirmed by the user.

When online checking still does not produce a 99-100% match, save the record as review/unresolved with a short reason. Do not guess.

Batch photo output fields:

- `brand`: brand printed on pack, or `Unknown` if not safe.
- `grouping_path`: optional hierarchy under the brand when the packaging clearly shows a range, sub-brand, collection, product line, or shelf grouping.
- `product_type`: functional type such as `Bulk Hair`, `Braid`, `Crochet Braid`, `Weave`, `Wig`, `Ponytail`, or `Clip On`.
- `style`: sellable family/style name printed on pack.
- `main_variant`: primary axis, usually length or size.
- `sub_variant`: secondary axis, usually colour.
- `common_variant`: only sellable shared variant traits, such as `3X`, `2X`, `4X`, pack count, bundle count, weight, or packaging count.
- `ecommerce_note`: short customer-safe product description. Do not mention source, supplier, uncertainty, or internal analysis.
- `analysis_notes`: internal reason for the match, including visible evidence and uncertainty.

Direct V2 mapping:

- `brand` maps to V2 brand/name.
- `grouping_path` maps to V2 grouping path.
- `product_type` maps to V2 product type.
- `style` maps to V2 Style / family.
- `main_variant` maps to the V2 main axis value. Use axis `Length` when the value is a length.
- `sub_variant` maps to the V2 sub axis value. Use axis `Colour` when the value is a colour code.
- `common_variant` maps to V2 common variants only when it is sellable, normally `Pack count`.
- The batch photo becomes the V2 intake main photo.
- `ecommerce_note` and `analysis_notes` go into the V2 note so the record remains reviewable.

Batch photo rules:

- Do not create a new family from a barcode-only or back-label-only image unless the front/product identity is also visible.
- Do not merge two similar products unless the same brand and same style/family are clearly visible.
- Do not treat a colour chart as proof that all colours are stocked.
- Do not treat one visible variant as proof that every variant under that style is stocked.
- If two photos appear to be the front and back of the same item, use the support photo to improve notes, but keep the family identity anchored to the front photo.
- Keep ecommerce notes clean and customer-facing; internal words like `maybe`, `unclear`, `support photo`, and supplier names do not belong there.

## Feature Versus Variant Rule

Do not put product features into variant fields.

Use `common_variant` only for sellable variant values that can change between sellable products or clearly define the pack being sold.

Good `common_variant` examples:

- `3X`
- `4X`
- `2 pack`
- `100g`
- `3 bundles`
- `Pre-stretched 3X` only if the pack/range sells that way and it distinguishes the product from another sellable version.

Do not use `common_variant` for features or marketing claims:

- `natural soft texture`
- `itch free`
- `anti-bacterial`
- `human hair feel`
- `soft and shiny`
- `long lasting`
- `premium fibre`

Features belong in `ecommerce_note` if customer-facing, or `analysis_notes` if they are only evidence from the pack.

## Grouping Path Rule For V2 Intake

Use `grouping_path` when the packaging gives useful hierarchy below the brand.

Examples:

- `Sleek > Style Icon`
- `Sleek > Remy Couture`
- `X-Pression > Outre > Twisted Up`
- `Kuknus > Bulk > Fusion`
- `Cherish > Bulk`

Grouping path is not the same as product type or style:

- Brand is the main brand only.
- Grouping path is a helpful hierarchy under the brand.
- Product type is the functional product kind, such as `Bulk Hair`, `Braid`, `Crochet Braid`, `Weave`, `Wig`, or `Ponytail`.
- Style/family is the exact sellable family name, such as `French Curl`, `Afro Kinky`, `Peruvian Remi Deep`, or `Spring Twist`.

Only add a grouping path when it is visible on packaging, already known from the project catalogue, or strongly supported by the brand's structure. Do not invent a group just because it would look tidy.

## Matching Order

1. Read the latest submitted intake only.
2. Use the submitted brand, grouping path, shelf/area note, style hint, photo, variant map, and note.
3. Search the imported hair-extension catalogue scoped to that brand.
4. Match against product family, product type, style, and variant axes.
5. Return the matched sellable structure only when the evidence is strong.

## Variant Taxonomy

Always organize variants into these groups:

- Main variant: usually length, e.g. `20"`, `46"`, `82"`.
- Sub variant: usually colour, e.g. `1`, `1B`, `T1B/30`, `UV-PINK`.
- Common variant: shared traits, e.g. `3X`, `pack count`, `bundle count`, `100g`, `synthetic`, `small`.

If the product uses a different structure, keep the same three groups but choose the closest correct axes.

## Shop-Floor Classification Rule

V2 intake may include an ordered grouping path from the shelf, for example `Kuknus Bulk > Fusion > Peruvian Remi`.

Treat this path as shop-floor evidence, not as final catalogue truth:

- Brand remains the top-level brand chosen by the user.
- Grouping path can represent sub-brand, range, collection, product group, or shelf wording.
- Product type should stay functional, e.g. `Bulk Hair`, `Braid`, `Weave`, `Wig`, `Ponytail`, `Crochet`.
- Style/family should be the sellable style name seen on the pack, e.g. `Deep`, `Water`, `Afro Kinky`, `French Curl`.
- Use the grouping path to reduce confusion when catalogue structure and packaging wording do not match exactly.
- Do not force every grouping level into the final product name. Keep it as evidence unless the user approves the final naming.

## Confidence Rule

Use the imported catalogue first.

If confidence is `98%` or higher:

- Return a confirmed match.
- Include the matched family, type, style, variant axes, and candidate variants.
- Mark only the photographed/observed variant as directly supported by the submission.

If confidence is below `98%`, or there are similar competing products:

- Do not guess.
- Check online sources for that brand/product to clarify packaging, naming, style family, and variant axes.
- Use online information only to understand and verify the catalogue match.
- Do not use online information to declare that the shop stocks a variant.

If still uncertain after online checking:

- Return the top 2-3 candidates.
- Explain exactly what matches and what is uncertain.
- Ask the user to choose.

## Online Checking Rule

Only check online when needed:

- The style name is ambiguous.
- The packaging photo is unclear.
- Multiple catalogue styles look similar.
- Variant axes conflict with the submitted observation.
- Confidence is under `98%`.

When checking online, prefer:

1. Official brand or distributor site.
2. Known supplier/source used for the catalogue.
3. Reputable retailer pages with clear product packaging.

Visual packaging and variant structure must match. Text similarity alone is not enough.

## Output Rule

For a match, return structured data that can pre-fill the intake flow:

- brand
- family
- product type
- style
- variant taxonomy
- all variants under the matched style
- which variants match the submitted observation
- confidence
- brief reasoning

Every variant remains pending user confirmation until the user adds barcode, price, photo, and location.

## Never Do

- Never publish automatically.
- Never rename, merge, or edit imported catalogue records without user approval.
- Never say a whole style is stocked because one variant photo was submitted.
- Never create a sellable product from a weak match.
- Never use a similar product image as proof of an exact match.
- Never search the whole project when the task is a hair-extension intake match; stay scoped to hair-extension catalogue and the latest intake.

## If Lost

Return to this sequence:

1. Latest intake submission.
2. Submitted brand.
3. Submitted style hint.
4. Submitted grouping path and shelf/area note.
5. Submitted variant map.
6. Imported catalogue under that brand.
7. Online verification only if confidence is below `98%`.
8. Confirm, offer candidates, or mark not found.
