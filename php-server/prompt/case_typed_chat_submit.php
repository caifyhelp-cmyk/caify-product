<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');

$__chat_submit_json_sent = false;
function chat_submit_json_exit(array $payload): void
{
    global $__chat_submit_json_sent;
    if ($__chat_submit_json_sent) {
        exit;
    }
    $__chat_submit_json_sent = true;
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ob_start();
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        chat_submit_json_exit([
            'success' => false,
            'error' => (defined('DEBUG_MODE') && DEBUG_MODE)
                ? ('서버 오류: ' . ($error['message'] ?? 'unknown'))
                : '저장 처리 중 서버 오류가 발생했습니다.',
        ]);
    }
});

require_login();
$member = current_member();

$memberPk = (int)($member['id'] ?? 0);
$memberId = (string)($member['member_id'] ?? '');

if ($memberPk <= 0) {
    chat_submit_json_exit(['success' => false, 'error' => '로그인이 필요합니다.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_submit_json_exit(['success' => false, 'error' => '잘못된 요청입니다.']);
}

function cs_chat(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function normalize_case_files_chat(string $key): array
{
    if (empty($_FILES[$key]) || !is_array($_FILES[$key])) return [];
    $f = $_FILES[$key];
    if (isset($f['name']) && is_array($f['name'])) {
        $out = [];
        for ($i = 0; $i < count($f['name']); $i++) {
            $out[] = [
                'name' => (string)($f['name'][$i] ?? ''),
                'type' => (string)($f['type'][$i] ?? ''),
                'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($f['size'][$i] ?? 0),
            ];
        }
        return $out;
    }
    return [[
        'name' => (string)($f['name'] ?? ''),
        'type' => (string)($f['type'] ?? ''),
        'tmp_name' => (string)($f['tmp_name'] ?? ''),
        'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($f['size'] ?? 0),
    ]];
}

function build_chat_back_url(int $caseId, string $caseInputType): string
{
    $base = 'case_short_typed_chat_.php';
    $params = [];
    if ($caseId > 0) $params['id'] = $caseId;
    if ($caseInputType !== '') $params['type'] = $caseInputType;
    return $params ? $base . '?' . http_build_query($params) : 'case_type_select_chat.php';
}

function build_case_title_from_answer(string $answer, string $typeLabel): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($answer)));
    if ($plain !== '') {
        $short = mb_substr($plain, 0, 36, 'UTF-8');
        return $short;
    }
    return $typeLabel . ' 사례';
}

function build_case_image_assets_chat(PDO $pdo, int $caseId, int $memberPk): array
{
    $stmt = $pdo->prepare(
        'SELECT f.id, f.original_name, f.stored_path, m.meta_json
           FROM caify_case_file f
           LEFT JOIN caify_case_file_meta m ON m.file_id = f.id
          WHERE f.case_id = :case_id
            AND f.member_pk = :member_pk
          ORDER BY f.id DESC'
    );
    $stmt->execute([
        ':case_id' => $caseId,
        ':member_pk' => $memberPk,
    ]);

    $assets = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $summaryParts = array_filter([
            trim((string)($meta['subject']['primary'] ?? '')),
            trim((string)($meta['scene']['scene_type'] ?? '')),
            trim((string)($meta['scene']['visual_role'] ?? '')),
            trim((string)($meta['mood']['mood'] ?? '')),
        ], static function ($value): bool {
            return trim((string)$value) !== '';
        });

        $assets[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['original_name'] ?? 'image'),
            'url' => '../' . ltrim((string)($row['stored_path'] ?? ''), '/'),
            'summary' => count($summaryParts) > 0 ? implode(' / ', $summaryParts) : '수동 추가 이미지',
            'description' => trim((string)($meta['description'] ?? '')),
            'subtitle_candidate' => trim((string)($meta['audio_text']['subtitle_candidate'] ?? '')),
            'keywords' => is_array($meta['keywords'] ?? null) ? array_values($meta['keywords']) : [],
            'has_meta' => count($meta) > 0,
        ];
    }

    return $assets;
}

$editCaseId = (int)cs_chat('case_id');
$caseInputType = cs_chat('case_input_type');
$caseTitle = cs_chat('case_title');
$rawContent = cs_chat('raw_content');
$targetKeywords = cs_chat('target_keywords');

$question1Answer = cs_chat('question1_answer');
$question2Answer = cs_chat('question2_answer');
$question3Answer = cs_chat('question3_answer');
$question2QuestionsRaw = cs_chat('question2_questions_json');
$question3QuestionsRaw = cs_chat('question3_questions_json');
$question3Skipped = cs_chat('question3_skipped') === '1';

$aiIndustryCategory = cs_chat('ai_industry_category');
$aiCaseCategory = cs_chat('ai_case_category');
$aiSubjectLabel = cs_chat('ai_subject_label');
$aiProblemSummary = cs_chat('ai_problem_summary');
$aiProcessSummary = cs_chat('ai_process_summary');
$aiResultSummary = cs_chat('ai_result_summary');
$aiTitle = cs_chat('ai_title');
$aiSummary = cs_chat('ai_summary');
$aiBodyDraft = cs_chat('ai_body_draft');
$aiStatus = cs_chat('ai_status');
$aiImageLayoutRaw = cs_chat('ai_image_layout_json');

$aiH2Raw = $_POST['ai_h2'] ?? [];
$aiH2Sections = [];
if (is_array($aiH2Raw)) {
    foreach ($aiH2Raw as $value) {
        $aiH2Sections[] = trim((string)$value);
    }
}
$aiH2Json = count(array_filter($aiH2Sections)) > 0
    ? json_encode($aiH2Sections, JSON_UNESCAPED_UNICODE)
    : null;

if (!in_array($aiStatus, ['pending', 'done', 'error'], true)) {
    $aiStatus = 'pending';
}

$typeMap = [
    'problem_solve' => '문제 해결',
    'process_work' => '작업/진행 과정',
    'consulting_qa' => '상담/문의',
    'review_experience' => '고객 경험/후기',
];
$typeLabel = $typeMap[$caseInputType] ?? '상담형';

if ($caseTitle === '') {
    $caseTitle = build_case_title_from_answer($question1Answer, $typeLabel);
}

$errors = [];
if ($question1Answer === '') $errors[] = '질문 1 답변은 필수입니다.';
if ($rawContent === '') $errors[] = '상담 내용을 먼저 정리해주세요.';

if (count($errors) > 0) {
    chat_submit_json_exit([
        'success' => false,
        'error' => implode(' ', $errors),
        'redirect_url' => build_chat_back_url($editCaseId, $caseInputType),
    ]);
}

$question2Questions = json_decode($question2QuestionsRaw, true);
if (!is_array($question2Questions)) $question2Questions = [];
$question3Questions = json_decode($question3QuestionsRaw, true);
if (!is_array($question3Questions)) $question3Questions = [];

$chatFlow = [
    'question1' => [
        'answer' => $question1Answer,
    ],
    'question2' => [
        'questions' => array_values($question2Questions),
        'answer' => $question2Answer,
    ],
    'question3' => [
        'questions' => array_values($question3Questions),
        'answer' => $question3Answer,
        'skipped' => $question3Skipped,
    ],
];

$aiStructured = [
    'industry_category' => $aiIndustryCategory,
    'case_category' => $aiCaseCategory,
    'subject_label' => $aiSubjectLabel,
    'problem_summary' => $aiProblemSummary,
    'process_summary' => $aiProcessSummary,
    'result_summary' => $aiResultSummary,
    'input_case_type' => $caseInputType,
    'chat_flow' => $chatFlow,
];

if ($targetKeywords !== '') {
    $aiStructured['target_keywords'] = $targetKeywords;
}

$titleCandidatesRaw = cs_chat('title_candidates_json');
if ($titleCandidatesRaw !== '') {
    $titleCandidates = json_decode($titleCandidatesRaw, true);
    if (is_array($titleCandidates) && count($titleCandidates) > 0) {
        $aiStructured['title_candidates'] = $titleCandidates;
    }
}

if ($aiImageLayoutRaw !== '') {
    $imageLayout = json_decode($aiImageLayoutRaw, true);
    if (is_array($imageLayout)) {
        $aiStructured['image_layout'] = $imageLayout;
    }
}

$aiStructuredJson = json_encode($aiStructured, JSON_UNESCAPED_UNICODE);

$rootUploadDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'upload';
if ($rootUploadDir === false) {
    $rootUploadDir = __DIR__ . '/../upload';
}

$memberFolder = safe_member_folder($memberId, $memberPk);
$userUploadAbs = rtrim($rootUploadDir, '\\/') . DIRECTORY_SEPARATOR . $memberFolder;
if (!is_dir($userUploadAbs) && !mkdir($userUploadAbs, 0775, true) && !is_dir($userUploadAbs)) {
    chat_submit_json_exit(['success' => false, 'error' => '업로드 폴더 생성에 실패했습니다.']);
}

$pdo = db();
$movedFiles = [];
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$newImageFilesForAnalysis = [];

try {
    $pdo->beginTransaction();

    if ($editCaseId > 0) {
        $check = $pdo->prepare('SELECT id FROM caify_case WHERE id = :id AND member_pk = :member_pk LIMIT 1');
        $check->execute([':id' => $editCaseId, ':member_pk' => $memberPk]);
        if (!is_array($check->fetch())) {
            throw new RuntimeException('수정 권한이 없거나 존재하지 않는 사례입니다.');
        }

        $upd = $pdo->prepare(
            'UPDATE caify_case SET
                case_title = :case_title,
                raw_content = :raw_content,
                ai_structured_json = :ai_structured_json,
                ai_title = :ai_title,
                ai_summary = :ai_summary,
                ai_body_draft = :ai_body_draft,
                ai_h2_sections = :ai_h2_sections,
                ai_status = :ai_status
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

    $uploadGroups = [
        ['key' => 'case_images', 'analyze' => true],
        ['key' => 'manual_images', 'analyze' => false],
    ];

    foreach ($uploadGroups as $group) {
        foreach (normalize_case_files_chat($group['key']) as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) continue;

            $orig = (string)($file['name'] ?? '');
            $size = (int)($file['size'] ?? 0);
            $mime = (string)$finfo->file($tmp);
            if (!in_array($mime, $allowedMimes, true)) continue;

            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if ($ext === '') $ext = 'bin';

            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $destAbs = rtrim($userUploadAbs, '\\/') . DIRECTORY_SEPARATOR . $filename;

            if (!move_uploaded_file($tmp, $destAbs)) continue;

            $storedRel = 'upload/' . $memberFolder . '/' . $filename;
            $movedFiles[] = $destAbs;

            $fIns = $pdo->prepare(
                'INSERT INTO caify_case_file (case_id, member_pk, original_name, stored_path, mime_type, file_size)
                 VALUES (:case_id, :member_pk, :original_name, :stored_path, :mime_type, :file_size)'
            );
            $fIns->execute([
                ':case_id' => $caseId,
                ':member_pk' => $memberPk,
                ':original_name' => $orig !== '' ? $orig : null,
                ':stored_path' => $storedRel,
                ':mime_type' => $mime !== '' ? $mime : null,
                ':file_size' => $size > 0 ? $size : null,
            ]);

            if ($group['analyze']) {
                $newImageFilesForAnalysis[] = [
                    'file_id' => (int)$pdo->lastInsertId(),
                    'case_id' => $caseId,
                    'member_pk' => $memberPk,
                    'abs_path' => $destAbs,
                ];
            }
        }
    }

    $pdo->commit();

    try {
        if (count($newImageFilesForAnalysis) > 0) {
            $projectRoot = realpath(__DIR__ . '/..');
            $py = ($projectRoot !== false)
                ? (rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'image.py')
                : (__DIR__ . '/../api/image.py');

            foreach ($newImageFilesForAnalysis as $q) {
                $absPath = (string)($q['abs_path'] ?? '');
                $fileId = (int)($q['file_id'] ?? 0);
                $cId = (int)($q['case_id'] ?? 0);
                $mPk = (int)($q['member_pk'] ?? 0);
                if ($absPath === '' || $fileId <= 0 || $cId <= 0 || $mPk <= 0) continue;

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
                    ];
                } elseif (!empty($meta['error'])) {
                    $status = 'error';
                    $errorText = is_string($meta['error']) ? $meta['error'] : 'error';
                }

                $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
                if ($metaJson === false) {
                    $metaJson = json_encode(['error' => 'meta_json_encode_failed'], JSON_UNESCAPED_UNICODE);
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
                    ':case_id' => $cId,
                    ':member_pk' => $mPk,
                    ':model' => 'gpt-4o-mini',
                    ':status' => $status,
                    ':meta_json' => $metaJson,
                    ':error_text' => $errorText,
                ]);
            }
        }
    } catch (Throwable $e) {
        // best-effort
    }

    chat_submit_json_exit([
        'success' => true,
        'case_id' => $caseId,
        'redirect_url' => build_chat_back_url($caseId, $caseInputType),
        'message' => '상담형 사례가 저장되었습니다.',
        'image_assets' => build_case_image_assets_chat($pdo, $caseId, $memberPk),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($movedFiles as $path) {
        @unlink($path);
    }

    chat_submit_json_exit([
        'success' => false,
        'error' => (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : '저장 중 오류가 발생했습니다. 다시 시도해주세요.',
        'redirect_url' => build_chat_back_url($editCaseId, $caseInputType),
    ]);
}
