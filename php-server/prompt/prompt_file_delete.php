<?php
declare(strict_types=1);

/**
 * 프롬프트 첨부 파일 삭제
 *
 * - 일반 폼 POST(file_id만): 전체 페이지 흐름 — 삭제 후 prompt.php 로 리다이렉트 (prompt.php 등)
 * - POST + ajax=1: JSON 응답만 — 페이지 갱신 없음 (prompt_.php 마법사 UI)
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/n8n_client.php';

/**
 * @param array<string, mixed> $payload
 */
function prompt_file_delete_json(array $payload, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
$member = current_member();
$memberPk = (int)($member['id'] ?? 0);

$ajax = ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['ajax'] ?? '') === '1'));

if ($memberPk <= 0) {
    if ($ajax) {
        prompt_file_delete_json(['ok' => false, 'message' => '로그인이 필요합니다.'], 401);
    }
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($ajax) {
        prompt_file_delete_json(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
    }
    header('Location: prompt.php');
    exit;
}

$fileIdRaw = trim((string)($_POST['file_id'] ?? ''));
if ($fileIdRaw === '' || !preg_match('/^\d+$/', $fileIdRaw)) {
    if ($ajax) {
        prompt_file_delete_json(['ok' => false, 'message' => '파일 정보가 올바르지 않습니다.'], 400);
    }
    header('Location: prompt.php?msg=bad');
    exit;
}
$fileId = (int)$fileIdRaw;

$pdo = db();

// 내 파일인지 확인
$stmt = $pdo->prepare('SELECT id, prompt_id, file_type, original_name, stored_path FROM caify_prompt_file WHERE id = :id AND member_pk = :member_pk LIMIT 1');
$stmt->execute([':id' => $fileId, ':member_pk' => $memberPk]);
$row = $stmt->fetch();

if (!is_array($row) || empty($row['id'])) {
    if ($ajax) {
        prompt_file_delete_json(['ok' => false, 'message' => '파일을 찾을 수 없습니다.'], 404);
    }
    header('Location: prompt.php?msg=notfound');
    exit;
}

$storedPath = (string)($row['stored_path'] ?? '');
$promptId = (int)($row['prompt_id'] ?? 0);
$fileType = (string)($row['file_type'] ?? '');
$originalName = (string)($row['original_name'] ?? '');

// stored_path는 upload/로 시작하는 상대경로만 허용
$storedPathNorm = str_replace('\\', '/', $storedPath);
if ($storedPathNorm === '' || strpos($storedPathNorm, 'upload/') !== 0 || strpos($storedPathNorm, '..') !== false) {
    if ($ajax) {
        prompt_file_delete_json(['ok' => false, 'message' => '경로가 올바르지 않습니다.'], 400);
    }
    header('Location: prompt.php?msg=badpath');
    exit;
}

// 프로젝트 루트 기준 절대경로 생성
$projectRoot = realpath(__DIR__ . '/..');
$uploadRoot = realpath(__DIR__ . '/../upload');

$abs = '';
if ($projectRoot !== false) {
    $abs = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPathNorm);
}

try {
    $pdo->beginTransaction();

    // 분석 메타데이터(best-effort) 먼저 삭제 (FK 없는 환경 대비)
    try {
        $metaDel = $pdo->prepare('DELETE FROM caify_prompt_file_meta WHERE file_id = :file_id AND member_pk = :member_pk');
        $metaDel->execute([':file_id' => $fileId, ':member_pk' => $memberPk]);
    } catch (Throwable $e) {
        // ignore
    }

    $del = $pdo->prepare('DELETE FROM caify_prompt_file WHERE id = :id AND member_pk = :member_pk');
    $del->execute([':id' => $fileId, ':member_pk' => $memberPk]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($ajax) {
        prompt_file_delete_json(['ok' => false, 'message' => '삭제 처리 중 오류가 발생했습니다.'], 500);
    }
    header('Location: prompt.php?msg=dberr');
    exit;
}

// 실파일 삭제(실패해도 화면은 정상 이동)
if ($abs !== '' && $uploadRoot !== false) {
    $absNorm = str_replace('\\', '/', $abs);
    $uploadNorm = str_replace('\\', '/', $uploadRoot);

    // upload 폴더 바깥 삭제 방지
    if (strpos($absNorm, $uploadNorm) === 0 && is_file($abs)) {
        @unlink($abs);
    }
}

// n8n 전송(삭제 이벤트): 삭제도 "변경"이므로 보내줌
try {
    $baseUrl = n8n_base_url();
    $fileUrl = ($baseUrl !== '') ? ($baseUrl . '/' . $storedPathNorm) : $storedPathNorm;
    n8n_send([
        'event' => 'prompt_file_deleted',
        'operation' => 'delete',
        'member_pk' => $memberPk,
        'prompt_id' => $promptId,
        'file' => [
            'id' => $fileId,
            'type' => $fileType,
            'original_name' => $originalName,
            'stored_path' => $storedPathNorm,
            'url' => $fileUrl,
        ],
    ]);
} catch (Throwable $e) {
    // ignore
}

if ($ajax) {
    prompt_file_delete_json(['ok' => true, 'file_id' => $fileId]);
}

header('Location: prompt.php?msg=deleted');
exit;

