<?php

/*
 * Pink-Commerce bridge configuration.
 *
 * When enabled, publishing a product family in this app also pushes it to the
 * Pink-Commerce (Railway) API and uploads its images to Cloudflare R2.
 * Leave PINKCOMMERCE_ENABLED=false (default) until the env vars below are set,
 * so pulling this code never disrupts the existing publish flow.
 */

return [
    'enabled' => filter_var(env('PINKCOMMERCE_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Base URL of the Pink-Commerce API (no trailing slash), e.g. https://api-production-5c17.up.railway.app
    'api_url' => rtrim((string) env('PINKCOMMERCE_API_URL', ''), '/'),

    // Shared ingest token (matches LHC_INGEST_TOKEN on the Pink-Commerce API).
    'ingest_token' => (string) env('PINKCOMMERCE_INGEST_TOKEN', ''),

    // Filesystem disk used to store images that get sent to Pink-Commerce (the R2 disk).
    'r2_disk' => (string) env('PINKCOMMERCE_R2_DISK', 'r2'),

    // HTTP timeout (seconds) for the push request.
    'timeout' => (int) env('PINKCOMMERCE_TIMEOUT', 20),
];
