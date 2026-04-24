<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$__chat_ai_json_sent = false;
function chat_ai_json_exit(array $payload): void
{
    global $__chat_ai_json_sent;
    if ($__chat_ai_json_sent) {
        exit;
    }
    $__chat_ai_json_sent = true;
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
        chat_ai_json_exit([
            'error' => (defined('DEBUG_MODE') && DEBUG_MODE)
                ? ('서버 오류: ' . ($error['message'] ?? 'unknown'))
                : 'AI 처리 중 서버 오류가 발생했습니다.',
        ]);
    }
});

require_login();
$member = current_member();
$memberPk = (int)($member['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_ai_json_exit(['error' => '잘못된 요청입니다.']);
}

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);
if (!is_array($input)) {
    chat_ai_json_exit(['error' => '데이터 형식이 올바르지 않습니다.']);
}

function decode_json_int_list_chat(?string $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_map('intval', $decoded) : [];
}

function join_prompt_labels_chat(array $values, array $map): string
{
    $out = [];
    foreach ($values as $value) {
        $key = (int)$value;
        if (isset($map[$key])) {
            $out[] = $map[$key];
        }
    }
    return implode(' / ', $out);
}

$caseData = [
    'mode' => trim((string)($input['mode'] ?? 'followup2')),
    'case_id' => (int)($input['case_id'] ?? 0),
    'case_title' => trim((string)($input['case_title'] ?? '')),
    'case_input_type' => trim((string)($input['case_input_type'] ?? '')),
    'raw_content' => trim((string)($input['raw_content'] ?? '')),
    'target_keywords' => trim((string)($input['target_keywords'] ?? '')),
    'ai_title' => trim((string)($input['ai_title'] ?? '')),
    'ai_summary' => trim((string)($input['ai_summary'] ?? '')),
    'ai_h2_sections' => $input['ai_h2_sections'] ?? [],
    'structured' => $input['structured'] ?? [],
    'question1_answer' => trim((string)($input['question1_answer'] ?? '')),
    'question2_answer' => trim((string)($input['question2_answer'] ?? '')),
    'question3_answer' => trim((string)($input['question3_answer'] ?? '')),
    'file_summary' => trim((string)($input['file_summary'] ?? '')),
];

$promptProfile = [];
if ($memberPk > 0) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM caify_prompt WHERE member_pk = :member_pk LIMIT 1');
    $stmt->execute([':member_pk' => $memberPk]);
    $promptRow = $stmt->fetch();

    if (is_array($promptRow)) {
        $mapGoal = [1 => '매출을 늘리고 싶다', 2 => '예약·방문을 늘리고 싶다', 3 => '문의·상담을 늘리고 싶다', 4 => '브랜드를 알리고 싶다', 5 => '신뢰를 확보하고 싶다', 6 => '기타'];
        $mapStrengths = [1 => '가격이 합리적이다', 2 => '결과·성과가 명확하다', 3 => '전문 인력이 직접 제공한다', 4 => '처리 속도가 빠르다', 5 => '경험·사례가 많다', 6 => '접근성이 좋다', 7 => '사후 관리가 잘 된다', 8 => '공식 인증·자격을 보유하고 있다', 9 => '기술력이 높다', 10 => '기타'];
        $mapTones = [1 => '차분하게 설명한다', 2 => '친절하게 쉽게 설명한다', 3 => '단호하고 확신 있게 말한다', 4 => '전문가가 조언하는 느낌'];
        $mapContentStyles = [1 => '짧은 문장 위주', 2 => '핵심 요약', 3 => '질문으로 마무리', 4 => '숫자·근거 강조'];
        $mapActionStyle = [1 => '정보만 제공하고 판단은 맡긴다', 2 => '관심이 생기도록 자연스럽게 유도한다', 3 => '지금 바로 행동하도록 안내한다'];
        $mapExpression = [1 => '과장된 표현', 2 => '가격·할인 언급', 3 => '타사 비교·비방 표현', 4 => '기타'];

        $goalValue = (int)($promptRow['goal'] ?? 0);
        $actionStyleValue = (int)($promptRow['action_style'] ?? 0);

        $promptProfile = [
            'brand_name' => trim((string)($promptRow['brand_name'] ?? '')),
            'product_name' => trim((string)($promptRow['product_name'] ?? '')),
            'industry' => trim((string)($promptRow['industry'] ?? '')),
            'goal' => $mapGoal[$goalValue] ?? '',
            'strengths' => join_prompt_labels_chat(decode_json_int_list_chat($promptRow['product_strengths'] ?? null), $mapStrengths),
            'tones' => join_prompt_labels_chat(decode_json_int_list_chat($promptRow['tones'] ?? null), $mapTones),
            'content_styles' => join_prompt_labels_chat(decode_json_int_list_chat($promptRow['content_styles'] ?? null), $mapContentStyles),
            'extra_strength' => trim((string)($promptRow['extra_strength'] ?? '')),
            'action_style' => $mapActionStyle[$actionStyleValue] ?? '',
            'forbidden_phrases' => trim((string)($promptRow['forbidden_phrases'] ?? '')),
            'forbidden_expressions' => join_prompt_labels_chat(decode_json_int_list_chat($promptRow['expressions'] ?? null), $mapExpression),
            'inquiry_channels' => trim((string)($promptRow['inquiry_channels'] ?? '')),
            'inquiry_phone' => trim((string)($promptRow['inquiry_phone'] ?? '')),
        ];
    }
}

$caseData['prompt_profile'] = $promptProfile;
$mode = strtolower($caseData['mode']);

if (in_array($mode, ['analyze', 'draft'], true)) {
    if ($caseData['case_id'] <= 0) {
        chat_ai_json_exit(['error' => '사례를 먼저 저장해주세요.']);
    }

    $pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : db();
    $caseStmt = $pdo->prepare(
        'SELECT id
           FROM caify_case
          WHERE id = :id AND member_pk = :member_pk
          LIMIT 1'
    );
    $caseStmt->execute([
        ':id' => $caseData['case_id'],
        ':member_pk' => $memberPk,
    ]);

    if (!is_array($caseStmt->fetch())) {
        chat_ai_json_exit(['error' => '사례를 찾을 수 없거나 접근 권한이 없습니다.']);
    }

    $f = $pdo->prepare(
        'SELECT f.id, f.original_name, f.stored_path, m.status, m.meta_json
           FROM caify_case_file f
           LEFT JOIN caify_case_file_meta m ON m.file_id = f.id
          WHERE f.case_id = :case_id
            AND f.member_pk = :member_pk
          ORDER BY f.id ASC'
    );
    $f->execute([
        ':case_id' => $caseData['case_id'],
        ':member_pk' => $memberPk,
    ]);

    $imageContexts = [];
    foreach ($f->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        if (!is_array($meta) || count($meta) === 0) {
            continue;
        }

        $imageContexts[] = [
            'file_id' => (int)($row['id'] ?? 0),
            'original_name' => trim((string)($row['original_name'] ?? '')),
            'stored_path' => trim((string)($row['stored_path'] ?? '')),
            'meta_status' => trim((string)($row['status'] ?? '')),
            'description' => trim((string)($meta['description'] ?? '')),
            'keywords' => is_array($meta['keywords'] ?? null) ? array_values($meta['keywords']) : [],
            'subject_primary' => trim((string)($meta['subject']['primary'] ?? '')),
            'scene_type' => trim((string)($meta['scene']['scene_type'] ?? '')),
            'visual_role' => trim((string)($meta['scene']['visual_role'] ?? '')),
            'mood' => trim((string)($meta['mood']['mood'] ?? '')),
            'subtitle_candidate' => trim((string)($meta['audio_text']['subtitle_candidate'] ?? '')),
        ];
    }
    $caseData['image_contexts'] = $imageContexts;
}

if ($mode === 'followup2' && $caseData['question1_answer'] === '') {
    chat_ai_json_exit(['error' => '질문 1 답변을 먼저 입력해주세요.']);
}
if ($mode === 'followup3' && ($caseData['question1_answer'] === '' || $caseData['question2_answer'] === '')) {
    chat_ai_json_exit(['error' => '질문 1과 질문 2 답변이 필요합니다.']);
}
if ($mode === 'analyze' && $caseData['raw_content'] === '') {
    chat_ai_json_exit(['error' => '상담 답변이 비어 있습니다.']);
}
if ($mode === 'draft' && ($caseData['raw_content'] === '' || $caseData['ai_title'] === '')) {
    chat_ai_json_exit(['error' => '본문 초안 생성을 위한 정보가 부족합니다.']);
}

$projectRoot = realpath(__DIR__ . '/..');
$activate = $projectRoot . '/env/bin/activate';
$pyScript = __DIR__ . '/case_typed_chat_ai_.py';
$command = "bash -c 'source " . escapeshellarg($activate) . " && python3 " . escapeshellarg($pyScript) . " " .
    escapeshellarg(base64_encode(json_encode($caseData, JSON_UNESCAPED_UNICODE))) . " 2>&1'";

$output = shell_exec($command);
if ($output === null) {
    chat_ai_json_exit(['error' => 'Python 스크립트 실행에 실패했습니다.']);
}

$response = json_decode($output, true);
if (isset($response['response'])) {
    chat_ai_json_exit(['success' => true, 'data' => $response['response']]);
} else {
    chat_ai_json_exit(['error' => 'AI 응답 처리 중 오류가 발생했습니다.', 'raw' => $output]);
}
