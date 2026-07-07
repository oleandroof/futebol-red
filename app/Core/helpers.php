<?php

declare(strict_types=1);

function env_value(mixed $value, mixed $default = null): mixed
{
    return $value !== null && $value !== '' ? $value : $default;
}

function app_base_path(): string
{
    $base = trim((string) dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if ($base === '' || $base === '.') {
        return '';
    }

    return '/' . $base;
}

function app_url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $configuredBase = '';
    if (defined('APP_BASE_URL')) {
        $configuredBase = trim((string) APP_BASE_URL);
    }

    if ($configuredBase !== '') {
        $normalizedPath = '/' . ltrim($path, '/');
        if (preg_match('#^https?://#i', $configuredBase) === 1) {
            return rtrim($configuredBase, '/') . $normalizedPath;
        }

        $prefix = '/' . trim($configuredBase, '/');
        return rtrim($prefix, '/') . $normalizedPath;
    }

    $base = app_base_path();
    $normalizedPath = '/' . ltrim($path, '/');

    return ($base === '' ? '' : $base) . $normalizedPath;
}

function app_absolute_url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    if (defined('APP_BASE_URL')) {
        $configuredBase = trim((string) APP_BASE_URL);
        if ($configuredBase !== '' && preg_match('#^https?://#i', $configuredBase) === 1) {
            return rtrim($configuredBase, '/') . '/' . ltrim($path, '/');
        }
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . app_url($path);
}
