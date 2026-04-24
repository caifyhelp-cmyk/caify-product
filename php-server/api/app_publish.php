<?php
/**
 * POST /api/app_publish.php — 발행 완료/실패 기록 (단순 분리 버전)
 *
 * Authorization: Bearer <api_token>
 * Body: { "id": 5, "action": "published" }   → posting_date = NOW()
 * Body: { "id": 5, "action": "failed", "reason": "..." } → 기록만
 *
 * app_posts.php 의 POST 기능과 동일하나 URL이 분리된 버전.
 * app_posts.php/:id/published 와 app_posts.php/:id/failed 를
 * Apache rewrite 없이 사용할 때 이 파일을 대신 쓸 수 있음.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Bearer 인증 ──────────────────────────────────────────────────
function get_member_by_token(PDO $pdo): ?array
{
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = trim(preg_replace('/^Bearer\s+/i', '', $auth));
    if ($token === '') return null;

    $stmt = $pdo->prepare('SELECT id FROM caify_member WHERE api_token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

try {
    $pdo    = db();
    $member = get_member_by_token($pdo);

    if (!$member) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '인증이 필요합니다.']);
        exit;
    }

    $raw    = file_get_contents('php://input');
    $body   = json_decode($raw, true) ?: [];
    $postId = (int)($body['id'] ?? 0);
    $action = (string)($body['action'] ?? '');

    if ($postId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'id 필요']);
        exit;
    }
    if (!in_array($action, ['published', 'failed'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'action은 published 또는 failed 여야 합니다.']);
        exit;
    }

    // 본인 포스트 확인
    $stmt = $pdo->prepare('SELECT customer_id FROM ai_posts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $postId]);
    $post = $stmt->fetch();

    if (!$post) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => '포스트를 찾을 수 없습니다.']);
        exit;
    }

    if ((int)$post['customer_id'] !== (int)$member['id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
        exit;
    }

    if ($action === 'published') {
        $pdo->prepare('UPDATE ai_posts SET posting_date = NOW() WHERE id = :id')
            ->execute([':id' => $postId]);
        echo json_encode(['ok' => true, 'posting_date' => date('c')]);
    } else {
        $reason = (string)($body['reason'] ?? '알 수 없는 오류');
        error_log("[app_publish] failed id={$postId} reason={$reason}");
        echo json_encode(['ok' => true, 'message' => '실패 기록 완료 (재시도 가능)']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
