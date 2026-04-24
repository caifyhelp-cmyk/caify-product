<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function n8n_webhook_url(): string
{
    return defined('N8N_WEBHOOK_URL') ? (string)N8N_WEBHOOK_URL : '';
}

function n8n_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $protocol = $https ? 'https://' : 'http://';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }

    // /something/prompt/prompt_submit.php -> /something
    $selfDir = (string)dirname((string)($_SERVER['PHP_SELF'] ?? '/'));
    $rootDir = (string)dirname($selfDir);
    if ($rootDir === DIRECTORY_SEPARATOR) {
        $rootDir = '';
    }

    return rtrim($protocol . $host . $rootDir, '/');
}

function n8n_send(array $payload): void
{
    $url = n8n_webhook_url();
    if ($url === '') {
        return; // 설정 없으면 스킵
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    // curl 있으면 curl, 없으면 file_get_contents 폴백
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        curl_close($ch);
        return;
    }

    @file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'timeout' => 3,
        ],
    ]));
}

