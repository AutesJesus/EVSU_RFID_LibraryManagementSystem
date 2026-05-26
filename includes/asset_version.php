<?php

declare(strict_types=1);

/**
 * Cache-bust static CSS/JS after deploy (Hostinger / LiteSpeed often caches old files).
 */
function app_asset_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $buildFile = dirname(__DIR__) . '/config/deploy.build.php';
    if (is_file($buildFile)) {
        require_once $buildFile;
        if (defined('DEPLOY_BUILD') && DEPLOY_BUILD !== '') {
            $version = (string) DEPLOY_BUILD;

            return $version;
        }
    }

    $version = 'dev';

    return $version;
}

function asset_with_version(string $href): string
{
    if ($href === '' || !preg_match('/\.(css|js)$/i', $href)) {
        return $href;
    }
    if (str_contains($href, '?')) {
        return $href;
    }

    return $href . '?v=' . rawurlencode(app_asset_version());
}
