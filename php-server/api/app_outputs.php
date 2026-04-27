<?php
/**
 * GET /api/outputs?member_pk=X&page=1
 * AI 생성 포스팅 목록 (ai_posts WHERE status=1)
 *
 * Authorization: Bearer <api_token>
 * Response: { ok, total, page, per_page, items: [...] }
 *
 * 필요 테이블: ai_posts
 *   id, customer_id, title, subject, html, naver_html, tags JSON,
 *   status (1=승인), posting_date, created_at
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bearer_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

try {
    $pdo = db();
    $m   = bearer_require($pdo);

    $pk      = (int)($_GET['member_pk'] ?? $m['id']);
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 12;

    if ($pk !== (int)$m['id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
        exit;
    }

    $offset = ($page - 1) * $perPage;

    $countSql = 'SELECT COUNT(*) FROM ai_posts WHERE customer_id = :mid AND status = 1';
    $cnt = (int)$pdo->prepare($countSql)->execute([':mid' => $pk]) ? null : null;
    $cstmt = $pdo->prepare($countSql);
    $cstmt->execute([':mid' => $pk]);
    $total = (int)$cstmt->fetchColumn();

    $sql = 'SELECT id, title, subject, html, naver_html, tags, status, posting_date, created_at
            FROM ai_posts
            WHERE customer_id = :mid AND status = 1
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':mid',    $pk,      PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = array_map(function (array $row): array {
        $tags = [];
        if (!empty($row['tags'])) {
            $d = json_decode($row['tags'], true);
            if (is_array($d)) $tags = $d;
        }
        // 썸네일: naver_html 첫 번째 img src
        $thumbnail = null;
        if (!empty($row['naver_html'])) {
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $row['naver_html'], $im)) {
                $thumbnail = $im[1];
            }
        }
        return [
            'id'           => (int)$row['id'],
            'title'        => (string)$row['title'],
            'subject'      => $row['subject'] ? (string)$row['subject'] : null,
            'html'         => (string)($row['html'] ?? ''),
            'naver_html'   => (string)($row['naver_html'] ?? ''),
            'tags'         => $tags,
            'status'       => $row['posting_date'] ? 'published' : 'ready',
            'posting_date' => $row['posting_date'],
            'created_at'   => $row['created_at'],
            'thumbnail'    => $thumbnail,
        ];
    }, $rows);

    echo json_encode([
        'ok'       => true,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'items'    => $items,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
