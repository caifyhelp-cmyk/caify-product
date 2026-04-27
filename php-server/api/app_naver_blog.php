<?php
/**
 * GET   /api/naver-blog?member_pk=X  → { ok, blog_id }
 * PATCH /api/naver-blog              → body { blog_id } → { ok }
 *
 * Authorization: Bearer <api_token>
 *
 * caify_member 테이블에 blog_id VARCHAR(100) 컬럼 필요:
 * ALTER TABLE caify_member ADD COLUMN blog_id VARCHAR(100) DEFAULT NULL;
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bearer_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

try {
    $pdo = db();
    $m   = bearer_require($pdo);
    $pk  = (int)$m['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $qpk = (int)($_GET['member_pk'] ?? $pk);
        if ($qpk !== $pk) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']); exit;
        }
        echo json_encode(['ok' => true, 'blog_id' => $m['blog_id'] ?? null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $raw    = file_get_contents('php://input');
        $body   = json_decode((string)$raw, true) ?: [];
        $blogId = trim((string)($body['blog_id'] ?? '')) ?: null;

        $pdo->prepare('UPDATE caify_member SET blog_id = :bid WHERE id = :id')
            ->execute([':bid' => $blogId, ':id' => $pk]);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
