<?php

declare(strict_types=1);

function ajax_is_requested(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['__ajax'] ?? '') === '1');
}

/**
 * @param array<string, mixed> $extra
 */
function ajax_json_response(bool $ok, string $flash, string $error, array $extra = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(
            [
                'ok' => $ok,
                'flash' => $flash,
                'error' => $error,
                'message' => $error !== '' ? $error : $flash,
            ],
            $extra
        ),
        JSON_UNESCAPED_SLASHES
    );
    exit;
}
