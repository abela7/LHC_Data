<?php

declare(strict_types=1);

/**
 * Unified SKU scheme.
 *
 *   {DEPT}-{BRAND}-{FFFFF}{V}    e.g. HE-XPR-00012A
 *
 * - DEPT  : 2 uppercase letters, fixed map below (one entry per
 *           product_families.root_catalogue_name).
 * - BRAND : 3-4 uppercase letters, stored on brands.sku_code. Falls back to
 *           an auto-derived code when the brand has none set.
 * - FFFFF : 5-digit zero-padded family sequence, allocated per (DEPT, BRAND)
 *           and stored on product_families.sku_family_seq.
 * - V     : variant suffix, base-26 letters allocated inside a family in
 *           creation order (A, B, ... Z, AA, AB, ...).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Department codes
    |--------------------------------------------------------------------------
    |
    | Keyed by the value stored in product_families.root_catalogue_name. The
    | map is intentionally small (6 entries today) so it is trivial to keep
    | curated. Unknown departments fall back to `department_fallback`.
    |
    */
    'department_codes' => [
        'Hair Products'    => 'HP',
        'Hair Care'        => 'HC',
        'Hair Extensions'  => 'HE',
        'Body Care'        => 'BC',
        'Skin Care'        => 'SC',
        'Cosmetics'        => 'CS',
        'Accessories'      => 'AC',
        'Oral Care'        => 'OC',
        'Shop Products'    => 'GP',
        'General Products' => 'GP',
        'Electrical'       => 'EL',
        'Productized Picture Drafts' => 'GP',
        'Productized Retail Products' => 'GP',
    ],

    'department_fallback' => 'GP',

    /*
    |--------------------------------------------------------------------------
    | Brand code
    |--------------------------------------------------------------------------
    |
    | Target length when auto-deriving a brand code from the brand name. The
    | allocator will extend to `brand_code_max` when shorter codes collide
    | with an existing brand.
    |
    */
    'brand_code_length' => 3,
    'brand_code_max'    => 4,

    /*
    |--------------------------------------------------------------------------
    | Family sequence
    |--------------------------------------------------------------------------
    */
    'family_seq_width' => 5,

    /*
    |--------------------------------------------------------------------------
    | Generic brand fallback
    |--------------------------------------------------------------------------
    |
    | Used when a family has no brand attached (e.g. unbranded house goods).
    |
    */
    'generic_brand_code' => 'GEN',
];
