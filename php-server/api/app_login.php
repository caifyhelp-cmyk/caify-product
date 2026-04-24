<?php
/**
 * POST /api/app_login.php
 * Flutter 앱 로그인 — Bearer 토큰 발급
 *
 * Body: { "member_id": "...", "passwd": "..." }
 * Response: { "ok": true, "member_pk": 1, "member_id": "...", "api_token": "...", "company_name": "...", "tier": 1 }
 *
 * 전제 조건: caify_member 테이블에 api_token VARCHAR(64), tier INT(1) DEFAULT 0 컬럼 추가 필요
 * ALTER TABLE caify_member ADD COLUMN api_token VARCHAR(64) DEFAULT NULL;
 * ALTER TABLE caify_member ADD COLUMN tier TINYINT(1) NOT NULL DEFAULT 0;
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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$member_id = trim((string)($data['member_id'] ?? ''));
$passwd    = (string)($data['passwd'] ?? '');

if ($member_id === '' || $passwd === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '아이디와 비밀번호를 입력해주세요.']);
    exit;
}

try {
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT id, member_id, passwd, company_name, api_token, tier FROM caify_member WHERE member_id = :mid LIMIT 1'
    );
    $stmt->execute([':mid' => $member_id]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '아이디 또는 비밀번호가 올바르지 않습니다.']);
        exit;
    }

    $stored = (string)($row['passwd'] ?? '');
    $info   = password_get_info($stored);
    $ok     = !empty($info['algo'])
        ? password_verify($passwd, $stored)
        : hash_equals($stored, $passwd);

    if (!$ok) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '아이디 또는 비밀번호가 올바르지 않습니다.']);
        exit;
    }

    // api_token 없으면 새로 발급
    $token = (string)($row['api_token'] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE caify_member SET api_token = :token WHERE id = :id')
            ->execute([':token' => $token, ':id' => $row['id']]);
    }

    echo json_encode([
        'ok'           => true,
        'member_pk'    => (int)$row['id'],
        'member_id'    => (string)$row['member_id'],
        'company_name' => (string)($row['company_name'] ?? $member_id),
        'api_token'    => $token,
        'tier'         => (int)($row['tier'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
