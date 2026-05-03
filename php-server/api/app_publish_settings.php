<?php
/**
 * GET  /api/publish-settings  — 발행 설정 조회
 * POST /api/publish-settings  — 발행 설정 저장
 *
 * Authorization: Bearer <api_token>
 * Response (GET):  { ok, align, font }  — null = 미설정(최초)
 * Body   (POST):   { "align": "left"|"center"|"right", "font": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bearer_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$m   = bearer_require();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT publish_align, publish_font FROM caify_member WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$m['id']]);
    $row = $stmt->fetch();
    echo json_encode([
        'ok'    => true,
        'align' => isset($row['publish_align']) && $row['publish_align'] !== '' ? $row['publish_align'] : null,
        'font'  => isset($row['publish_font'])  ? $row['publish_font']  : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $align = trim((string)($body['align'] ?? 'left'));
    $font  = trim((string)($body['font']  ?? ''));

    if (!in_array($align, ['left', 'center', 'right'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'align must be left/center/right'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare('UPDATE caify_member SET publish_align = :align, publish_font = :font WHERE id = :id')
        ->execute([':align' => $align, ':font' => $font, ':id' => (int)$m['id']]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
