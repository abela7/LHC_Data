# Hair Extension Product Structure Recommendation

Goal: create a product structure that works for POS, ecommerce, and inventory.

The operational goal is simple:

```text
Create the correct product family first.
Then adding variants, barcode, price, photos, and shelf location becomes simple.
```

## Executive Recommendation

Use a two-layer model:

1. **Master catalogue layer**: every known brand, line, type, style, and possible variant from sources and shop observation.
2. **Shop sellable layer**: only the variants LHC actually stocks, each with barcode, price, photo, location, POS/ecommerce/inventory flags.

Do not mix these layers.

The master catalogue answers:

```text
What products exist under this brand?
What product family does this belong to?
What variants can this family have?
```

The shop sellable layer answers:

```text
Do we physically stock this exact variant?
What is the barcode?
What is the price?
Where is it located?
Which image should show online/POS?
```

## Recommended Hierarchy

Use this hierarchy everywhere:

```text
Department
  -> Brand
    -> Product Line / Grouping Path
      -> Product Type
        -> Material
          -> Style / Family
            -> Variant Groups
              -> Sellable SKU
```

For the current app, this maps to existing tables:

```text
Department
  -> brand_catalogues

Brand
  -> brand_catalogue_brands

Product Line / Grouping Path
  -> brand_catalogue_lines

Product Type
  -> brand_catalogue_product_types

Material
  -> brand_catalogue_materials

Style / Family
  -> brand_catalogue_styles

Variant Groups
  -> brand_catalogue_variants
  -> brand_catalogue_variant_options

Possible Catalogue SKU
  -> brand_catalogue_skus

Published Shop Family
  -> product_families

Published Variant Groups
  -> product_variant_groups
  -> product_variant_options

Sellable Product
  -> products
```

## Why This Works

Hair-extension brands do not use one consistent structure.

Examples from research:

- Outre/X-Pression separates braids into `Pre-Stretched Braid`, `Crochet Braid (PRE-LOOP)`, `Braiding Hair`, and `Bulk`.
- Sensationnel product pages expose structured attributes such as `Material`, `Braid Type`, `Style`, `Length`, and `Colors`.
- Kuknus lists category/family names such as `Synthetic Crochet Braids`, with products like `EZ Braid 16" 3X`.
- Koko separates clip-in ponytails and clip-in hair extensions.
- Kanekalon describes material/fibre use across braids, wigs, crochet and bulk styles, proving material should not be product type.

So LHC should not blindly copy supplier categories. LHC needs its own stable structure.

## Controlled Product Types

Keep product type short and physical-format based.

Use only these as major product types:

```text
Crochet Braid
Braid
Bulk Hair
Weave
Ponytail
Clip-in Extensions
Wig
Closure / Frontal
Bun
Scrunchie
Bang / Fringe
Tape-in Extensions
I-tip Extensions
Micro Loop Extensions
Nano Ring Extensions
Stick Tip Extensions
```

Do not use these as product type:

```text
BOHO
Cherish Junior
Hair Couture
Fashion Idol
European Weave
Brazilian Hair Weave
Virgin Remy Hair Weave
Remy Couture
Noble Gold
Nu Soft
Twisted Up
Premium Too
```

Those are usually product line, grouping path, material, or style.

## Product Line / Grouping Path

This is where brand-specific structure belongs.

Examples:

```text
Cherish > Bulk
Cherish > Boho
Cherish > Junior
Cherish > Handmade

X-Pression > Braids
X-Pression > Twisted Up
X-Pression > Weave On

Sleek > Fashion Idol Express
Sleek > Noble Gold
Sleek > Remy Couture

Sensationnel > Premium Too
Sensationnel > Empire
Sensationnel > Goddess Select

Smart > Vivitress
Smart > Remy Chaser
```

Rule:

```text
If it sounds like a brand line, collection, range, or supplier grouping, it goes in grouping path.
```

## Style / Family

This is the most important level.

The style/family is the product the customer recognises before choosing colour/length.

Examples:

```text
Passion Twist
Boho Braid
Butterfly Locs
Nu Soft Locs
Ultra Braid
Pre-Stretched Braid
Water Wave
Deep Bulk
Yaky Weave
European Weave
One Weft Straight
Drawstring Ponytail
```

Family names should be clean:

Good:

```text
Cherish Bulk Passion Twist
Obsession Nu Soft Locs
Sleek Fashion Idol Express Boho Water Braid
Koko One Weft Straight Clip-in Extension
```

Bad:

```text
Cherish Bulk Passion Twist 18 inch Colour BG 3X Premium Fibre
```

Variant details do not belong in the family name.

## Material

Material should be an attribute/table level, not product type and not common variant.

Recommended material values:

```text
Synthetic Fibre
Kanekalon
Toyokalon
Heat-Resistant Synthetic Fibre
Premium Fibre
Human Hair Blend
Human Hair
Remy Human Hair
Virgin Remy Human Hair
```

Examples:

```text
Product Type: Weave
Material: 100% Human Hair
Style: European Weave

Product Type: Braid
Material: Kanekalon
Style: Ultra Braid

Product Type: Crochet Braid
Material: Human Hair Blend
Style: Feather Crochet Deep
```

## Variant Groups

Use three practical variant groups.

### Main Variant

Usually length.

Examples:

```text
8 inch
14 inch
18 inch
22 inch
30 inch
46 inch
72 inch
14/18/22 inch mixed pack
100g
```

### Sub Variant

Usually colour.

Keep printed colour codes exactly:

```text
1
1B
2
4
27
30
33
613
99J
BG
T27
T30
T530
T1B/30
P1B/30
OT30
```

### Common Variant

Only use common variant for sellable pack/count traits.

Good:

```text
1X
2X
3X
4X
6X
1 Pack
3 Pack
7 Piece
```

Bad:

```text
100% Human Hair
Premium Fibre
Pre-Stretched
Soft Texture
Anti-Itch
Human Hair Blend
```

Those are features/materials/notes.

## SKU Rule

One sellable SKU equals one exact sellable combination.

Formula:

```text
Brand + Line + Style + Main Variant + Sub Variant + Common Variant
```

Example:

```text
Family:
Cherish Bulk Passion Twist

Variant groups:
Length: 14 inch, 18 inch, 24 inch
Colour: 1, 1B, 2, BG, T27, OT30
Pack count: 1X

Sellable SKU:
Cherish Bulk Passion Twist - 18 inch - Colour BG
```

Only sellable SKUs get:

```text
barcode
price
inventory quantity
store / section / subsection
variant photo
POS active flag
ecommerce active flag
inventory tracked flag
```

## POS Naming

Receipt names should be short.

POS receipt name:

```text
Cherish Passion Twist BG
X-Pression Ultra UV Pink
Obsession Nu Soft T530
```

Inventory/POS full name:

```text
Cherish Bulk Passion Twist - 18 inch - Colour BG
X-Pression Pre-Stretched Ultraviolet - 46 inch - UV-PINK
Obsession Nu Soft Locs - 18 inch - Colour T530 - 3X
```

Ecommerce title:

```text
Cherish Bulk Passion Twist - 18 inch | Colour BG
X-Pression Pre-Stretched Ultraviolet - 46 inch | UV-PINK
Obsession Nu Soft Locs - 18 inch | T530 | 3X
```

## Image Rule

At family level:

```text
family_main image
```

At sellable SKU level:

```text
variant_front image
barcode image
back image
label image
gallery images
```

If exact variant image is missing, do not pretend another colour image is exact. Use family image only and mark the variant image as missing.

## Inventory Rule

Inventory belongs to the sellable SKU, not the family.

Family can say:

```text
This is a product line we sell.
```

SKU says:

```text
We currently have Colour 1B, 18 inch, barcode X, price £Y, in Store 1 > Section A > Shelf B.
```

## Ecommerce Rule

Family description can be shared.

SKU description can override only when needed.

Do not mention source websites, supplier names, or where information came from in customer descriptions.

Description should be customer-facing:

```text
Lightweight crochet passion twist hair designed for quick protective styling with a soft textured finish.
```

Not:

```text
Information from Shaba / Mamado / source page says...
```

## Recommended Cleanup Plan For Current LHC Data

Current audit shows:

```text
310 submitted intakes
27 brands
39 current product type strings
25 normalized type strings
237 unique family keys
```

Step 1: Normalize product types.

Create one mapping table:

```text
current_product_type -> normalized_product_type
```

Examples:

```text
Crochet Hair -> Crochet Braid
Crochet -> Crochet Braid
Crochet Braids -> Crochet Braid
Ponytails / Drawstrings -> Ponytail
EZ Ponytail -> Ponytail
Weft Hair Extensions -> Weave
Clip-In Hair Extensions -> Clip-in Extensions
BULK -> Bulk Hair
Hair Bulk -> Bulk Hair
```

Step 2: Move line names into grouping path.

Examples:

```text
BOHO -> grouping path Cherish > Boho
Cherish junior -> grouping path Cherish > Junior
Hair Couture -> grouping path Sleek > Hair Couture
European Weave -> style/material context, product type Weave
Brazilian Hair Weave -> grouping/style/material context, product type Weave
Virgin Remy Hair Weave -> material context, product type Weave
```

Step 3: Clean common variants.

Examples:

```text
3x -> 3X
3X Pack -> 3X
3X VALUE PACK -> 3X
2X VALUE PACK -> 2X
100% Human Hair -> material
Human Hair Blend -> material
Premium Fibre -> material/feature
```

Step 4: Merge family duplicates.

Do not merge by name only. Merge by:

```text
brand + grouping path + normalized product type + material + style/family
```

Step 5: Publish family records.

For each clean family:

```text
create/update product_families
create product_variant_groups
create product_variant_options
do not activate all possible SKUs yet
```

Step 6: Activate stocked SKUs only.

When you scan/add real shop data:

```text
create/update products row
set barcode
set price
set POS/ecommerce/inventory flags
set location
attach variant image
```

## Recommended Family Readiness Status

Add/track a status for each family:

```text
catalogue_draft
structure_review
family_ready
sku_capture_ready
published
```

Meaning:

```text
catalogue_draft:
Raw imported or observed data exists.

structure_review:
Needs product type / grouping / style cleanup.

family_ready:
Brand, line, type, material, style and variant axes are clean.

sku_capture_ready:
Ready for barcode, price, photos, and stock confirmation.

published:
At least one sellable SKU active.
```

## Proposed LHC Brand Example Structures

### Cherish

```text
Cherish
  > Bulk
    Product Type: Bulk Hair or Crochet Braid depending on physical format
    Styles: Passion Twist, Water Wave, Deep Twist, Bohemian, Brazilian, Afro Kinky
  > Boho
    Product Type: Crochet Braid
    Styles: Boho Braid, Saniya Boho Braid, Mona Boho Braid
  > Junior
    Product Type: Crochet Braid / Bulk Hair
    Styles: Butterfly Locs, Silky Locs, Water Bulk
  > French Curl
    Product Type: Braid / Crochet Braid
    Styles: Spiral French Curl
```

### X-Pression

```text
X-Pression
  > Braids
    Product Type: Braid
    Styles: Ultra Braid, Pre-Stretched Braid, Lagos Braid
  > Twisted Up
    Product Type: Crochet Braid / Braid
    Styles: Swicy Afro Twist, Springy Afro Twist, French Curl
  > Weave On
    Product Type: Weave
    Styles: Active
```

### Sensationnel

```text
Sensationnel
  > Premium Too
    Product Type: Crochet Braid / Bulk Hair / Weave
  > Empire
    Product Type: Weave
  > Goddess Select
    Product Type: Weave / Wig
```

### Sleek

```text
Sleek
  > Fashion Idol Express
  > Fashion Idol 101
  > Style Icon
  > Remy Couture
  > Noble Gold
  > Virgin Gold
  > Brazilian
```

Product type is chosen under each line: Braid, Crochet Braid, Weave, Ponytail, Wig.

### Koko

```text
Koko
  > Clip-in Extensions
    Styles: One Weft Straight, One Weft Curly, Three Weft Beach Wave
  > Ponytail
    Styles: Claw Clip Ponytail, Wrap Ponytail
```

### Smart

```text
Smart
  > Smart Braid
  > Vivitress
  > Remy Chaser
  > X-Smart
  > Smart Fashion Ponytail
```

## Final Recommendation

The right structure for LHC is:

```text
Catalogue family first.
Sellable SKU second.
Stock confirmation third.
```

Do not build products directly from every photo as final SKUs.

Instead:

1. Convert all intakes into clean product families.
2. Normalize product types.
3. Group by brand/line/type/material/style.
4. Build variant axes.
5. Let barcode/price/photo create active sellable products.

This gives LHC:

- clean POS names
- clean ecommerce titles
- correct inventory tracking
- no duplicated product families
- easy future variant additions
- clear separation between "catalogue exists" and "we physically stock this variant"

## Research Sources

- Outre X-Pression taxonomy: https://www.outre.com/product/x-pression-pre-stretched-braid/
- Outre X-Pression Twisted Up: https://www.outre.com/brand/x-pression-twisted-up/
- Sensationnel Premium Too attributes: https://www.sensationnel.com/product/premium-too_feather-crochet_deep-14/
- Kuknus Synthetic Crochet Braids: https://www.kuknus.co.uk/index.php?path=17&route=product%2Fcategory
- Koko Couture ponytail/clip-in categories: https://www.kokocouture.co.uk/collections/clip-in-ponytails
- Kanekalon official material reference: https://www.kanekalon.com/en/
- Cliphair material/application reference: https://www.cliphair.com/pages/product-quality
- ICM extension method reference: https://www.icm.education/cbq-units/hair-extensions
