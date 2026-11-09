<?php

/**
 * Minimal stand-ins for OpenEMR globals so unit tests run without a core checkout.
 */

declare(strict_types=1);

if (!function_exists('xl')) {
    function xl(string $text): string
    {
        return $text;
    }
}

if (!function_exists('xlt')) {
    function xlt(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!class_exists(\OpenEMR\Services\Globals\GlobalSetting::class)) {
    require_once __DIR__ . '/stubs/GlobalSetting.php';
}
