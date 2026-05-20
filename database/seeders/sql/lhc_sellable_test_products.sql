-- LHC sellable product test seed
-- Purpose: import realistic sellable products for POS/ecommerce/inventory software testing.
-- Safe identifiers:
--   SKUs use LHC-TEST-*.
--   Family/product slugs use lhc-test-*.
-- To remove this seed later, delete product_families where slug like 'lhc-test-%';
-- product children cascade through the foreign keys.

DROP TEMPORARY TABLE IF EXISTS lhc_test_sellable_seed;

CREATE TEMPORARY TABLE lhc_test_sellable_seed (
    seed_no INT PRIMARY KEY,
    root_catalogue_name VARCHAR(255) NOT NULL DEFAULT 'Hair Extensions',
    brand_name VARCHAR(255) NOT NULL,
    line_name VARCHAR(255) NULL,
    product_type_name VARCHAR(255) NULL,
    family_name VARCHAR(255) NOT NULL,
    family_slug VARCHAR(255) NOT NULL,
    family_description TEXT NULL,
    source_url VARCHAR(2000) NULL,
    product_name VARCHAR(255) NOT NULL,
    product_slug VARCHAR(255) NOT NULL,
    sku VARCHAR(255) NOT NULL,
    barcode VARCHAR(255) NOT NULL,
    receipt_name VARCHAR(255) NULL,
    inventory_name VARCHAR(255) NULL,
    product_description TEXT NULL,
    retail_price DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(2000) NOT NULL,
    length_label VARCHAR(255) NULL,
    colour_label VARCHAR(255) NULL,
    bundle_label VARCHAR(255) NULL
);

INSERT INTO lhc_test_sellable_seed
(seed_no, brand_name, line_name, product_type_name, family_name, family_slug, family_description, source_url, product_name, product_slug, sku, barcode, receipt_name, inventory_name, product_description, retail_price, image_url, length_label, colour_label, bundle_label)
VALUES
(1, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 1', 'cherish-bulk-brazilian-20-inch-colour-1', 'LHC-TEST-CHBR-20-001', '2099990000013', 'Cherish Brazilian 1', 'Cherish Bulk Brazilian - 20 inch - Colour 1', 'Bulk Brazilian textured hair in 20 inch length, Colour 1.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/1.png?v=9819110778786795257', '20 inch', '1', NULL),
(2, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 1B', 'cherish-bulk-brazilian-20-inch-colour-1b', 'LHC-TEST-CHBR-20-1B', '2099990000020', 'Cherish Brazilian 1B', 'Cherish Bulk Brazilian - 20 inch - Colour 1B', 'Bulk Brazilian textured hair in 20 inch length, Colour 1B.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/1b.png?v=10452069144337968406', '20 inch', '1B', NULL),
(3, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 2', 'cherish-bulk-brazilian-20-inch-colour-2', 'LHC-TEST-CHBR-20-002', '2099990000037', 'Cherish Brazilian 2', 'Cherish Bulk Brazilian - 20 inch - Colour 2', 'Bulk Brazilian textured hair in 20 inch length, Colour 2.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/2.png?v=17253831491054468573', '20 inch', '2', NULL),
(4, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 27', 'cherish-bulk-brazilian-20-inch-colour-27', 'LHC-TEST-CHBR-20-027', '2099990000044', 'Cherish Brazilian 27', 'Cherish Bulk Brazilian - 20 inch - Colour 27', 'Bulk Brazilian textured hair in 20 inch length, Colour 27.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/27.png?v=1505023408637373548', '20 inch', '27', NULL),
(5, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 30', 'cherish-bulk-brazilian-20-inch-colour-30', 'LHC-TEST-CHBR-20-030', '2099990000051', 'Cherish Brazilian 30', 'Cherish Bulk Brazilian - 20 inch - Colour 30', 'Bulk Brazilian textured hair in 20 inch length, Colour 30.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/30.png?v=6658586574804318518', '20 inch', '30', NULL),
(6, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 33', 'cherish-bulk-brazilian-20-inch-colour-33', 'LHC-TEST-CHBR-20-033', '2099990000068', 'Cherish Brazilian 33', 'Cherish Bulk Brazilian - 20 inch - Colour 33', 'Bulk Brazilian textured hair in 20 inch length, Colour 33.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/33.png?v=13045118928068158442', '20 inch', '33', NULL),
(7, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 4', 'cherish-bulk-brazilian-20-inch-colour-4', 'LHC-TEST-CHBR-20-004', '2099990000075', 'Cherish Brazilian 4', 'Cherish Bulk Brazilian - 20 inch - Colour 4', 'Bulk Brazilian textured hair in 20 inch length, Colour 4.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/4.png?v=12441539964259892882', '20 inch', '4', NULL),
(8, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 613', 'cherish-bulk-brazilian-20-inch-colour-613', 'LHC-TEST-CHBR-20-613', '2099990000082', 'Cherish Brazilian 613', 'Cherish Bulk Brazilian - 20 inch - Colour 613', 'Bulk Brazilian textured hair in 20 inch length, Colour 613.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/613.png?v=13181873777735557648', '20 inch', '613', NULL),
(9, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour 99J', 'cherish-bulk-brazilian-20-inch-colour-99j', 'LHC-TEST-CHBR-20-99J', '2099990000099', 'Cherish Brazilian 99J', 'Cherish Bulk Brazilian - 20 inch - Colour 99J', 'Bulk Brazilian textured hair in 20 inch length, Colour 99J.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/99j.png?v=7638736854035338534', '20 inch', '99J', NULL),
(10, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Brazilian', 'lhc-test-cherish-bulk-brazilian', 'Bulk Brazilian textured hair for protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Brazilian 20 inch - Colour P1B/30', 'cherish-bulk-brazilian-20-inch-colour-p1b-30', 'LHC-TEST-CHBR-20-P1B30', '2099990000105', 'Cherish Brazilian P1B/30', 'Cherish Bulk Brazilian - 20 inch - Colour P1B/30', 'Bulk Brazilian textured hair in 20 inch length, Colour P1B/30.', 3.99, 'https://shabacosmetics.com/cdn/shop/files/p1b30.png?v=12056937041224699482', '20 inch', 'P1B/30', NULL),
(11, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 1', 'cherish-bulk-deep-twist-22-inch-colour-1', 'LHC-TEST-CHDT-22-001', '2099990000112', 'Cherish Deep Twist 1', 'Cherish Bulk Deep Twist - 22 inch - Colour 1', 'Bulk Deep Twist hair in 22 inch length, Colour 1.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/653fdcfe4b4718.83446694.jpg?v=1763322210', '22 inch', '1', NULL),
(12, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 1B', 'cherish-bulk-deep-twist-22-inch-colour-1b', 'LHC-TEST-CHDT-22-1B', '2099990000129', 'Cherish Deep Twist 1B', 'Cherish Bulk Deep Twist - 22 inch - Colour 1B', 'Bulk Deep Twist hair in 22 inch length, Colour 1B.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/DP1B.jpg?v=1763322228', '22 inch', '1B', NULL),
(13, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 2', 'cherish-bulk-deep-twist-22-inch-colour-2', 'LHC-TEST-CHDT-22-002', '2099990000136', 'Cherish Deep Twist 2', 'Cherish Bulk Deep Twist - 22 inch - Colour 2', 'Bulk Deep Twist hair in 22 inch length, Colour 2.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/2_67f6873b-e268-4744-a697-c00371addb87.jpg?v=1761386267', '22 inch', '2', NULL),
(14, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 27', 'cherish-bulk-deep-twist-22-inch-colour-27', 'LHC-TEST-CHDT-22-027', '2099990000143', 'Cherish Deep Twist 27', 'Cherish Bulk Deep Twist - 22 inch - Colour 27', 'Bulk Deep Twist hair in 22 inch length, Colour 27.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/27.jpg?v=1761386311', '22 inch', '27', NULL),
(15, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 30', 'cherish-bulk-deep-twist-22-inch-colour-30', 'LHC-TEST-CHDT-22-030', '2099990000150', 'Cherish Deep Twist 30', 'Cherish Bulk Deep Twist - 22 inch - Colour 30', 'Bulk Deep Twist hair in 22 inch length, Colour 30.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/30.jpg?v=1761386364', '22 inch', '30', NULL),
(16, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 33', 'cherish-bulk-deep-twist-22-inch-colour-33', 'LHC-TEST-CHDT-22-033', '2099990000167', 'Cherish Deep Twist 33', 'Cherish Bulk Deep Twist - 22 inch - Colour 33', 'Bulk Deep Twist hair in 22 inch length, Colour 33.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/33.jpg?v=1761386479', '22 inch', '33', NULL),
(17, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 4', 'cherish-bulk-deep-twist-22-inch-colour-4', 'LHC-TEST-CHDT-22-004', '2099990000174', 'Cherish Deep Twist 4', 'Cherish Bulk Deep Twist - 22 inch - Colour 4', 'Bulk Deep Twist hair in 22 inch length, Colour 4.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/4_abbc4b2b-78c4-463c-b4a0-4092a7e383f6.jpg?v=1761386692', '22 inch', '4', NULL),
(18, 'Cherish', NULL, 'Bulk Hair', 'Cherish Bulk Deep Twist', 'lhc-test-cherish-bulk-deep-twist', 'Bulk Deep Twist hair for textured protective styling, supplied in colour and length variants.', 'https://shabacosmetics.com/collections/cherish', 'Cherish Bulk Deep Twist 22 inch - Colour 613', 'cherish-bulk-deep-twist-22-inch-colour-613', 'LHC-TEST-CHDT-22-613', '2099990000181', 'Cherish Deep Twist 613', 'Cherish Bulk Deep Twist - 22 inch - Colour 613', 'Bulk Deep Twist hair in 22 inch length, Colour 613.', 4.49, 'https://cdn.shopify.com/s/files/1/0787/0234/6582/files/613.jpg?v=1761386774', '22 inch', '613', NULL),
(19, 'X-Pression', 'X-Pression Braids', 'Braid', 'X-Pression Braids Pre-Stretched Ultraviolet', 'lhc-test-x-pression-braids-pre-stretched-ultraviolet', 'Pre-stretched ultraviolet braid range with bright colour variants.', 'https://feme.com/x-pression-pre-stretched-ultraviolet/', 'X-Pression Pre-Stretched Ultraviolet - 46 inch - Colour UV-PINK', 'x-pression-pre-stretched-ultraviolet-46-inch-colour-uv-pink', 'LHC-TEST-XPUV-46-PINK', '2099990000198', 'X-Pression UV Pink', 'X-Pression Pre-Stretched Ultraviolet - 46 inch - UV-PINK', 'Pre-stretched ultraviolet braid in 46 inch length, Colour UV-PINK.', 2.99, 'https://cdn11.bigcommerce.com/s-p1mnzjw5yo/images/stencil/1280x1280/products/1039/2674/UV_Xpressions_28__48451.1681724149.1280.1280__47371.1684134631.jpg?c=1', '46 inch', 'UV-PINK', NULL),
(20, 'X-Pression', 'X-Pression Braids', 'Braid', 'X-Pression Braids Pre-Stretched Ultraviolet', 'lhc-test-x-pression-braids-pre-stretched-ultraviolet', 'Pre-stretched ultraviolet braid range with bright colour variants.', 'https://feme.com/x-pression-pre-stretched-ultraviolet/', 'X-Pression Pre-Stretched Ultraviolet - 46 inch - Colour UV-Yellow', 'x-pression-pre-stretched-ultraviolet-46-inch-colour-uv-yellow', 'LHC-TEST-XPUV-46-YELLOW', '2099990000204', 'X-Pression UV Yellow', 'X-Pression Pre-Stretched Ultraviolet - 46 inch - UV-Yellow', 'Pre-stretched ultraviolet braid in 46 inch length, Colour UV-Yellow.', 2.99, 'https://cdn11.bigcommerce.com/s-p1mnzjw5yo/images/stencil/1280x1280/products/1039/2674/UV_Xpressions_28__48451.1681724149.1280.1280__47371.1684134631.jpg?c=1', '46 inch', 'UV-Yellow', NULL);

INSERT INTO lhc_test_sellable_seed
(seed_no, root_catalogue_name, brand_name, line_name, product_type_name, family_name, family_slug, family_description, source_url, product_name, product_slug, sku, barcode, receipt_name, inventory_name, product_description, retail_price, image_url, length_label, colour_label, bundle_label)
VALUES
(21, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 10 CRYSTAL CLEAR', 'adore-semi-permanent-hair-dye-colour-10-crystal-clear', 'LHC-TEST-ADORE-010', '2099990000211', 'Adore Crystal Clear', 'Adore Semi Permanent Hair Dye Colour - 10 CRYSTAL CLEAR', 'Semi-permanent hair colour shade 10 CRYSTAL CLEAR.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_10CrystalClear.jpg?v=1668544178', NULL, '10 CRYSTAL CLEAR', NULL),
(22, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 121 JET BLACK', 'adore-semi-permanent-hair-dye-colour-121-jet-black', 'LHC-TEST-ADORE-121', '2099990000228', 'Adore Jet Black', 'Adore Semi Permanent Hair Dye Colour - 121 JET BLACK', 'Semi-permanent hair colour shade 121 JET BLACK.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_121JetBlack.jpg?v=1668620511', NULL, '121 JET BLACK', NULL),
(23, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 140 NEON PINK', 'adore-semi-permanent-hair-dye-colour-140-neon-pink', 'LHC-TEST-ADORE-140', '2099990000235', 'Adore Neon Pink', 'Adore Semi Permanent Hair Dye Colour - 140 NEON PINK', 'Semi-permanent hair colour shade 140 NEON PINK.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_140NeonPink.jpg?v=1668620553', NULL, '140 NEON PINK', NULL),
(24, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 117 AQUAMARINE', 'adore-semi-permanent-hair-dye-colour-117-aquamarine', 'LHC-TEST-ADORE-117', '2099990000242', 'Adore Aquamarine', 'Adore Semi Permanent Hair Dye Colour - 117 AQUAMARINE', 'Semi-permanent hair colour shade 117 AQUAMARINE.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_117Aquamarine.jpg?v=1668620434', NULL, '117 AQUAMARINE', NULL),
(25, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 164 ELECTRIC LIME', 'adore-semi-permanent-hair-dye-colour-164-electric-lime', 'LHC-TEST-ADORE-164', '2099990000259', 'Adore Electric Lime', 'Adore Semi Permanent Hair Dye Colour - 164 ELECTRIC LIME', 'Semi-permanent hair colour shade 164 ELECTRIC LIME.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_164ElectricLime.jpg?v=1668620638', NULL, '164 ELECTRIC LIME', NULL),
(26, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 76 COPPER BROWN', 'adore-semi-permanent-hair-dye-colour-76-copper-brown', 'LHC-TEST-ADORE-076', '2099990000266', 'Adore Copper Brown', 'Adore Semi Permanent Hair Dye Colour - 76 COPPER BROWN', 'Semi-permanent hair colour shade 76 COPPER BROWN.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_76CopperBrown.jpg?v=1668620209', NULL, '76 COPPER BROWN', NULL),
(27, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 70 RAGING RED', 'adore-semi-permanent-hair-dye-colour-70-raging-red', 'LHC-TEST-ADORE-070', '2099990000273', 'Adore Raging Red', 'Adore Semi Permanent Hair Dye Colour - 70 RAGING RED', 'Semi-permanent hair colour shade 70 RAGING RED.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_70RagingRed.jpg?v=1668544420', NULL, '70 RAGING RED', NULL),
(28, 'Hair Colour', 'Adore', NULL, 'Semi-Permanent Hair Colour', 'Adore Semi Permanent Hair Dye Colour', 'lhc-test-adore-semi-permanent-hair-dye-colour', 'Semi-permanent hair colour range supplied in individual shade variants.', 'https://www.beautyflex.co.uk/products/adore-semi-permanent-hair-dye-colour-all-shades', 'Adore Semi Permanent Hair Dye Colour - 113 AFRICAN VIOLET', 'adore-semi-permanent-hair-dye-colour-113-african-violet', 'LHC-TEST-ADORE-113', '2099990000280', 'Adore African Violet', 'Adore Semi Permanent Hair Dye Colour - 113 AFRICAN VIOLET', 'Semi-permanent hair colour shade 113 AFRICAN VIOLET.', 6.99, 'https://cdn.shopify.com/s/files/1/0528/8640/5320/products/AdoreShiningSemiPermanentHairColour_113AfricanViolet.jpg?v=1668620390', NULL, '113 AFRICAN VIOLET', NULL),
(29, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Canary Yellow', 'crazy-color-semi-permanent-hair-dye-canary-yellow', 'LHC-TEST-CRAZY-CANARY', '2099990000297', 'Crazy Canary Yellow', 'Crazy Color Semi-Permanent Hair Dye - Canary Yellow', 'Semi-permanent hair dye shade Canary Yellow.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0DB2KDCHF.MAIN.png?v=1747648916', NULL, 'Canary Yellow', NULL),
(30, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Capri Blue', 'crazy-color-semi-permanent-hair-dye-capri-blue', 'LHC-TEST-CRAZY-CAPRI', '2099990000303', 'Crazy Capri Blue', 'Crazy Color Semi-Permanent Hair Dye - Capri Blue', 'Semi-permanent hair dye shade Capri Blue.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0DB2KVWZ3.MAIN.png?v=1747649305', NULL, 'Capri Blue', NULL),
(31, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Cyclamen', 'crazy-color-semi-permanent-hair-dye-cyclamen', 'LHC-TEST-CRAZY-CYCLAMEN', '2099990000310', 'Crazy Cyclamen', 'Crazy Color Semi-Permanent Hair Dye - Cyclamen', 'Semi-permanent hair dye shade Cyclamen.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0D31RL2TV.MAIN.png?v=1747650259', NULL, 'Cyclamen', NULL),
(32, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Fire', 'crazy-color-semi-permanent-hair-dye-fire', 'LHC-TEST-CRAZY-FIRE', '2099990000327', 'Crazy Fire', 'Crazy Color Semi-Permanent Hair Dye - Fire', 'Semi-permanent hair dye shade Fire.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0D31Q9LGK.MAIN.png?v=1747650797', NULL, 'Fire', NULL),
(33, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Lime Twist', 'crazy-color-semi-permanent-hair-dye-lime-twist', 'LHC-TEST-CRAZY-LIME', '2099990000334', 'Crazy Lime Twist', 'Crazy Color Semi-Permanent Hair Dye - Lime Twist', 'Semi-permanent hair dye shade Lime Twist.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0DB2LW57D.MAIN.png?v=1747655876', NULL, 'Lime Twist', NULL),
(34, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Pinkissimo', 'crazy-color-semi-permanent-hair-dye-pinkissimo', 'LHC-TEST-CRAZY-PINKISSIMO', '2099990000341', 'Crazy Pinkissimo', 'Crazy Color Semi-Permanent Hair Dye - Pinkissimo', 'Semi-permanent hair dye shade Pinkissimo.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0DB2KLSVN.MAIN.png?v=1747662941', NULL, 'Pinkissimo', NULL),
(35, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Silver', 'crazy-color-semi-permanent-hair-dye-silver', 'LHC-TEST-CRAZY-SILVER', '2099990000358', 'Crazy Silver', 'Crazy Color Semi-Permanent Hair Dye - Silver', 'Semi-permanent hair dye shade Silver.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0DB2L7VSN.MAIN.png?v=1747664006', NULL, 'Silver', NULL),
(36, 'Hair Colour', 'Crazy Color', NULL, 'Semi-Permanent Hair Colour', 'Crazy Color Semi-Permanent Hair Dye', 'lhc-test-crazy-color-semi-permanent-hair-dye', 'Semi-permanent hair dye range supplied in individual shade variants.', 'https://www.crazycolor.co.uk/', 'Crazy Color Semi-Permanent Hair Dye - Violette', 'crazy-color-semi-permanent-hair-dye-violette', 'LHC-TEST-CRAZY-VIOLETTE', '2099990000365', 'Crazy Violette', 'Crazy Color Semi-Permanent Hair Dye - Violette', 'Semi-permanent hair dye shade Violette.', 5.99, 'https://cdn.shopify.com/s/files/1/0907/0087/4107/files/B0DB2LM7Q4.MAIN.png?v=1747665798', NULL, 'Violette', NULL),
(37, 'Hair Colour', 'Creme of Nature', NULL, 'Permanent Hair Colour', 'Creme of Nature Moisture Rich Hair Color', 'lhc-test-creme-of-nature-moisture-rich-hair-color', 'Moisture rich permanent hair colour range supplied in individual shade variants.', 'https://shabacosmetics.com/collections/creme-of-nature', 'Creme of Nature Moisture Rich Hair Color - C41 Honey Blonde', 'creme-of-nature-moisture-rich-hair-color-c41-honey-blonde', 'LHC-TEST-CON-C41', '2099990000372', 'CON Honey Blonde', 'Creme of Nature Moisture Rich Hair Color - C41 Honey Blonde', 'Moisture rich permanent hair colour shade C41 Honey Blonde.', 7.99, 'https://shabacosmetics.com/cdn/shop/files/075724640412-980x980.png?v=1698386607', NULL, 'C41 Honey Blonde', NULL),
(38, 'Hair Colour', 'Creme of Nature', NULL, 'Permanent Hair Colour', 'Creme of Nature Moisture Rich Hair Color', 'lhc-test-creme-of-nature-moisture-rich-hair-color', 'Moisture rich permanent hair colour range supplied in individual shade variants.', 'https://shabacosmetics.com/collections/creme-of-nature', 'Creme of Nature Moisture Rich Hair Color - C10 Jet Black', 'creme-of-nature-moisture-rich-hair-color-c10-jet-black', 'LHC-TEST-CON-C10', '2099990000389', 'CON Jet Black', 'Creme of Nature Moisture Rich Hair Color - C10 Jet Black', 'Moisture rich permanent hair colour shade C10 Jet Black.', 7.99, 'https://shabacosmetics.com/cdn/shop/files/075724640108-980x980.png?v=1698385469', NULL, 'C10 Jet Black', NULL),
(39, 'Hair Colour', 'Creme of Nature', NULL, 'Permanent Hair Colour', 'Creme of Nature Moisture Rich Hair Color', 'lhc-test-creme-of-nature-moisture-rich-hair-color', 'Moisture rich permanent hair colour range supplied in individual shade variants.', 'https://shabacosmetics.com/collections/creme-of-nature', 'Creme of Nature Moisture Rich Hair Color - C11 Natural Black', 'creme-of-nature-moisture-rich-hair-color-c11-natural-black', 'LHC-TEST-CON-C11', '2099990000396', 'CON Natural Black', 'Creme of Nature Moisture Rich Hair Color - C11 Natural Black', 'Moisture rich permanent hair colour shade C11 Natural Black.', 7.99, 'https://shabacosmetics.com/cdn/shop/files/075724780118-980x980.png?v=1698385666', NULL, 'C11 Natural Black', NULL),
(40, 'Hair Colour', 'Creme of Nature', NULL, 'Permanent Hair Colour', 'Creme of Nature Moisture Rich Hair Color', 'lhc-test-creme-of-nature-moisture-rich-hair-color', 'Moisture rich permanent hair colour range supplied in individual shade variants.', 'https://shabacosmetics.com/collections/creme-of-nature', 'Creme of Nature Moisture Rich Hair Color - C30 Red Hot Burgundy', 'creme-of-nature-moisture-rich-hair-color-c30-red-hot-burgundy', 'LHC-TEST-CON-C30', '2099990000402', 'CON Red Burgundy', 'Creme of Nature Moisture Rich Hair Color - C30 Red Hot Burgundy', 'Moisture rich permanent hair colour shade C30 Red Hot Burgundy.', 7.99, 'https://shabacosmetics.com/cdn/shop/files/CremeofNatureHairColorC30RedHotBurgundy_1022x1022_jpg.webp?v=1742574208', NULL, 'C30 Red Hot Burgundy', NULL);

START TRANSACTION;

INSERT INTO product_families
(brand_id, brand_catalogue_id, brand_catalogue_brand_id, brand_catalogue_line_id, brand_catalogue_product_type_id, brand_catalogue_style_id, catalogue_scope_key, root_catalogue_name, brand_name, line_name, product_type_name, family_name, slug, description, source_url, status, published_at, sort_order, created_at, updated_at)
SELECT
    NULL, NULL, NULL, NULL, NULL, NULL, NULL,
    root_catalogue_name,
    brand_name,
    line_name,
    product_type_name,
    family_name,
    family_slug,
    family_description,
    source_url,
    'published',
    NOW(),
    MIN(seed_no),
    NOW(),
    NOW()
FROM lhc_test_sellable_seed
GROUP BY root_catalogue_name, brand_name, line_name, product_type_name, family_name, family_slug, family_description, source_url
ON DUPLICATE KEY UPDATE
    brand_name = VALUES(brand_name),
    line_name = VALUES(line_name),
    product_type_name = VALUES(product_type_name),
    family_name = VALUES(family_name),
    description = VALUES(description),
    source_url = VALUES(source_url),
    status = 'published',
    published_at = COALESCE(product_families.published_at, NOW()),
    updated_at = NOW();

INSERT INTO product_variant_groups
(product_family_id, brand_catalogue_variant_id, name, variant_type, sort_order, created_at, updated_at)
SELECT DISTINCT pf.id, NULL, 'Length', 'text', 10, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
WHERE s.length_label IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO product_variant_groups
(product_family_id, brand_catalogue_variant_id, name, variant_type, sort_order, created_at, updated_at)
SELECT DISTINCT pf.id, NULL, 'Colour', 'text', 20, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
WHERE s.colour_label IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO product_variant_groups
(product_family_id, brand_catalogue_variant_id, name, variant_type, sort_order, created_at, updated_at)
SELECT DISTINCT pf.id, NULL, 'Bundle', 'text', 30, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
WHERE s.bundle_label IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO product_variant_options
(product_variant_group_id, brand_catalogue_variant_option_id, label, value, sort_order, created_at, updated_at)
SELECT pvg.id, NULL, s.length_label, s.length_label, MIN(s.seed_no), NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
JOIN product_variant_groups pvg ON pvg.product_family_id = pf.id AND pvg.name = 'Length'
WHERE s.length_label IS NOT NULL
GROUP BY pvg.id, s.length_label
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW();

INSERT INTO product_variant_options
(product_variant_group_id, brand_catalogue_variant_option_id, label, value, sort_order, created_at, updated_at)
SELECT pvg.id, NULL, s.colour_label, s.colour_label, MIN(s.seed_no), NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
JOIN product_variant_groups pvg ON pvg.product_family_id = pf.id AND pvg.name = 'Colour'
WHERE s.colour_label IS NOT NULL
GROUP BY pvg.id, s.colour_label
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW();

INSERT INTO product_variant_options
(product_variant_group_id, brand_catalogue_variant_option_id, label, value, sort_order, created_at, updated_at)
SELECT pvg.id, NULL, s.bundle_label, s.bundle_label, MIN(s.seed_no), NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
JOIN product_variant_groups pvg ON pvg.product_family_id = pf.id AND pvg.name = 'Bundle'
WHERE s.bundle_label IS NOT NULL
GROUP BY pvg.id, s.bundle_label
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW();

INSERT INTO products
(product_family_id, brand_id, brand_catalogue_sku_id, name, slug, sku, barcode, receipt_name, inventory_name, search_keywords, description, status, is_pos_active, is_ecommerce_active, is_inventory_tracked, sort_order, created_at, updated_at)
SELECT
    pf.id,
    NULL,
    NULL,
    s.product_name,
    s.product_slug,
    s.sku,
    s.barcode,
    s.receipt_name,
    s.inventory_name,
    CONCAT_WS(' ', s.brand_name, s.family_name, s.product_name, s.length_label, s.colour_label, s.bundle_label),
    s.product_description,
    'published',
    1,
    1,
    1,
    s.seed_no,
    NOW(),
    NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    barcode = VALUES(barcode),
    receipt_name = VALUES(receipt_name),
    inventory_name = VALUES(inventory_name),
    search_keywords = VALUES(search_keywords),
    description = VALUES(description),
    status = 'published',
    is_pos_active = 1,
    is_ecommerce_active = 1,
    is_inventory_tracked = 1,
    sort_order = VALUES(sort_order),
    updated_at = NOW();

INSERT IGNORE INTO product_variant_values
(product_id, product_variant_group_id, product_variant_option_id, created_at, updated_at)
SELECT p.id, pvg.id, pvo.id, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
JOIN product_variant_groups pvg ON pvg.product_family_id = p.product_family_id AND pvg.name = 'Length'
JOIN product_variant_options pvo ON pvo.product_variant_group_id = pvg.id AND pvo.label = s.length_label
WHERE s.length_label IS NOT NULL;

INSERT IGNORE INTO product_variant_values
(product_id, product_variant_group_id, product_variant_option_id, created_at, updated_at)
SELECT p.id, pvg.id, pvo.id, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
JOIN product_variant_groups pvg ON pvg.product_family_id = p.product_family_id AND pvg.name = 'Colour'
JOIN product_variant_options pvo ON pvo.product_variant_group_id = pvg.id AND pvo.label = s.colour_label
WHERE s.colour_label IS NOT NULL;

INSERT IGNORE INTO product_variant_values
(product_id, product_variant_group_id, product_variant_option_id, created_at, updated_at)
SELECT p.id, pvg.id, pvo.id, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
JOIN product_variant_groups pvg ON pvg.product_family_id = p.product_family_id AND pvg.name = 'Bundle'
JOIN product_variant_options pvo ON pvo.product_variant_group_id = pvg.id AND pvo.label = s.bundle_label
WHERE s.bundle_label IS NOT NULL;

INSERT INTO product_prices
(product_id, retail_price, compare_at_price, cost_price, currency, tax_class, vat_rate, price_notes, created_at, updated_at)
SELECT p.id, s.retail_price, NULL, NULL, 'GBP', 'standard', 20.00, 'Test seed price', NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
ON DUPLICATE KEY UPDATE
    retail_price = VALUES(retail_price),
    currency = 'GBP',
    tax_class = 'standard',
    vat_rate = 20.00,
    price_notes = 'Test seed price',
    updated_at = NOW();

INSERT INTO product_media
(product_family_id, product_id, catalogue_image_id, image_role, source_type, source_label, usage_context, external_url, storage_disk, storage_path, alt_text, original_filename, mime_type, file_size, notes, is_primary, is_offline_ready, sort_order, created_at, updated_at)
SELECT p.product_family_id, p.id, NULL, 'main', 'external_url', 'LHC test seed', 'all', s.image_url, NULL, NULL, s.product_name, NULL, NULL, NULL, 'Variant image for test sellable product', 1, 0, 10, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
WHERE NOT EXISTS (
    SELECT 1
    FROM product_media pm
    WHERE pm.product_id = p.id
      AND pm.image_role = 'main'
      AND pm.external_url = s.image_url
);

INSERT INTO product_media
(product_family_id, product_id, catalogue_image_id, image_role, source_type, source_label, usage_context, external_url, storage_disk, storage_path, alt_text, original_filename, mime_type, file_size, notes, is_primary, is_offline_ready, sort_order, created_at, updated_at)
SELECT pf.id, NULL, NULL, 'main', 'external_url', 'LHC test seed', 'family', MIN(s.image_url), NULL, NULL, pf.family_name, NULL, NULL, NULL, 'Family image for test sellable products', 1, 0, 1, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN product_families pf ON pf.slug = s.family_slug
WHERE NOT EXISTS (
    SELECT 1
    FROM product_media pm
    WHERE pm.product_family_id = pf.id
      AND pm.product_id IS NULL
      AND pm.image_role = 'main'
      AND pm.source_label = 'LHC test seed'
)
GROUP BY pf.id, pf.family_name;

INSERT INTO product_pos_profiles
(product_id, receipt_name, quick_search_keywords, pos_category, discount_allowed, quick_sale_enabled, tax_class, created_at, updated_at)
SELECT p.id, s.receipt_name, CONCAT_WS(' ', s.brand_name, s.family_name, s.colour_label), s.root_catalogue_name, 1, 1, 'standard', NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
ON DUPLICATE KEY UPDATE
    receipt_name = VALUES(receipt_name),
    quick_search_keywords = VALUES(quick_search_keywords),
    pos_category = VALUES(pos_category),
    discount_allowed = 1,
    quick_sale_enabled = 1,
    tax_class = 'standard',
    updated_at = NOW();

INSERT INTO product_ecommerce_profiles
(product_family_id, product_id, profile_level, online_title, short_description, long_description, seo_slug, seo_title, seo_description, tags, is_published, click_and_collect_enabled, shipping_weight, shipping_dimensions, created_at, updated_at)
SELECT pf.id, NULL, 'family', pf.family_name, s.family_description, s.family_description, s.family_slug, pf.family_name, s.family_description, '["test-seed","hair-extension"]', 1, 1, NULL, NULL, NOW(), NOW()
FROM (
    SELECT family_slug, MIN(family_description) AS family_description
    FROM lhc_test_sellable_seed
    GROUP BY family_slug
) s
JOIN product_families pf ON pf.slug = s.family_slug
WHERE NOT EXISTS (
    SELECT 1
    FROM product_ecommerce_profiles pep
    WHERE pep.product_family_id = pf.id
      AND pep.product_id IS NULL
      AND pep.profile_level = 'family'
      AND pep.seo_slug = s.family_slug
);

INSERT INTO product_ecommerce_profiles
(product_family_id, product_id, profile_level, online_title, short_description, long_description, seo_slug, seo_title, seo_description, tags, is_published, click_and_collect_enabled, shipping_weight, shipping_dimensions, created_at, updated_at)
SELECT p.product_family_id, p.id, 'sku', s.product_name, s.product_description, s.product_description, s.product_slug, s.product_name, s.product_description, '["test-seed","hair-extension","variant"]', 1, 1, NULL, NULL, NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
WHERE NOT EXISTS (
    SELECT 1
    FROM product_ecommerce_profiles pep
    WHERE pep.product_id = p.id
      AND pep.profile_level = 'sku'
      AND pep.seo_slug = s.product_slug
);

INSERT INTO product_sources
(product_family_id, product_id, source_type, source_table, source_id, source_url, confidence, notes, created_at, updated_at)
SELECT p.product_family_id, p.id, 'test_seed', 'lhc_sellable_test_products_sql', s.seed_no, s.source_url, 'A', 'Inserted from LHC sellable product test seed.', NOW(), NOW()
FROM lhc_test_sellable_seed s
JOIN products p ON p.sku = s.sku
WHERE NOT EXISTS (
    SELECT 1
    FROM product_sources ps
    WHERE ps.product_id = p.id
      AND ps.source_type = 'test_seed'
      AND ps.source_table = 'lhc_sellable_test_products_sql'
      AND ps.source_id = s.seed_no
);

COMMIT;

SELECT
    'LHC test sellable products imported' AS result,
    COUNT(*) AS product_count
FROM products
WHERE sku LIKE 'LHC-TEST-%';
