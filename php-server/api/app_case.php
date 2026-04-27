<?php
/**
 * POST /api/case/submit  — 사례 제출 (multipart/form-data)
 * GET  /api/case          — 사례 목록 조회
 *
 * Authorization: Bearer <api_token>
 *
 * 사용 테이블 (기존 PHP와 동일):
 *   caify_case       — id, member_pk, case_title, raw_content, status,
 *                       ai_structured_json, ai_title, ai_summary, ai_body_draft,
 *                       ai_h2_sections, ai_status (pending|done|error), created_at
 *   caify_case_file  — id, case_id, member_pk, original_name, stored_path,
 *                       mime_type, file_size
 *
 * 제출 후: n8n 케이스 워크플로우 REST API execute 호출
 * (고객 n8n_workflow_ids JSON → case 워크플로우 ID)
 *
 * ai_status 매핑: DB 'error' → API 응답 'failed' (Flutter _statusBadge 호환)
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

// 업로드 기본 경로 (기존 PHP와 동일하게 /upload/member_folder/)
$rootUploadDir = realpath(__DIR__ . '/../upload') ?: (__DIR__ . '/../upload');
$uploadBaseUrl = 'https://caify.ai/upload';

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
            'SELECT id, case_title, raw_content, ai_status, ai_title, ai_summary, created_at
             FROM caify_case
             WHERE member_pk = :pk AND status = 1
             ORDER BY created_at DESC'
        );
        $stmt->execute([':pk' => $qpk]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            echo json_encode([], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 케이스 ID 목록으로 파일 일괄 조회
        $caseIds = array_column($rows, 'id');
        $in      = implode(',', array_fill(0, count($caseIds), '?'));
        $fStmt   = $pdo->prepare(
            "SELECT case_id, id AS file_id, original_name, stored_path
             FROM caify_case_file
             WHERE case_id IN ($in) AND member_pk = ?
             ORDER BY id ASC"
        );
        $fStmt->execute(array_merge($caseIds, [$qpk]));
        $fileRows = $fStmt->fetchAll();

        // case_id → files 매핑
        $filesMap = [];
        foreach ($fileRows as $fr) {
            $cid = (int)$fr['case_id'];
            $url = '';
            $sp  = (string)$fr['stored_path'];
            if ($sp !== '') {
                // stored_path: "upload/member_folder/filename"
                $url = 'https://caify.ai/' . ltrim($sp, '/');
            }
            $filesMap[$cid][] = [
                'id'            => (int)$fr['file_id'],
                'original_name' => (string)$fr['original_name'],
                'url'           => $url,
            ];
        }

        $result = array_map(function (array $r) use ($filesMap): array {
            $cid    = (int)$r['id'];
            $status = (string)$r['ai_status'];
            // DB 'error' → Flutter 'failed'
            if ($status === 'error') $status = 'failed';
            return [
                'id'          => $cid,
                'case_title'  => (string)$r['case_title'],
                'raw_content' => (string)$r['raw_content'],
                'ai_status'   => $status,
                'ai_title'    => $r['ai_title'],
                'ai_summary'  => $r['ai_summary'],
                'files'       => $filesMap[$cid] ?? [],
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

        $pdo->beginTransaction();

        // caify_case INSERT (기존 웹 PHP와 동일 구조)
        $pdo->prepare(
            'INSERT INTO caify_case (member_pk, case_title, raw_content, ai_status, status)
             VALUES (:pk, :title, :content, :ai_status, 1)'
        )->execute([
            ':pk'        => $pk,
            ':title'     => $caseTitle,
            ':content'   => $rawContent,
            ':ai_status' => 'pending',
        ]);
        $caseId = (int)$pdo->lastInsertId();

        // 이미지 파일 처리 — 기존 PHP와 동일한 업로드 경로 구조
        $memberId    = (string)$m['member_id'];
        $memberFolder = preg_replace('/[^a-zA-Z0-9_-]/', '_', $memberId);
        if ($memberFolder === '') $memberFolder = 'member_' . $pk;

        $userUploadDir = rtrim($rootUploadDir, '\\/') . DIRECTORY_SEPARATOR . $memberFolder;
        if (!is_dir($userUploadDir)) {
            mkdir($userUploadDir, 0775, true);
        }

        $savedFiles   = [];
        $uploadedFiles = $_FILES['case_images'] ?? [];
        $allowedMimes  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo         = new finfo(FILEINFO_MIME_TYPE);
        $count         = is_array($uploadedFiles['name'] ?? null) ? count($uploadedFiles['name']) : 0;

        for ($i = 0; $i < $count && $i < 8; $i++) {
            if (($uploadedFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmp = (string)$uploadedFiles['tmp_name'][$i];
            if ($tmp === '' || !is_uploaded_file($tmp)) continue;

            $mime = (string)$finfo->file($tmp);
            if (!in_array($mime, $allowedMimes, true)) continue;

            $orig     = basename((string)$uploadedFiles['name'][$i]);
            $size     = (int)$uploadedFiles['size'][$i];
            $ext      = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
            if ($ext === '') $ext = 'jpg';

            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $destAbs  = $userUploadDir . DIRECTORY_SEPARATOR . $filename;

            if (!move_uploaded_file($tmp, $destAbs)) continue;

            $storedPath = 'upload/' . $memberFolder . '/' . $filename;

            $pdo->prepare(
                'INSERT INTO caify_case_file (case_id, member_pk, original_name, stored_path, mime_type, file_size)
                 VALUES (:case_id, :member_pk, :original_name, :stored_path, :mime_type, :file_size)'
            )->execute([
                ':case_id'       => $caseId,
                ':member_pk'     => $pk,
                ':original_name' => $orig,
                ':stored_path'   => $storedPath,
                ':mime_type'     => $mime,
                ':file_size'     => $size,
            ]);

            $savedFiles[] = [
                'id'            => (int)$pdo->lastInsertId(),
                'original_name' => $orig,
                'url'           => 'https://caify.ai/' . $storedPath,
            ];
        }

        $pdo->commit();

        // n8n 케이스 워크플로우 트리거 (caify_member.n8n_workflow_ids['case'])
        $wfIds  = null;
        if (!empty($m['n8n_workflow_ids'])) {
            $wfIds = json_decode((string)$m['n8n_workflow_ids'], true);
        }
        $caseWfId = is_array($wfIds) ? ($wfIds['case'] ?? null) : null;

        if ($caseWfId
            && !str_starts_with($caseWfId, 'mock-')
            && !str_starts_with($caseWfId, 'err-')
        ) {
            $n8nResult = n8n_api('POST', "/workflows/{$caseWfId}/execute", [
                'data' => [['json' => [
                    'case_id'     => $caseId,
                    'member_pk'   => $pk,
                    'case_title'  => $caseTitle,
                    'raw_content' => $rawContent,
                    'files'       => $savedFiles,
                ]]],
            ]);
            error_log("[app_case] n8n execute wf={$caseWfId} result=" . json_encode($n8nResult));
        } else {
            error_log("[app_case] n8n 워크플로우 미설정 또는 mock — case_id={$caseId}");
        }

        http_response_code(201);
        echo json_encode([
            'ok'      => true,
            'case_id' => $caseId,
            'message' => '사례가 제출됐습니다. AI가 포스팅을 생성하는 중이에요.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
