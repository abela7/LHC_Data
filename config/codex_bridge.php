<?php

return [
    'enabled' => (bool) env('CODEX_BRIDGE_ENABLED', false),
    'thread_id' => env('CODEX_BRIDGE_THREAD_ID'),
    'binary' => env('CODEX_BRIDGE_BIN', 'codex'),
    'workspace' => env('CODEX_BRIDGE_WORKSPACE', base_path()),
];
