<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => env('GEMINI_TIMEOUT', 30),
    ],

    'ai_lookup' => [
        'provider' => env('AI_LOOKUP_PROVIDER', 'openrouter'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'retail_naming_model' => env('OPENAI_RETAIL_NAMING_MODEL', 'gpt-5-nano'),
        'timeout' => env('OPENAI_TIMEOUT', 60),
        'site_url' => env('OPENAI_SITE_URL', env('APP_URL', 'http://localhost')),
        'app_name' => env('OPENAI_APP_NAME', env('APP_NAME', 'LHC Data')),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-3-flash-preview'),
        'timeout' => env('OPENROUTER_TIMEOUT', 45),
        'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL', 'http://localhost')),
        'app_name' => env('OPENROUTER_APP_NAME', env('APP_NAME', 'LHC Data')),
        'web_search' => env('OPENROUTER_WEB_SEARCH', true),
        'web_search_max_results' => env('OPENROUTER_WEB_SEARCH_MAX_RESULTS', 4),
        'web_search_max_total_results' => env('OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS', 8),
        'models' => [
            ['id' => 'google/gemini-3-flash-preview', 'name' => 'Gemini 3 Flash'],
            ['id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash'],
            ['id' => 'google/gemini-2.0-flash-001', 'name' => 'Gemini 2.0 Flash'],
            ['id' => 'perplexity/sonar', 'name' => 'Perplexity Sonar'],
            ['id' => 'openrouter/auto', 'name' => 'OpenRouter Auto'],
        ],
        'vision_model' => env('OPENROUTER_VISION_MODEL', 'openrouter/free'),
        'vision_models' => [
            ['id' => 'openrouter/free', 'name' => 'Free Vision Router'],
            ['id' => 'baidu/qianfan-ocr-fast:free', 'name' => 'Qianfan OCR Fast (Free)'],
            ['id' => 'nvidia/nemotron-nano-12b-v2-vl:free', 'name' => 'Nemotron Nano VL (Free)'],
            ['id' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free', 'name' => 'Nemotron Omni (Free)'],
            ['id' => 'google/gemma-4-26b-a4b-it:free', 'name' => 'Gemma 4 Vision (Free)'],
            ['id' => 'google/gemini-2.0-flash-001', 'name' => 'Gemini 2.0 Flash'],
        ],
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
