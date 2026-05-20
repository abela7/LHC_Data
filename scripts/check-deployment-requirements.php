<?php

declare(strict_types=1);

/**
 * Run from the project root:
 *   php scripts/check-deployment-requirements.php
 *
 * This reports whether a shared hosting / cPanel server can run this Laravel app.
 * It intentionally does not print secret environment values.
 */

$basePath = realpath(__DIR__.'/..') ?: dirname(__DIR__);

$requiredExtensions = [
    'ctype',
    'curl',
    'dom',
    'exif',
    'fileinfo',
    'filter',
    'gd',
    'hash',
    'iconv',
    'json',
    'libxml',
    'mbstring',
    'mysqli',
    'openssl',
    'pcre',
    'pdo',
    'pdo_mysql',
    'phar',
    'session',
    'simplexml',
    'tokenizer',
    'xml',
    'xmlreader',
    'xmlwriter',
    'zlib',
];

$recommendedExtensions = [
    'bcmath',
    'zip',
];

$requiredWritablePaths = [
    'storage',
    'storage/app',
    'storage/app/public',
    'storage/framework',
    'storage/logs',
    'bootstrap/cache',
];

function status(bool $ok): string
{
    return $ok ? 'OK' : 'FAIL';
}

function bytesFromIni(string $value): ?int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return null;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function envState(string $basePath, string $key): string
{
    $path = $basePath.'/.env';
    if (! is_file($path)) {
        return 'missing .env';
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$envKey, $value] = explode('=', $line, 2);
        if (trim($envKey) !== $key) {
            continue;
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");

        return $value === '' ? 'empty' : 'set';
    }

    return 'missing';
}

function commandVersion(string $command): string
{
    if (! function_exists('shell_exec')) {
        return 'shell_exec disabled';
    }

    $output = @shell_exec($command.' 2>&1');

    return trim((string) $output) ?: 'not found';
}

$rows = [];
$failed = false;

$phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
$rows[] = ['PHP version', PHP_VERSION, status($phpOk), 'Need PHP 8.2+'];
$failed = $failed || ! $phpOk;

foreach ($requiredExtensions as $extension) {
    $ok = extension_loaded($extension);
    $rows[] = ["ext-{$extension}", $ok ? 'loaded' : 'missing', status($ok), 'Required'];
    $failed = $failed || ! $ok;
}

foreach ($recommendedExtensions as $extension) {
    $ok = extension_loaded($extension);
    $rows[] = ["ext-{$extension}", $ok ? 'loaded' : 'missing', $ok ? 'OK' : 'WARN', 'Recommended'];
}

foreach ($requiredWritablePaths as $path) {
    $fullPath = $basePath.'/'.$path;
    $exists = is_dir($fullPath);
    $writable = $exists && is_writable($fullPath);
    $rows[] = [$path, $exists ? ($writable ? 'writable' : 'not writable') : 'missing', status($writable), 'Laravel needs write access'];
    $failed = $failed || ! $writable;
}

$buildManifest = $basePath.'/public/build/manifest.json';
$buildOk = is_file($buildManifest);
$rows[] = ['public/build/manifest.json', $buildOk ? 'exists' : 'missing', $buildOk ? 'OK' : 'WARN', 'Run npm run build before upload if missing'];

$storageLink = $basePath.'/public/storage';
$storageLinkOk = file_exists($storageLink) || is_link($storageLink) || is_dir($storageLink);
$rows[] = ['public/storage', $storageLinkOk ? 'exists' : 'missing', $storageLinkOk ? 'OK' : 'WARN', 'Run php artisan storage:link if missing'];

$uploadMax = ini_get('upload_max_filesize') ?: '';
$postMax = ini_get('post_max_size') ?: '';
$memoryLimit = ini_get('memory_limit') ?: '';
$maxExecution = ini_get('max_execution_time') ?: '';

$postBytes = bytesFromIni($postMax);
$uploadBytes = bytesFromIni($uploadMax);
$uploadOk = $uploadBytes === null || $uploadBytes >= 20 * 1024 * 1024;
$postOk = $postBytes === null || $postBytes >= 20 * 1024 * 1024;

$rows[] = ['upload_max_filesize', $uploadMax, $uploadOk ? 'OK' : 'WARN', 'Recommend 20M+ for phone photos'];
$rows[] = ['post_max_size', $postMax, $postOk ? 'OK' : 'WARN', 'Recommend 20M+ for photo forms'];
$rows[] = ['memory_limit', $memoryLimit, 'INFO', 'Recommend 256M+'];
$rows[] = ['max_execution_time', $maxExecution, 'INFO', 'Recommend 120+ for imports/images'];

foreach (['APP_KEY', 'APP_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
    $state = envState($basePath, $key);
    $ok = $state === 'set';
    $rows[] = [".env {$key}", $state, status($ok), 'Required production env'];
    $failed = $failed || ! $ok;
}

$rows[] = ['Composer', strtok(commandVersion('composer --version'), "\n") ?: 'not found', 'INFO', 'Needed on server or build machine'];
$rows[] = ['Node', strtok(commandVersion('node -v'), "\n") ?: 'not found', 'INFO', 'Optional if public/build is uploaded'];
$rows[] = ['NPM', strtok(commandVersion('npm -v'), "\n") ?: 'not found', 'INFO', 'Optional if public/build is uploaded'];

$widths = [0, 0, 0, 0];
foreach ($rows as $row) {
    foreach ($row as $index => $value) {
        $widths[$index] = max($widths[$index], strlen((string) $value));
    }
}

echo PHP_EOL.'LHC_Data deployment requirements check'.PHP_EOL;
echo str_repeat('=', 39).PHP_EOL.PHP_EOL;

foreach ($rows as $row) {
    printf(
        "%-{$widths[0]}s  %-{$widths[1]}s  %-{$widths[2]}s  %s".PHP_EOL,
        $row[0],
        $row[1],
        $row[2],
        $row[3],
    );
}

echo PHP_EOL.($failed
    ? 'Result: FAIL - fix required items before deployment.'
    : 'Result: OK - required checks passed. Review WARN/INFO items before going live.'
).PHP_EOL;

exit($failed ? 1 : 0);
