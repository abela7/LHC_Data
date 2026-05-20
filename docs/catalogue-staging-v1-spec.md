# Catalogue Staging System V1 Spec

## Purpose

This application is a pre-POS catalogue staging and approval tool.

It is responsible for:

- storing catalogue master data for parent product families, optional product types, and sellable variants
- storing shop assortment matching separately from catalogue master
- importing external AI-generated JSON drafts
- preserving raw import payloads and shop evidence photos
- supporting human review, approval, rejection, and merge actions
- exporting approved structured data for later mapping into the final retailer/POS system

It is explicitly not responsible for:

- checkout
- sales
- live stock quantities
- barcode workflows
- SKU generation
- pricing workflows
- purchase ordering
- warehouse logic

## Modelling Principles

1. Keep catalogue master separate from shop assortment.
2. Keep both separate from future operational inventory.
3. Parent-child structure is mandatory.
4. Human review is the trust gate.
5. AI is external to the website and only supplies draft JSON.
6. Partial imports are allowed if the JSON shape is valid.
7. Generic or unbranded products must be supported.

## Core Domain Layers

### 1. Catalogue Master

Represents all known possible products and variants.

- brands
- categories
- subcategories
- parent product families
- optional product types / subtypes
- sellable variants
- sources
- images

### 2. Shop Assortment

Represents whether this shop actually carries a family, type, or variant.

- family shop match
- type shop match
- variant shop match
- confirmation method and audit

### 3. Operational Inventory

Out of scope for v1.

Future fields such as barcode, SKU, price, and stock should not drive the v1 schema.

## Proposed Laravel Models

- `Category`
- `Subcategory`
- `Brand`
- `CatalogueFamily`
- `CatalogueType`
- `CatalogueVariant`
- `CatalogueSource`
- `CatalogueImage`
- `ImportBatch`
- `ImportRecord`
- `ReviewAction`
- `DuplicateCandidate`
- `MergeEvent`
- `ShopMatch`
- `User`

## Database Tables

### `categories`

Seeded with the initial four business categories, but extendable later.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| name | string | Unique, e.g. `Hair` |
| slug | string | Unique |
| description | text nullable | |
| is_active | boolean | default true |
| sort_order | integer | default 0 |
| created_at / updated_at | timestamps | |

Notes:

- Seed: `Hair`, `Body Care`, `Hair Accessories`, `Cosmetics`.
- Do not hardcode categories into the schema.

### `subcategories`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| category_id | foreignId nullable | nullable during early setup if needed |
| name | string | |
| slug | string | |
| description | text nullable | |
| is_active | boolean | default true |
| sort_order | integer | default 0 |
| created_at / updated_at | timestamps | |

Indexes:

- unique on `category_id + slug`

### `brands`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| name | string | Unique |
| slug | string | Unique |
| notes | text nullable | |
| is_active | boolean | default true |
| is_generic | boolean | default false |
| created_by | foreignId nullable | users |
| updated_by | foreignId nullable | users |
| created_at / updated_at | timestamps | |

Notes:

- Seed generic records such as `Generic`, `Unbranded`, `Store Generic`.
- `brand_id` remains nullable on catalogue families for unknown drafts.

### `catalogue_families`

Represents the parent product family.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| brand_id | foreignId nullable | supports unknown or unassigned brand |
| category_id | foreignId nullable | |
| subcategory_id | foreignId nullable | |
| product_family_name | string | main recognized product line |
| slug | string nullable | app-generated, unique per brand where possible |
| short_description | text nullable | |
| full_description | longText nullable | |
| source_confidence | decimal(5,2) nullable | normalized human/import confidence |
| import_confidence | decimal(5,2) nullable | raw confidence from imported draft |
| status | string | see status rules |
| needs_source_verification | boolean | default false |
| duplicate_flag | boolean | default false |
| imported_json_snapshot | json nullable | convenience snapshot only |
| notes | longText nullable | internal notes |
| created_by | foreignId nullable | users |
| updated_by | foreignId nullable | users |
| reviewed_by | foreignId nullable | users |
| approved_by | foreignId nullable | users |
| approved_at | timestamp nullable | |
| merged_into_family_id | foreignId nullable | self-reference for merged records |
| archived_at | timestamp nullable | soft archive marker |
| created_at / updated_at | timestamps | |

Indexes:

- index on `brand_id`
- index on `category_id`
- index on `subcategory_id`
- index on `status`
- index on `approved_at`
- index on `merged_into_family_id`
- composite index on `brand_id + product_family_name`

Notes:

- This table is the main review object.
- `imported_json_snapshot` is optional convenience storage; raw import authority stays in `import_records`.

### `catalogue_types`

Optional structural layer under a family.

Examples:

- `Value Pack`
- `Professional Pack`
- `Pre-Stretched`
- `Water Wave`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| catalogue_family_id | foreignId | parent family |
| name | string | |
| slug | string nullable | |
| description | text nullable | |
| sort_order | integer | default 0 |
| status | string | draft lifecycle, usually mirrors family lifecycle |
| notes | text nullable | |
| created_by | foreignId nullable | users |
| updated_by | foreignId nullable | users |
| reviewed_by | foreignId nullable | users |
| approved_by | foreignId nullable | users |
| approved_at | timestamp nullable | |
| merged_into_type_id | foreignId nullable | self-reference if merged later |
| created_at / updated_at | timestamps | |

Indexes:

- index on `catalogue_family_id`
- index on `status`
- unique on `catalogue_family_id + slug`

Notes:

- This table is optional and only used when the product family has meaningful internal structure.
- Variants may belong directly to a family without a type.

### `catalogue_variants`

Represents the exact sellable child item.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| catalogue_family_id | foreignId | required |
| catalogue_type_id | foreignId nullable | optional structural grouping |
| variant_display_name | string | human-readable label |
| color_code | string nullable | |
| color_name | string nullable | |
| size | string nullable | |
| length | string nullable | |
| bundle_count | integer nullable | |
| pack_size | string nullable | |
| texture | string nullable | |
| shade | string nullable | |
| finish | string nullable | |
| style | string nullable | |
| weight | string nullable | |
| volume | string nullable | |
| attributes_json | json nullable | uncommon category-specific attributes |
| source_confidence | decimal(5,2) nullable | |
| import_confidence | decimal(5,2) nullable | |
| status | string | see status rules |
| notes | text nullable | |
| created_by | foreignId nullable | users |
| updated_by | foreignId nullable | users |
| reviewed_by | foreignId nullable | users |
| approved_by | foreignId nullable | users |
| approved_at | timestamp nullable | |
| merged_into_variant_id | foreignId nullable | self-reference |
| archived_at | timestamp nullable | |
| created_at / updated_at | timestamps | |

Indexes:

- index on `catalogue_family_id`
- index on `catalogue_type_id`
- index on `status`
- composite index on `catalogue_family_id + variant_display_name`

Notes:

- Use common descriptive columns for high-frequency attributes.
- Use `attributes_json` for uncommon fields such as `material`, `hold_level`, `fragrance`, `shade_family`, or `piece_count`.
- Do not put shop stocking flags in this table.

### `catalogue_sources`

Supports multiple sources per family, type, or variant.

Use a polymorphic relation.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| sourceable_type | string | `CatalogueFamily`, `CatalogueType`, `CatalogueVariant` |
| sourceable_id | bigint | polymorphic id |
| role | string | `primary`, `secondary`, `image_reference`, `variant_reference`, `manual_reference` |
| source_type | string | `official_brand`, `authorized_distributor`, `trusted_retailer`, `internal_manual` |
| url | text nullable | nullable for internal manual source |
| title | string nullable | source page title |
| notes | text nullable | |
| confidence | decimal(5,2) nullable | |
| is_primary | boolean | default false |
| is_verified | boolean | default false |
| verified_by | foreignId nullable | users |
| verified_at | timestamp nullable | |
| created_by | foreignId nullable | users |
| created_at / updated_at | timestamps | |

Indexes:

- index on `sourceable_type + sourceable_id`
- index on `source_type`
- index on `role`
- index on `is_verified`

### `catalogue_images`

Supports both uploaded shop photos and external image URLs.

Use a polymorphic relation.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| imageable_type | string | `CatalogueFamily`, `CatalogueType`, `CatalogueVariant`, `ImportRecord` |
| imageable_id | bigint | polymorphic id |
| image_role | string | `shop_photo`, `source_image`, `packaging`, `variant_image`, `evidence` |
| storage_disk | string nullable | for uploaded files |
| storage_path | string nullable | for uploaded files |
| external_url | text nullable | for external references |
| original_filename | string nullable | |
| mime_type | string nullable | |
| file_size | unsignedBigInteger nullable | |
| sort_order | integer | default 0 |
| is_primary | boolean | default false |
| source_id | foreignId nullable | optional link to `catalogue_sources` |
| notes | text nullable | |
| uploaded_by | foreignId nullable | users |
| created_at / updated_at | timestamps | |

Indexes:

- index on `imageable_type + imageable_id`
- index on `image_role`

Rules:

- At least one of `storage_path` or `external_url` must be present.
- Shop photos should usually be attached to the `ImportRecord` first, then optionally promoted to family or variant once identity is confirmed.

### `import_batches`

Represents one import session.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| batch_uuid | uuid | unique |
| import_channel | string | `paste`, `file_upload` |
| original_filename | string nullable | if file upload |
| source_label | string nullable | human note such as `Claude import 2026-03-17` |
| status | string | `received`, `parsed`, `partially_imported`, `failed`, `completed` |
| total_records | integer | default 0 |
| accepted_records | integer | default 0 |
| warning_records | integer | default 0 |
| rejected_records | integer | default 0 |
| imported_by | foreignId nullable | users |
| notes | text nullable | |
| created_at / updated_at | timestamps | |

### `import_records`

Represents one draft product record from an external JSON payload.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| import_batch_id | foreignId | parent batch |
| target_family_id | foreignId nullable | linked family after staging |
| external_reference | string nullable | source-side identifier if provided |
| status | string | `pending_parse`, `parsed`, `parsed_with_warnings`, `staged`, `rejected`, `merged` |
| raw_json | longText | original JSON record exactly as imported |
| normalized_json | json nullable | parsed normalized structure |
| payload_hash | string nullable | duplicate detection aid |
| import_confidence | decimal(5,2) nullable | from source draft |
| parse_warnings | json nullable | array of warning messages |
| import_notes | text nullable | |
| imported_by | foreignId nullable | users |
| staged_at | timestamp nullable | |
| created_at / updated_at | timestamps | |

Indexes:

- index on `import_batch_id`
- index on `target_family_id`
- index on `status`
- index on `payload_hash`

Notes:

- `raw_json` must preserve the original payload exactly.
- `normalized_json` stores the internal parsed format used for staging preview.

### `review_actions`

Audit log for review decisions and important workflow changes.

Use a polymorphic relation.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| reviewable_type | string | family, type, variant, import record |
| reviewable_id | bigint | polymorphic id |
| action | string | `submit`, `edit`, `approve`, `reject`, `mark_needs_research`, `merge`, `unmerge`, `verify_source`, `confirm_shop_match` |
| from_status | string nullable | |
| to_status | string nullable | |
| notes | text nullable | |
| metadata | json nullable | action details |
| acted_by | foreignId nullable | users |
| created_at | timestamp | |

Indexes:

- index on `reviewable_type + reviewable_id`
- index on `action`

### `duplicate_candidates`

Used to surface likely family duplicates for human review.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| left_family_id | foreignId | |
| right_family_id | foreignId | |
| similarity_score | decimal(5,2) | |
| match_basis | json nullable | e.g. same brand + normalized name |
| status | string | `open`, `ignored`, `merged`, `resolved_not_duplicate` |
| reviewed_by | foreignId nullable | users |
| reviewed_at | timestamp nullable | |
| notes | text nullable | |
| created_at / updated_at | timestamps | |

Indexes:

- unique on `left_family_id + right_family_id`
- index on `status`
- index on `similarity_score`

### `merge_events`

Tracks manual merges and preserves traceability.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| mergeable_type | string | `CatalogueFamily`, `CatalogueType`, `CatalogueVariant` |
| source_id | bigint | record being merged away |
| target_id | bigint | surviving record |
| notes | text nullable | |
| merged_by | foreignId nullable | users |
| merged_at | timestamp | |
| created_at / updated_at | timestamps | |

Indexes:

- index on `mergeable_type + source_id`
- index on `mergeable_type + target_id`

Rules:

- Source record should keep its history and move to archived or merged state.
- Child records and shop matches should be re-pointed to the surviving target.

### `shop_matches`

Represents whether the shop carries a family, type, or variant.

Use a polymorphic relation.

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint | PK |
| matchable_type | string | `CatalogueFamily`, `CatalogueType`, `CatalogueVariant` |
| matchable_id | bigint | polymorphic id |
| shop_match_status | string | `unknown`, `maybe`, `confirmed_yes`, `confirmed_no` |
| confidence | decimal(5,2) nullable | |
| confirmation_method | string nullable | `shelf_photo`, `physical_check`, `manager_confirmation`, `manual_assumption` |
| confirmed_by | foreignId nullable | users |
| confirmed_at | timestamp nullable | |
| notes | text nullable | |
| created_by | foreignId nullable | users |
| updated_by | foreignId nullable | users |
| created_at / updated_at | timestamps | |

Indexes:

- unique on `matchable_type + matchable_id`
- index on `shop_match_status`
- index on `confirmation_method`

Rules:

- This table is the only place where shop assortment status lives in v1.
- Do not overload catalogue record status with stocking state.

## Relationship Design

### `Category`

- hasMany `Subcategory`
- hasMany `CatalogueFamily`

### `Subcategory`

- belongsTo `Category`
- hasMany `CatalogueFamily`

### `Brand`

- hasMany `CatalogueFamily`

### `CatalogueFamily`

- belongsTo `Brand`
- belongsTo `Category`
- belongsTo `Subcategory`
- hasMany `CatalogueType`
- hasMany `CatalogueVariant`
- morphMany `CatalogueSource`
- morphMany `CatalogueImage`
- morphOne `ShopMatch`
- hasMany `ReviewAction`
- hasMany `ImportRecord`

### `CatalogueType`

- belongsTo `CatalogueFamily`
- hasMany `CatalogueVariant`
- morphMany `CatalogueSource`
- morphMany `CatalogueImage`
- morphOne `ShopMatch`
- hasMany `ReviewAction`

### `CatalogueVariant`

- belongsTo `CatalogueFamily`
- belongsTo `CatalogueType`
- morphMany `CatalogueSource`
- morphMany `CatalogueImage`
- morphOne `ShopMatch`
- hasMany `ReviewAction`

### `ImportBatch`

- hasMany `ImportRecord`
- belongsTo `User` as `importedBy`

### `ImportRecord`

- belongsTo `ImportBatch`
- belongsTo `CatalogueFamily` as `targetFamily`
- morphMany `CatalogueImage`
- hasMany `ReviewAction`

### `ShopMatch`

- morphTo `matchable`
- belongsTo `User` as `confirmedBy`

### `ReviewAction`

- morphTo `reviewable`
- belongsTo `User` as `actedBy`

### `DuplicateCandidate`

- belongsTo `CatalogueFamily` as `leftFamily`
- belongsTo `CatalogueFamily` as `rightFamily`
- belongsTo `User` as `reviewedBy`

### `MergeEvent`

- belongsTo `User` as `mergedBy`

## Status Rules

## Catalogue Family Status

Recommended enum values:

- `imported`
- `identified`
- `researching`
- `matched`
- `needs_review`
- `approved`
- `rejected`
- `archived`

Meaning:

- `imported`: raw draft has been staged
- `identified`: a likely family identity exists
- `researching`: source matching and enrichment are still ongoing
- `matched`: likely trusted source has been found
- `needs_review`: ready for human review
- `approved`: trusted catalogue record
- `rejected`: invalid or unwanted record
- `archived`: superseded, merged, or no longer used

Transition guidance:

- `imported -> identified`
- `identified -> researching`
- `researching -> matched`
- `matched -> needs_review`
- `needs_review -> approved`
- `needs_review -> rejected`
- any active state -> archived

Shortcuts:

- manual entry may start directly at `identified` or `needs_review`
- a high-confidence import with clear source may jump from `imported` to `matched`

## Catalogue Type Status

Use a lighter lifecycle aligned to its parent:

- `draft`
- `needs_review`
- `approved`
- `rejected`
- `archived`

Rules:

- a type cannot be `approved` if its family is not at least `needs_review`
- a type should usually be approved together with its family review

## Catalogue Variant Status

Recommended enum values:

- `draft`
- `inferred`
- `matched`
- `needs_review`
- `approved`
- `rejected`
- `archived`

Meaning:

- `draft`: manually added or imported but not yet trusted
- `inferred`: derived from source or pattern, not directly confirmed
- `matched`: found on trusted source
- `needs_review`: ready for approval
- `approved`: trusted sellable variant in catalogue master
- `rejected`: not part of the approved catalogue structure
- `archived`: merged or retired

Important rule:

- Do not use `stocked` or `not_stocked` as variant statuses.
- Those belong in `shop_matches` to preserve the catalogue versus shop separation.

## Shop Match Status

Enum values:

- `unknown`
- `maybe`
- `confirmed_yes`
- `confirmed_no`

Rules:

- every family, type, or variant may have one shop match record
- absence of a shop match record should be treated as `unknown`
- `confirmed_yes` and `confirmed_no` should capture `confirmed_by`, `confirmed_at`, and `confirmation_method` where possible

## Import Record Status

Enum values:

- `pending_parse`
- `parsed`
- `parsed_with_warnings`
- `staged`
- `rejected`
- `merged`

## Duplicate Candidate Status

Enum values:

- `open`
- `ignored`
- `merged`
- `resolved_not_duplicate`

## Source Trust Rules

Approved source order:

1. `official_brand`
2. `authorized_distributor`
3. `trusted_retailer`
4. `internal_manual`

Rules:

- AI-only suggestion never counts as an approved source.
- A family may have multiple sources.
- A family should not reach `approved` without at least one verified source or explicit internal manual confirmation.
- Generic or accessory products may be approved using `internal_manual` when no reliable public source exists.

## Duplicate Detection Rules

V1 duplicate detection should be basic and human-led.

Create duplicate candidates when:

- same brand and highly similar normalized family name
- same brand and same slug candidate
- same brand and same source URL
- same import payload hash

Suggested normalized family matching:

- lowercase
- trim whitespace
- remove punctuation
- collapse repeated spaces
- optionally remove common filler words such as `hair`, `pack`, `new`

Do not auto-merge in v1.

## Merge Rules

When merging families:

1. choose the surviving target family
2. re-point child types to target family
3. re-point variants to target family and correct type links
4. re-point shop matches to the target where appropriate
5. preserve source records and import links
6. log the operation in `merge_events`
7. mark the source family as archived and set `merged_into_family_id`

Equivalent rules apply for type and variant merges.

## External JSON Import Contract

## Import Strategy

The website does not call AI.

The workflow is:

1. AI is used outside the website
2. AI returns JSON
3. user pastes or uploads JSON
4. app validates shape
5. app stores raw payload
6. app normalizes and stages records
7. human reviews and approves

## Accepted Payload Shapes

V1 should accept:

- one single draft object
- one array of draft objects
- one wrapper object containing `items`

Internally, normalize all accepted inputs into:

```json
{
  "schema_version": "catalogue_draft.v1",
  "items": []
}
```

## Canonical Draft Object

```json
{
  "external_reference": "optional-string",
  "brand_name": "X-Pression",
  "category_name": "Hair",
  "subcategory_name": "Hair Extensions",
  "product_family_name": "Ultra Braid Stretched",
  "short_description": "Pre-stretched braiding hair line",
  "full_description": "Optional long description",
  "confidence": 0.91,
  "notes": "Imported from external AI research",
  "source_candidates": [
    {
      "role": "primary",
      "source_type": "official_brand",
      "url": "https://example.com/product",
      "title": "Ultra Braid Stretched",
      "confidence": 0.93,
      "notes": "Likely official page"
    }
  ],
  "image_refs": [
    {
      "image_role": "shop_photo",
      "external_url": "https://example.com/image.jpg",
      "notes": "Front packaging image"
    }
  ],
  "product_types": [
    {
      "name": "Value Pack",
      "description": "Optional structural type",
      "notes": "Optional notes",
      "variants": [
        {
          "variant_display_name": "20 inch / Color 1",
          "color_code": "1",
          "color_name": "Jet Black",
          "size": null,
          "length": "20 inch",
          "bundle_count": 1,
          "pack_size": null,
          "texture": null,
          "shade": null,
          "finish": null,
          "style": null,
          "weight": null,
          "volume": null,
          "attributes": {
            "material": "synthetic"
          },
          "confidence": 0.88,
          "notes": "Variant inferred from source"
        }
      ]
    }
  ],
  "variants": [
    {
      "variant_display_name": "18 inch / Color 1B",
      "color_code": "1B",
      "color_name": "Off Black",
      "size": null,
      "length": "18 inch",
      "bundle_count": 1,
      "pack_size": null,
      "texture": null,
      "shade": null,
      "finish": null,
      "style": null,
      "weight": null,
      "volume": null,
      "attributes": {},
      "confidence": 0.86,
      "notes": "Variant directly under family"
    }
  ],
  "shop_match": {
    "shop_match_status": "maybe",
    "confidence": 0.65,
    "confirmation_method": "shelf_photo",
    "notes": "Seen in shelf image but not confirmed"
  }
}
```

## Import Validation Rules

Reject only when the payload is structurally malformed, for example:

- invalid JSON
- top-level type is not object or array
- `items` exists but is not an array
- `variants` exists but is not an array
- `product_types` exists but is not an array
- `source_candidates` exists but is not an array
- `image_refs` exists but is not an array

Do not reject only because business fields are incomplete.

Instead, import with warnings when fields such as these are missing:

- `brand_name`
- `category_name`
- `product_family_name`
- source candidates
- variants

Examples of parse warnings:

- `Missing brand_name`
- `Missing category_name`
- `Missing product_family_name`
- `No source candidates provided`
- `No variants provided`
- `Unknown category_name value`

## Import Mapping Rules

### Brand

- if `brand_name` matches an existing brand, link it
- otherwise create a new brand as inactive or draft-reviewable
- for clearly unbranded items, link to a seeded generic brand or leave null pending review

### Category and Subcategory

- match against seeded category and subcategory names or slugs
- unknown values should not block import
- store warning and leave unresolved for review

### Family

- create or update a `catalogue_families` record with status:
  - `imported` when minimal identity is weak
  - `identified` when family name is present
  - `matched` when a likely trusted source exists
  - `needs_review` if importer is configured to push complete drafts into the queue immediately

### Types

- create `catalogue_types` only when `product_types` array is provided
- do not force a type row if the product family does not need one

### Variants

- attach variants either:
  - under a product type when nested there
  - directly under the family when supplied at top level

### Sources

- create `catalogue_sources` rows from `source_candidates`
- mark as unverified initially

### Images

- store external image refs in `catalogue_images`
- store uploaded files in `catalogue_images` with disk and path

### Shop Match

- optional import
- create `shop_matches` only if `shop_match` exists in the payload

## Review Workflow

## Main Screens

### Dashboard

Show:

- total brands
- total catalogue families
- total variants
- drafts pending review
- approved records
- unmatched products
- products needing source verification
- duplicate candidates

### Brands

Manage:

- name
- slug
- notes
- active or inactive
- generic flag

### Catalogue Families Index

Filters:

- status
- category
- subcategory
- brand
- needs source verification
- duplicate flag
- approved or not approved
- shop match status

Columns:

- family name
- brand
- category
- subcategory
- status
- source summary
- variant count
- shop match status
- updated at

### Family Detail / Review Page

Sections:

1. Overview
2. Sources
3. Images and shop photos
4. Product types
5. Variants
6. Shop matching
7. Import history
8. Review history
9. Duplicate candidates

Primary actions:

- save edits
- submit for review
- approve
- reject
- mark needs research
- archive
- merge into another family

### JSON Import Page

Supports:

- paste JSON
- upload JSON file
- preview parsed records
- validation messages
- import summary
- create draft staging records

Recommended flow:

1. paste or upload JSON
2. validate shape
3. show normalized preview
4. show warnings
5. confirm import
6. create import batch, import records, and staged family data

### Review Queue

Focuses on:

- families in `needs_review`
- imports with parse warnings
- records missing verified sources
- duplicate candidates requiring decision

Actions:

- open record
- approve
- reject
- merge
- send back to research

## Approval Rules

A family should be approvable when:

- a recognizable family identity exists
- category is assigned
- brand is assigned or intentionally generic/unbranded
- at least one trusted or internal verified source exists, unless specifically waived by business review
- variants are reviewed to an acceptable level for that family

A variant should be approvable when:

- it belongs to an existing family
- its sellable identity is clear
- its structural attribute combination is coherent
- optional type assignment is valid if present

## Export Shape

V1 should support:

- JSON export
- CSV export
- SQL-friendly row export

Exports should be limited to approved catalogue data by default.

## Export Dataset Structure

### Family export fields

- family_id
- brand_name
- category_name
- subcategory_name
- product_family_name
- short_description
- full_description
- status
- approved_at
- primary_source_url
- primary_source_type
- notes

### Type export fields

- type_id
- family_id
- type_name
- status

### Variant export fields

- variant_id
- family_id
- type_id
- variant_display_name
- color_code
- color_name
- size
- length
- bundle_count
- pack_size
- texture
- shade
- finish
- style
- weight
- volume
- attributes_json
- status

### Shop match export fields

- entity_type
- entity_id
- shop_match_status
- confirmation_method
- confirmed_at
- confirmed_by
- notes

### Source export fields

- entity_type
- entity_id
- role
- source_type
- url
- is_primary
- is_verified

## Recommended Implementation Order

1. create taxonomy tables: categories, subcategories, brands
2. create catalogue master tables: families, types, variants
3. create supporting tables: sources, images
4. create import tables: batches, records
5. create workflow tables: review actions, duplicate candidates, merge events
6. create assortment table: shop matches
7. build JSON import UI and parser
8. build family review page
9. build duplicate review and merge actions
10. build approved export endpoints

## Non-Goals for V1

- direct AI provider integration
- auto-scraping of websites
- barcode assignment
- SKU generation
- pricing
- stock counts
- purchase orders
- checkout
- supplier invoices

## Key Decisions Locked In

- external AI only, no in-app AI calls
- parent family, optional type, sellable variant
- multiple sources per record
- local shop photos and external images supported
- partial imports allowed
- review and approval required before trust
- shop assortment tracked separately from catalogue master
