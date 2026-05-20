<?php

use App\Models\HairExtensionIntake;
use App\Models\HairExtensionIntakeAiSuggestion;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$intakeId = 24;

$intake = HairExtensionIntake::query()->findOrFail($intakeId);

$groups = [
    [
        'label' => '3X Bundle 14/16/18 inches',
        'pack_count' => '3X',
        'length' => '14/16/18 inches',
        'colour_values' => [
            '1', '1B', '2', '4', '27', '30', '613', 'T27', 'T30',
            'T350', 'T530', 'P4/27', 'P4/30', 'P4/27/30',
            'P27/30/613', '6', '8', '51',
        ],
        'source_sheet' => '3X Bundle (14/16/18") Pre-Stretched Spiral French Curl',
    ],
    [
        'label' => '4X 18 inches',
        'pack_count' => '4X',
        'length' => '18 inches',
        'colour_values' => [
            '1', '1B', '2', '4', 'T27', 'T30', 'BG', '613', '27',
            '30', 'T350', 'T530',
        ],
        'source_sheet' => '4X Pre-Stretched Spiral French Curl 18"',
    ],
    [
        'label' => '3X 22 inches',
        'pack_count' => '3X',
        'length' => '22 inches',
        'colour_values' => [
            '1', '1B', '2', '4', '613', 'T27', 'T30', 'T530', 'TCOPPER',
            'T4/30', 'T27/613', 'T30/27', 'T33/30', 'T33/350',
            'T4/30/27', 'T1B/30/33', '27', '30', '33', 'T350',
            'BLACKGOLD', 'VANILLA', '3T1B27613', '3T1B99J530',
            '3T1B3027', 'RED', 'ORANGE', 'HOT PINK', 'ROSE WINE',
            'ASH BLONDE', 'ASH LATTE', 'ASH PLATINUM', '130', 'CB27613',
            'CB3033', 'P4/27', 'P4/30', 'P4/27/30', 'P27/30/613',
            '6', '8', '51',
        ],
        'source_sheet' => '3X Pre-Stretched Spiral French Curl 22"',
    ],
    [
        'label' => '3X 28 inches',
        'pack_count' => '3X',
        'length' => '28 inches',
        'colour_values' => [
            '1', '1B', '2', '4', '613', 'T27', 'T30', 'T530', 'TCOPPER',
            'T4/30', 'T27/613', 'T30/27', 'T33/30', 'T33/350',
            'T4/30/27', 'T1B/30/33', '27', '30', 'BLACKGOLD', 'VANILLA',
            '3T1B27613', '3T1B99J530', '3T1B3027', 'RED', 'ORANGE',
            'HOT PINK', 'ROSE WINE', 'ASH BLONDE', 'P4/27', 'P4/30',
            'P4/27/30', 'P27/30/613', '6', '8', '51',
        ],
        'source_sheet' => '3X Pre-Stretched Spiral French Curl 28"',
    ],
];

$suggestion = [
    'kind' => 'catalogue_structure_suggestion',
    'source_type' => 'user_supplied_manufacturer_sheets',
    'confidence' => 'A',
    'brand' => 'Cherish',
    'supplier' => 'Mamado International',
    'product_type' => 'Braiding Hair',
    'style_family' => 'Spiral French Curl',
    'family_name' => 'Cherish Spiral French Curl Pre-Stretched',
    'observed_product_name' => $intake->observed_product_name,
    'variant_axes' => ['Pack count', 'Length', 'Colour'],
    'proposed_variant_groups' => $groups,
    'proposed_sellable_sku_count' => array_sum(array_map(
        fn (array $group): int => count($group['colour_values']),
        $groups,
    )),
    'catalogue_decision' => [
        'publish_ready' => false,
        'review_required' => true,
        'reason' => 'Manufacturer range is clear, but shop-owner must confirm which lengths/colours are actually stocked before publishing real SKUs.',
    ],
    'normalisation_notes' => [
        'Keep manufacturer colour codes exactly as printed at this suggestion stage.',
        'BG and BLACKGOLD may be the same colour family but are kept separate until human review.',
        'T4/3027 from the shop intake should be reviewed against manufacturer code T4/30/27.',
        'The 14/16/18 entry is a mixed-length 3X bundle, not three separate single-length SKUs unless the shop confirms otherwise.',
    ],
];

$record = HairExtensionIntakeAiSuggestion::query()->updateOrCreate(
    [
        'hair_extension_intake_id' => $intake->id,
        'provider' => 'manual',
        'model' => 'mamado-manufacturer-sheet',
    ],
    [
        'brand_name' => 'Cherish',
        'observed_product_name' => $intake->observed_product_name,
        'source_url' => 'https://www.mamado.co.uk/',
        'status' => 'completed',
        'confidence' => 'A',
        'suggestion' => $suggestion,
        'source_urls' => [
            'https://www.mamado.co.uk/',
            'https://www.mamadoUSA.com/',
        ],
        'raw_response' => json_encode($suggestion, JSON_PRETTY_PRINT),
        'error_message' => null,
        'prompt_hash' => hash('sha256', 'cherish-spiral-french-curl-mamado-manufacturer-sheets-v1'),
    ],
);

echo "Saved suggestion {$record->id} for intake {$intake->id} with {$suggestion['proposed_sellable_sku_count']} proposed SKUs.".PHP_EOL;

