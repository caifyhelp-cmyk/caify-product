<?php
/**
 * GET  /api/app_posts.php?status=ready&member_pk=X  → 발행 대기 목록
 * POST /api/app_posts.php/:id/published             → 발행 완료 기록 (posting_date = NOW())
 * POST /api/app_posts.php/:id/failed                → 발행 실패 기록 (posting_date 유지)
 *
 * Authorization: Bearer <api_token>
 *
 * 사용 테이블: ai_posts
 *   id, customer_id, prompt_id, prompt_node_id,
 *   title, subject, intro, html, naver_html,
 *   status (0=대기/미승인, 1=승인완료),
 *   posting_date (NULL=미발행, NOT NULL=발행완료),
 *   tags JSON, created_at
 *
 * tags 컬럼이 없으면 ALTER TABLE ai_posts ADD COLUMN tags JSON DEFAULT NULL;
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Bearer 토큰 인증 ─────────────────────────────────────────────
function get_member_by_token(PDO $pdo): ?array
{
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = trim(preg_replace('/^Bearer\s+/i', '', $auth));
    if ($token === '') return null;

    $stmt = $pdo->prepare('SELECT id, member_id, company_name, tier FROM caify_member WHERE api_token = :t LIMIT 1');
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

    $method    = $_SERVER['REQUEST_METHOD'];
    $uri       = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $memberId  = (int)$member['id'];

    // ── POST /:id/published or /:id/failed ─────────────────────
    if ($method === 'POST') {
        $raw    = file_get_contents('php://input');
        $body   = json_decode($raw, true) ?: [];

        // URL から id と action を取る: /api/app_posts.php/5/published
        if (preg_match('#/(\d+)/(published|failed)$#', $uri, $m)) {
            $postId = (int)$m[1];
            $action = $m[2];
        } else {
            $postId = (int)($body['id'] ?? 0);
            $action = (string)($body['action'] ?? '');
        }

        if ($postId <= 0 || !in_array($action, ['published', 'failed'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'id와 action(published|failed) 필요']);
            exit;
        }

        // 본인 포스트 여부 확인
        $stmt = $pdo->prepare('SELECT id, customer_id FROM ai_posts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $postId]);
        $post = $stmt->fetch();

        if (!$post) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => '포스트를 찾을 수 없습니다.']);
            exit;
        }

        if ((int)$post['customer_id'] !== $memberId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
            exit;
        }

        if ($action === 'published') {
            $pdo->prepare('UPDATE ai_posts SET posting_date = NOW() WHERE id = :id')
                ->execute([':id' => $postId]);
            echo json_encode(['ok' => true, 'posting_date' => date('c')]);
        } else {
            // failed: posting_date 그대로 유지 → 재시도 가능
            $reason = (string)($body['reason'] ?? '알 수 없는 오류');
            error_log("[app_posts] failed post_id={$postId} reason={$reason}");
            echo json_encode(['ok' => true, 'message' => '실패 기록 완료 (재시도 가능)']);
        }
        exit;
    }

    // ── GET → 포스트 목록 ────────────────────────────────────────
    if ($method === 'GET') {
        $status   = (string)($_GET['status'] ?? '');
        $memberPk = (int)($_GET['member_pk'] ?? 0);

        // member_pk 검증 (본인만 조회 가능)
        if ($memberPk !== $memberId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
            exit;
        }

        if ($status === 'ready') {
            $sql = 'SELECT id, title, naver_html AS html, tags, status, posting_date, created_at
                    FROM ai_posts
                    WHERE customer_id = :mid AND status = 1 AND posting_date IS NULL
                    ORDER BY created_at DESC';
        } elseif ($status === 'published') {
            $sql = 'SELECT id, title, naver_html AS html, tags, status, posting_date, created_at
                    FROM ai_posts
                    WHERE customer_id = :mid AND posting_date IS NOT NULL
                    ORDER BY posting_date DESC';
        } else {
            $sql = 'SELECT id, title, naver_html AS html, tags, status, posting_date, created_at
                    FROM ai_posts
                    WHERE customer_id = :mid
                    ORDER BY created_at DESC';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':mid' => $memberPk]);
        $rows = $stmt->fetchAll();

        $result = array_map(function (array $row): array {
            $tags = [];
            if (!empty($row['tags'])) {
                $decoded = json_decode($row['tags'], true);
                if (is_array($decoded)) $tags = $decoded;
            }
            return [
                'id'           => (int)$row['id'],
                'title'        => (string)$row['title'],
                'html'         => (string)$row['html'],
                'tags'         => $tags,
                'status'       => (int)$row['status'],
                'posting_date' => $row['posting_date'],
                'created_at'   => $row['created_at'],
            ];
        }, $rows);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
