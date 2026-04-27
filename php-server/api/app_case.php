<?php
/**
 * POST /api/case/submit  — 사례 제출 (multipart/form-data)
 * GET  /api/case          — 사례 목록 조회
 *
 * Authorization: Bearer <api_token>
 *
 * 필요 테이블: caify_case
 *   id, member_pk, case_title, raw_content TEXT,
 *   ai_status ENUM('pending','done','failed') DEFAULT 'pending',
 *   ai_title VARCHAR(500) NULL, ai_summary TEXT NULL,
 *   files JSON NULL, created_at DATETIME DEFAULT NOW()
 *
 * CREATE TABLE IF NOT EXISTS caify_case (
 *   id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   member_pk   INT UNSIGNED NOT NULL,
 *   case_title  VARCHAR(500) NOT NULL,
 *   raw_content TEXT NOT NULL,
 *   ai_status   ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
 *   ai_title    VARCHAR(500) NULL,
 *   ai_summary  TEXT NULL,
 *   files       JSON NULL,
 *   created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   INDEX idx_member_pk (member_pk)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * 이미지 파일은 서버 로컬 uploads/case/:member_pk/ 에 저장.
 * UPLOADS_DIR 을 nginx가 /uploads/ 경로로 서빙하도록 설정 필요.
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bearer_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 업로드 디렉터리 (nginx /uploads/ 로 서빙)
define('UPLOADS_BASE', dirname(__DIR__) . '/uploads');
define('UPLOADS_URL',  'https://caify.ai/uploads');

try {
    $pdo = db();
    $m   = bearer_require($pdo);
    $pk  = (int)$m['id'];

    // ── GET /api/case ─────────────────────────────────────────
    if ($method === 'GET') {
        $qpk = (int)($_GET['member_pk'] ?? $pk);
        if ($qpk !== $pk) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT id, case_title, raw_content, ai_status, ai_title, ai_summary, files, created_at
             FROM caify_case WHERE member_pk = :pk ORDER BY created_at DESC'
        );
        $stmt->execute([':pk' => $qpk]);
        $rows = $stmt->fetchAll();

        $result = array_map(function (array $r): array {
            return [
                'id'          => (int)$r['id'],
                'case_title'  => (string)$r['case_title'],
                'raw_content' => (string)$r['raw_content'],
                'ai_status'   => (string)$r['ai_status'],
                'ai_title'    => $r['ai_title'],
                'ai_summary'  => $r['ai_summary'],
                'files'       => $r['files'] ? json_decode($r['files'], true) : [],
                'created_at'  => $r['created_at'],
            ];
        }, $rows);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST /api/case/submit ─────────────────────────────────
    if ($method === 'POST') {
        if ((int)$m['tier'] < 1) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '유료 플랜 전용입니다.']);
            exit;
        }

        $caseTitle  = trim((string)($_POST['case_title']  ?? ''));
        $rawContent = trim((string)($_POST['raw_content'] ?? ''));

        if ($caseTitle === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => '사례명은 필수입니다.']); exit;
        }
        if ($rawContent === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => '사례 내용은 필수입니다.']); exit;
        }

        // 이미지 저장
        $files     = [];
        $uploadDir = UPLOADS_BASE . '/case/' . $pk . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $uploaded = $_FILES['case_images'] ?? [];
        $count    = is_array($uploaded['name'] ?? null) ? count($uploaded['name']) : 0;
        for ($i = 0; $i < $count && $i < 8; $i++) {
            if ($uploaded['error'][$i] !== UPLOAD_ERR_OK) continue;
            $origName = basename((string)$uploaded['name'][$i]);
            $ext      = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) continue;
            $saveName = uniqid('img_', true) . '.' . $ext;
            $savePath = $uploadDir . $saveName;
            if (move_uploaded_file($uploaded['tmp_name'][$i], $savePath)) {
                $files[] = [
                    'id'            => $i + 1,
                    'original_name' => $origName,
                    'url'           => UPLOADS_URL . '/case/' . $pk . '/' . $saveName,
                ];
            }
        }

        // DB 저장
        $stmt = $pdo->prepare(
            'INSERT INTO caify_case (member_pk, case_title, raw_content, files)
             VALUES (:pk, :title, :content, :files)'
        );
        $stmt->execute([
            ':pk'      => $pk,
            ':title'   => $caseTitle,
            ':content' => $rawContent,
            ':files'   => !empty($files) ? json_encode($files, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $caseId = (int)$pdo->lastInsertId();

        // n8n 케이스 워크플로우 실행
        $wfIds = null;
        if (!empty($m['n8n_workflow_ids'])) {
            $wfIds = json_decode((string)$m['n8n_workflow_ids'], true);
        }
        $caseWfId = $wfIds['case'] ?? null;

        if ($caseWfId && !str_starts_with($caseWfId, 'mock-') && !str_starts_with($caseWfId, 'err-')) {
            n8n_api('POST', "/workflows/{$caseWfId}/execute", [
                'data' => [['json' => [
                    'case_id'     => $caseId,
                    'member_pk'   => $pk,
                    'case_title'  => $caseTitle,
                    'raw_content' => $rawContent,
                    'files'       => $files,
                ]]],
            ]);
        }

        http_response_code(201);
        echo json_encode([
            'ok'      => true,
            'case_id' => $caseId,
            'message' => 'n8n 워크플로우 실행 중입니다. 잠시 후 산출물 탭에서 확인하세요.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
