<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();
$memberPk = (int)($member['id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$caseType = trim((string)($input['case_type'] ?? ''));

$validTypes = ['problem_solve', 'process_work', 'consulting_qa', 'review_experience'];
if (!in_array($caseType, $validTypes, true)) {
    echo json_encode(['error' => '올바른 사례 유형이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($memberPk <= 0) {
    echo json_encode(['error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM caify_prompt WHERE member_pk = :member_pk LIMIT 1');
$stmt->execute([':member_pk' => $memberPk]);
$promptRow = $stmt->fetch();

if (!is_array($promptRow) || empty($promptRow['id'])) {
    echo json_encode(['error' => 'no_prompt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mapGoal = [
    1 => '매출을 늘리고 싶다',
    2 => '예약·방문을 늘리고 싶다',
    3 => '문의·상담을 늘리고 싶다',
    4 => '브랜드를 알리고 싶다',
    5 => '신뢰를 확보하고 싶다',
    6 => '기타',
];

$mapStrengths = [
    1 => '가격이 합리적이다',
    2 => '결과·성과가 명확하다',
    3 => '전문 인력이 직접 제공한다',
    4 => '처리 속도가 빠르다',
    5 => '경험·사례가 많다',
    6 => '접근성이 좋다',
    7 => '사후 관리가 잘 된다',
    8 => '공식 인증·자격을 보유하고 있다',
    9 => '기술력이 높다',
    10 => '기타',
];

function decode_json_int_list_ex(?string $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_map('intval', $decoded) : [];
}

function join_labels_ex(array $values, array $map): string
{
    $out = [];
    foreach ($values as $v) {
        if (isset($map[(int)$v])) {
            $out[] = $map[(int)$v];
        }
    }
    return implode(', ', $out);
}

$goalValue = (int)($promptRow['goal'] ?? 0);
$strengthsArr = decode_json_int_list_ex($promptRow['product_strengths'] ?? null);

$data = [
    'brand_name' => trim((string)($promptRow['brand_name'] ?? '')),
    'product_name' => trim((string)($promptRow['product_name'] ?? '')),
    'industry' => trim((string)($promptRow['industry'] ?? '')),
    'goal' => $mapGoal[$goalValue] ?? '',
    'strengths' => join_labels_ex($strengthsArr, $mapStrengths),
    'case_type' => $caseType,
];

$projectRoot = realpath(__DIR__ . '/..');
$activate    = $projectRoot . '/env/bin/activate';
$pyScript    = __DIR__ . '/case_type_examples_ai.py';

$command = "bash -c 'source " . escapeshellarg($activate) . " && python3 " . escapeshellarg($pyScript) . " " .
    escapeshellarg(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE))) . " 2>&1'";

$output = shell_exec($command);
if ($output === null) {
    echo json_encode(['error' => 'Python 실행 실패', 'command' => $command], JSON_UNESCAPED_UNICODE);
    exit;
}

$output = trim($output);
$response = json_decode($output, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'JSON 파싱 실패', 'raw' => mb_substr($output, 0, 500)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($response['response'])) {
    echo json_encode(['success' => true, 'data' => $response['response']], JSON_UNESCAPED_UNICODE);
} elseif (isset($response['error'])) {
    echo json_encode(['error' => $response['error'], 'raw' => $response['raw'] ?? ''], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['error' => 'AI 응답 오류', 'raw' => mb_substr($output, 0, 500)], JSON_UNESCAPED_UNICODE);
}
exit;
