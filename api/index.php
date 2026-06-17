<?php

/**
 * Vercel PHP entry point for Laravel.
 *
 * Vercel's filesystem is read-only except for /tmp.
 * We override all paths that Laravel needs to write to.
 */

// Override writable paths BEFORE Laravel boots
$_ENV['STORAGE_PATH']       = '/tmp/storage';
$_ENV['VIEW_COMPILED_PATH'] = '/tmp/storage/framework/views';

// Ensure all required writable directories exist in /tmp
$dirs = [
    '/tmp/storage/framework/views',
    '/tmp/storage/framework/cache/data',
    '/tmp/storage/framework/sessions',
    '/tmp/storage/logs',
    '/tmp/bootstrap/cache',
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Forward the request to Laravel's public/index.php
require __DIR__ . '/../public/index.php';

