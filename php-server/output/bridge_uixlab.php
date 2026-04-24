<?php
declare(strict_types=1);

/**
 * UIXLAB 브릿지 API 호출 유틸.
 *
 * - 이 파일은 "원격 호출 + 예외 throw" 역할만 합니다. (브라우저 출력/리다이렉트는 호출자에서 처리)
 * - 기능(성공/실패 판단 로직)은 유지하면서, 장애 분석을 위해 예외 메시지/로그에 맥락을 충분히 남깁니다.
 * - 브라우저 노출은 `output_publish_site.php`에서 debug 모드일 때 예외 메시지를 출력하도록 구현되어 있습니다.
 */

function normalizeJoinUrl(string $base, string $path): string
{
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function isAllowedBridgeUrl(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme === 'https') {
        return true;
    }

    if ($scheme === 'http' && UIXLAB_ALLOW_HTTP === '1') {
        return true;
    }

    return false;
}

function maskBridgeValue(string $key, $value): string
{
    $v = trim((string)$value);
    if ($v === '') {
        return '';
    }

    $sensitiveKeys = ['email', 'phone', 'contact_phone', 'draft_token', 'bridge_token', 'source_member_id'];
    if (!in_array($key, $sensitiveKeys, true)) {
        return $v;
    }

    $len = strlen($v);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }

    return substr($v, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($v, -2);
}

function bridgeLog(string $event, array $context = []): void
{
    $sanitized = [];
    foreach ($context as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $sanitized[$k] = maskBridgeValue((string)$k, $v);
            continue;
        }
        if (is_array($v)) {
            $sanitized[$k] = '[array]';
            continue;
        }
        $sanitized[$k] = '[object]';
    }

    error_log('[uixlab_bridge] ' . $event . ' ' . json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * @return array{http_code:int,correlation_id:string,data:array}
 */
function callUixlabBridgeApi(string $createPath, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is not available.');
    }
    if (UIXLAB_BRIDGE_API_KEY === '') {
        throw new RuntimeException('Bridge API key is empty.');
    }

    // UIXLAB API endpoint (예: https://uixlab.co.kr/design/theme_01/admin/gallery_draft_create.php)
    $endpoint = normalizeJoinUrl(UIXLAB_API_BASE, $createPath);
    if (!isAllowedBridgeUrl($endpoint)) {
        throw new RuntimeException('Bridge endpoint scheme is not allowed.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode bridge payload.');
    }

    $correlationId = bin2hex(random_bytes(16));
    bridgeLog('request', [
        'endpoint' => $createPath,
        'correlation_id' => $correlationId,
        'payload_keys' => implode(',', array_keys($payload)),
        'source_customer_id' => $payload['source_customer_id'] ?? '',
        'source_member_id' => $payload['source_member_id'] ?? '',
        'email' => $payload['email'] ?? '',
        'contact_phone' => $payload['contact_phone'] ?? '',
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        // 응답이 JSON이 아닌 경우(로그인 HTML, 에러 페이지 등) 분석을 위해 body를 그대로 받습니다.
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        // 302 등 리다이렉트는 JSON이 아닐 가능성이 높아서 따라가지 않도록 고정합니다.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=UTF-8',
            'Accept: application/json',
            'X-Bridge-Api-Key: ' . UIXLAB_BRIDGE_API_KEY,
            'X-Correlation-Id: ' . $correlationId,
        ],
        CURLOPT_POSTFIELDS => $json,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        bridgeLog('error_transport', [
            'endpoint' => $createPath,
            'correlation_id' => $correlationId,
            'curl_error' => $curlErr,
            'effective_url' => $effectiveUrl,
        ]);
        // 호출자(output_publish_site.php)에서 debug 모드면 브라우저에 예외 메시지가 그대로 출력됩니다.
        throw new RuntimeException(
            'Bridge request failed. '
            . '(correlation_id=' . $correlationId . ') '
            . $curlErr
        );
    }

    // 정상 케이스는 JSON(배열)이어야 합니다.
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $prefix = substr((string)$response, 0, 800);
        bridgeLog('error_response_format', [
            'endpoint' => $createPath,
            'correlation_id' => $correlationId,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'effective_url' => $effectiveUrl,
            'response_prefix' => $prefix,
        ]);
        // 호출자에서 브라우저로 노출될 수 있도록, 원인에 필요한 최소 정보를 예외 메시지에 포함합니다.
        throw new RuntimeException(
            'Invalid bridge response format. '
            . '(http_code=' . $httpCode
            . ', content_type=' . ($contentType !== '' ? $contentType : 'n/a')
            . ', correlation_id=' . $correlationId
            . ', effective_url=' . ($effectiveUrl !== '' ? $effectiveUrl : 'n/a') . ') '
            . 'response_prefix=' . $prefix
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $prefix = substr((string)$response, 0, 800);
        bridgeLog('error_http', [
            'endpoint' => $createPath,
            'correlation_id' => $correlationId,
            'http_code' => $httpCode,
            'message' => (string)($decoded['message'] ?? ''),
            'response_prefix' => $prefix,
            'content_type' => $contentType,
            'effective_url' => $effectiveUrl,
        ]);
        // 호출자에서 브라우저로 노출될 수 있도록, http_code/correlation_id를 함께 제공합니다.
        $errorMessage = (string)($decoded['message'] ?? 'Bridge API returned error.');
        throw new RuntimeException(
            $errorMessage
            . ' (http_code=' . $httpCode
            . ', correlation_id=' . $correlationId
            . ', content_type=' . ($contentType !== '' ? $contentType : 'n/a')
            . ')'
        );
    }

    bridgeLog('response_ok', [
        'endpoint' => $createPath,
        'correlation_id' => $correlationId,
        'http_code' => $httpCode,
        'has_draft_token' => isset($decoded['draft_token']) ? 'Y' : 'N',
    ]);

    return [
        'http_code' => $httpCode,
        'correlation_id' => $correlationId,
        'data' => $decoded,
    ];
}
