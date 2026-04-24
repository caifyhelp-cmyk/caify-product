<?php
/*
 * ────────────────────────────────────────────────────────────────────────────
 * DB 테이블 생성 SQL → case_schema.sql 참조
 * ────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/n8n_client.php';

require_login();
$member = current_member();

$memberPk = (int)($member['id'] ?? 0);
$memberId = (string)($member['member_id'] ?? '');

if ($memberPk <= 0) {
    header('Location: /member/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: case_list.php');
    exit;
}

function case_swal_redirect(string $title, string $text, string $icon, string $url): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($text,  ENT_QUOTES, 'UTF-8');
    $i = htmlspecialchars($icon,  ENT_QUOTES, 'UTF-8');
    $r = htmlspecialchars($url,   ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>알림</title></head><body>';
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script>';
    echo 'var title="'.$t.'";var text="'.$m.'";var icon="'.$i.'";var redirect="'.$r.'";';
    echo 'function go(){window.location.href=redirect;}';
    echo 'if(typeof Swal!=="undefined"){Swal.fire({title:title,text:text,icon:icon,confirmButtonText:"확인"}).then(go);}';
    echo 'else{alert(title+"\\n\\n"+text);go();}';
    echo '</script></body></html>';
    exit;
}

function cs(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function normalize_case_files(string $key): array
{
    if (empty($_FILES[$key]) || !is_array($_FILES[$key])) return [];
    $f = $_FILES[$key];
    if (isset($f['name']) && is_array($f['name'])) {
        $out = [];
        for ($i = 0; $i < count($f['name']); $i++) {
            $out[] = [
                'name'     => (string)($f['name'][$i]     ?? ''),
                'type'     => (string)($f['type'][$i]     ?? ''),
                'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                'error'    => (int)($f['error'][$i]       ?? UPLOAD_ERR_NO_FILE),
                'size'     => (int)($f['size'][$i]        ?? 0),
            ];
        }
        return $out;
    }
    return [[
        'name'     => (string)($f['name']     ?? ''),
        'type'     => (string)($f['type']     ?? ''),
        'tmp_name' => (string)($f['tmp_name'] ?? ''),
        'error'    => (int)($f['error']       ?? UPLOAD_ERR_NO_FILE),
        'size'     => (int)($f['size']        ?? 0),
    ]];
}

// POST 값 수집
$editCaseId = (int)cs('case_id');
$caseTitle = cs('case_title');
$rawContent = cs('raw_content');
$aiIndustryCategory = cs('ai_industry_category');
$aiCaseCategory = cs('ai_case_category');
$aiSubjectLabel = cs('ai_subject_label');
$aiProblemSummary = cs('ai_problem_summary');
$aiProcessSummary = cs('ai_process_summary');
$aiResultSummary = cs('ai_result_summary');
$aiTitle = cs('ai_title');
$aiSummary = cs('ai_summary');
$aiBodyDraft = cs('ai_body_draft');
$aiStatus = cs('ai_status');
$aiImageLayoutJson = trim((string)($_POST['ai_image_layout_json'] ?? ''));

// H2 소제목 배열
$aiH2Raw      = $_POST['ai_h2'] ?? [];
$aiH2Sections = [];
if (is_array($aiH2Raw)) {
    foreach ($aiH2Raw as $v) {
        $aiH2Sections[] = trim((string)$v);
    }
}
$aiH2Json = count(array_filter($aiH2Sections)) > 0
    ? json_encode($aiH2Sections, JSON_UNESCAPED_UNICODE)
    : null;

if (!in_array($aiStatus, ['pending', 'done', 'error'], true)) {
    $aiStatus = 'pending';
}

// 필수 검증
$errors = [];
if ($caseTitle === '') $errors[] = '사례명은 필수입니다.';
if ($rawContent === '') $errors[] = '사례 내용은 필수입니다.';

if (count($errors) > 0) {
    $backUrl = $editCaseId > 0 ? 'case_short.php?id=' . $editCaseId : 'case_short.php';
    case_swal_redirect('입력 오류', implode(' ', $errors), 'warning', $backUrl);
}

$aiStructured = [
    'industry_category' => $aiIndustryCategory,
    'case_category' => $aiCaseCategory,
    'subject_label' => $aiSubjectLabel,
    'problem_summary' => $aiProblemSummary,
    'process_summary' => $aiProcessSummary,
    'result_summary' => $aiResultSummary,
];
if ($aiImageLayoutJson !== '') {
    $decodedLayout = json_decode($aiImageLayoutJson, true);
    if (is_array($decodedLayout)) {
        $aiStructured['image_layout'] = $decodedLayout;
    }
}
$hasStructuredValue = false;
foreach ($aiStructured as $value) {
    if (trim((string)$value) !== '') {
        $hasStructuredValue = true;
        break;
    }
}
$aiStructuredJson = $hasStructuredValue ? json_encode($aiStructured, JSON_UNESCAPED_UNICODE) : null;

// 업로드 경로
$rootUploadDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'upload';
if ($rootUploadDir === false) {
    $rootUploadDir = __DIR__ . '/../upload';
}

$memberFolder = safe_member_folder($memberId, $memberPk);
$userUploadAbs = rtrim($rootUploadDir, '\\/') . DIRECTORY_SEPARATOR . $memberFolder;

if (!is_dir($userUploadAbs)) {
    if (!mkdir($userUploadAbs, 0775, true) && !is_dir($userUploadAbs)) {
        case_swal_redirect('오류', '업로드 폴더 생성에 실패했습니다.', 'error', 'case_short.php');
    }
}

$pdo = db();
$movedFiles = [];
$newImageFilesForAnalysis = [];

try {
    $pdo->beginTransaction();

    if ($editCaseId > 0) {
        // 수정: 본인 소유 확인
        $check = $pdo->prepare('SELECT id FROM caify_case WHERE id = :id AND member_pk = :member_pk LIMIT 1');
        $check->execute([':id' => $editCaseId, ':member_pk' => $memberPk]);
        if (!is_array($check->fetch()) ) {
            $pdo->rollBack();
            case_swal_redirect('오류', '수정 권한이 없거나 존재하지 않는 사례입니다.', 'error', 'case_list.php');
        }

        $upd = $pdo->prepare(
            'UPDATE caify_case SET
                case_title         = :case_title,
                raw_content        = :raw_content,
                ai_structured_json = :ai_structured_json,
                ai_title           = :ai_title,
                ai_summary         = :ai_summary,
                ai_body_draft      = :ai_body_draft,
                ai_h2_sections     = :ai_h2_sections,
                ai_status          = :ai_status
             WHERE id = :id AND member_pk = :member_pk'
        );
        $upd->execute([
            ':id' => $editCaseId,
            ':member_pk' => $memberPk,
            ':case_title' => $caseTitle,
            ':raw_content' => $rawContent,
            ':ai_structured_json' => $aiStructuredJson,
            ':ai_title' => $aiTitle !== '' ? $aiTitle : null,
            ':ai_summary' => $aiSummary !== '' ? $aiSummary : null,
            ':ai_body_draft' => $aiBodyDraft !== '' ? $aiBodyDraft : null,
            ':ai_h2_sections' => $aiH2Json,
            ':ai_status' => $aiStatus,
        ]);
        $caseId = $editCaseId;

    } else {
        // 신규 등록
        $ins = $pdo->prepare(
            'INSERT INTO caify_case
                (member_pk, case_title, raw_content, ai_structured_json, ai_title, ai_summary, ai_body_draft, ai_h2_sections, ai_status)
             VALUES
                (:member_pk, :case_title, :raw_content, :ai_structured_json, :ai_title, :ai_summary, :ai_body_draft, :ai_h2_sections, :ai_status)'
        );
        $ins->execute([
            ':member_pk' => $memberPk,
            ':case_title' => $caseTitle,
            ':raw_content' => $rawContent,
            ':ai_structured_json' => $aiStructuredJson,
            ':ai_title' => $aiTitle !== '' ? $aiTitle : null,
            ':ai_summary' => $aiSummary !== '' ? $aiSummary : null,
            ':ai_body_draft' => $aiBodyDraft !== '' ? $aiBodyDraft : null,
            ':ai_h2_sections' => $aiH2Json,
            ':ai_status' => $aiStatus,
        ]);
        $caseId = (int)$pdo->lastInsertId();
    }

    // 이미지 파일 처리
    $finfo        = new finfo(FILEINFO_MIME_TYPE);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    foreach (normalize_case_files('case_images') as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp  = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;

        $orig = (string)($file['name'] ?? '');
        $size = (int)($file['size']    ?? 0);
        $mime = (string)$finfo->file($tmp);

        if (!in_array($mime, $allowedMimes, true)) continue;

        $ext      = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext === '') $ext = 'bin';

        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destAbs  = rtrim($userUploadAbs, '\\/') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmp, $destAbs)) continue;

        $storedRel    = 'upload/' . $memberFolder . '/' . $filename;
        $movedFiles[] = $destAbs;

        $fIns = $pdo->prepare(
            'INSERT INTO caify_case_file (case_id, member_pk, original_name, stored_path, mime_type, file_size)
             VALUES (:case_id, :member_pk, :original_name, :stored_path, :mime_type, :file_size)'
        );
        $fIns->execute([
            ':case_id'       => $caseId,
            ':member_pk'     => $memberPk,
            ':original_name' => $orig !== '' ? $orig : null,
            ':stored_path'   => $storedRel,
            ':mime_type'     => $mime !== '' ? $mime : null,
            ':file_size'     => $size > 0    ? $size : null,
        ]);

        $fileId = (int)$pdo->lastInsertId();
        if ($fileId > 0) {
            $newImageFilesForAnalysis[] = [
                'file_id' => $fileId,
                'case_id' => $caseId,
                'member_pk' => $memberPk,
                'abs_path' => $destAbs,
                'stored_path' => $storedRel,
                'original_name' => $orig,
            ];
        }
    }

    $pdo->commit();

    // 이미지 메타데이터 분석(Python + GPT Vision) & DB 저장 (best-effort)
    try {
        if (count($newImageFilesForAnalysis) > 0) {
            $projectRoot = realpath(__DIR__ . '/..');
            $py = ($projectRoot !== false)
                ? (rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'image.py')
                : (__DIR__ . '/../api/image.py');

            foreach ($newImageFilesForAnalysis as $q) {
                if (!is_array($q)) {
                    continue;
                }

                $absPath = (string)($q['abs_path'] ?? '');
                $fileId = (int)($q['file_id'] ?? 0);
                $linkedCaseId = (int)($q['case_id'] ?? 0);
                $linkedMemberPk = (int)($q['member_pk'] ?? 0);

                if ($absPath === '' || $fileId <= 0 || $linkedCaseId <= 0 || $linkedMemberPk <= 0) {
                    continue;
                }

                $arg1 = base64_encode($absPath);
                $output = '';
                $cmd = '';

                if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') {
                    $cmd = 'python ' . escapeshellarg($py) . ' ' . escapeshellarg($arg1) . ' 2>&1';
                    $output = (string)shell_exec($cmd);
                } else {
                    $venvPython = defined('PYTHON_VENV_PATH') ? PYTHON_VENV_PATH : '';

                    if ($venvPython === '' || !is_file($venvPython)) {
                        $candidates = [
                            ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python3' : '',
                            '/usr/share/nginx/html/env/bin/python3',
                            ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python' : '',
                        ];

                        foreach ($candidates as $path) {
                            if ($path !== '' && is_file($path)) {
                                $venvPython = $path;
                                break;
                            }
                        }
                    }

                    if ($venvPython !== '' && is_file($venvPython)) {
                        $cmd = escapeshellarg($venvPython) . ' ' . escapeshellarg($py) . ' ' . escapeshellarg($arg1) . ' 2>&1';
                    } else {
                        $cmd = 'python3 ' . escapeshellarg($py) . ' ' . escapeshellarg($arg1) . ' 2>&1';
                    }

                    $output = (string)shell_exec($cmd);
                }

                $output = trim($output);
                $meta = json_decode($output, true);
                $status = 'success';
                $errorText = null;

                if (!is_array($meta)) {
                    $status = 'error';
                    $errorText = 'Invalid JSON from python';
                    $meta = [
                        'error' => 'Invalid JSON from python',
                        'raw_output' => $output !== '' ? substr($output, 0, 1000) : '(empty)',
                        'command' => substr($cmd, 0, 200),
                        'image_path' => substr($absPath, -100),
                    ];
                } elseif (!empty($meta['error'])) {
                    $status = 'error';
                    $errorText = is_string($meta['error']) ? $meta['error'] : 'error';
                }

                $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
                if ($metaJson === false) {
                    $metaJson = json_encode(['error' => 'json_encode_failed'], JSON_UNESCAPED_UNICODE);
                    $status = 'error';
                    $errorText = 'json_encode_failed';
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO caify_case_file_meta
                        (file_id, case_id, member_pk, model, status, meta_json, error_text)
                     VALUES
                        (:file_id, :case_id, :member_pk, :model, :status, :meta_json, :error_text)
                     ON DUPLICATE KEY UPDATE
                        model = VALUES(model),
                        status = VALUES(status),
                        meta_json = VALUES(meta_json),
                        error_text = VALUES(error_text),
                        updated_at = CURRENT_TIMESTAMP'
                );
                $stmt->execute([
                    ':file_id' => $fileId,
                    ':case_id' => $linkedCaseId,
                    ':member_pk' => $linkedMemberPk,
                    ':model' => 'gpt-4o-mini',
                    ':status' => $status,
                    ':meta_json' => $metaJson,
                    ':error_text' => $errorText,
                ]);
            }
        }
    } catch (Throwable $e) {
        // ignore: 메타 분석 실패가 사례 저장 자체를 막지 않도록 유지
    }

    case_swal_redirect('저장 완료!', '고객 사례가 저장되었습니다.', 'success', 'case_short.php?id=' . $caseId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($movedFiles as $p) { @unlink($p); }

    $errMsg = '저장 중 오류가 발생했습니다. 다시 시도해주세요.';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $errMsg .= ' [' . $e->getMessage() . ']';
    }
    $backUrl = $editCaseId > 0 ? 'case_short.php?id=' . $editCaseId : 'case_short.php';
    case_swal_redirect('저장 실패', $errMsg, 'error', $backUrl);
}
