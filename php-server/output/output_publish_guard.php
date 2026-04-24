<?php
declare(strict_types=1);

session_start();

require "../inc/db.php";

header('Content-Type: application/json; charset=UTF-8');

$customer_id = (int)($_SESSION['member']['id'] ?? 0);
$is_admin = ($customer_id === 10);

if ($customer_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않은 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_POST['action'] ?? '');
$post_id = (int)($_POST['id'] ?? 0);

if ($post_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();

    if ($action === 'check_recent_posting') {
        $stmt = $pdo->prepare('
            SELECT posting_date
            FROM ai_posts
            WHERE customer_id = :customer_id
              AND status = 1
              AND posting_date IS NOT NULL
              AND posting_date >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
              AND id <> :id
            ORDER BY posting_date DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':customer_id' => $customer_id,
            ':id' => $post_id,
        ]);

        $recent_posting_date = (string)($stmt->fetchColumn() ?: '');

        echo json_encode([
            'ok' => true,
            'has_recent_posting' => ($recent_posting_date !== ''),
            'recent_posting_date' => $recent_posting_date,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'mark_posting') {
        $sql = 'UPDATE ai_posts SET posting_date = NOW() WHERE id = :id';
        $params = [':id' => $post_id];

        if (!$is_admin) {
            $sql .= ' AND customer_id = :customer_id';
            $params[':customer_id'] = $customer_id;
        }

        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'ok' => true,
            'posting_date' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '알 수 없는 요청입니다.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 처리 중 오류가 발생했습니다.', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
