<?php
/**
 * Bearer 토큰 인증 공통 헬퍼
 * require_once 후 bearer_member() 또는 bearer_require() 사용
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Authorization: Bearer <token> 헤더에서 회원 행 반환.
 * 토큰 없거나 유효하지 않으면 null 반환.
 */
function bearer_member(?PDO $pdo = null): ?array
{
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // Apache mod_rewrite 환경 폴백
    if ($auth === '') {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }
    $token = trim(preg_replace('/^Bearer\s+/i', '', $auth));
    if ($token === '') {
        return null;
    }
    if ($pdo === null) {
        $pdo = db();
    }
    $stmt = $pdo->prepare(
        'SELECT id, member_id, company_name, tier, blog_id, n8n_workflow_ids,
                schedule_days, schedule_hour
         FROM caify_member WHERE api_token = :t LIMIT 1'
    );
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 인증 필수 — 실패 시 401 JSON 반환 후 종료.
 */
function bearer_require(?PDO $pdo = null): array
{
    $m = bearer_member($pdo);
    if ($m === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $m;
}

/**
 * n8n REST API 호출 헬퍼 (curl 사용)
 * @return array decoded JSON or ['error' => ..., 'status' => ...]
 */
function n8n_api(string $method, string $path, ?array $body = null): array
{
    $url     = rtrim((string)N8N_URL, '/') . '/api/v1' . $path;
    $apiKey  = defined('N8N_API_KEY') ? (string)N8N_API_KEY : '';
    $headers = [
        'X-N8N-API-KEY: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['error' => 'curl 연결 실패', 'status' => 0];
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['error' => 'n8n 응답 파싱 실패', 'raw' => $raw, 'status' => $status];
    }
    return $decoded;
}
