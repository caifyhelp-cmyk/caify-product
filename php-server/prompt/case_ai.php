<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input_raw = file_get_contents('php://input');
$input     = json_decode($input_raw, true);

if (!is_array($input)) {
    echo json_encode(['error' => '데이터 형식이 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$case_data = [
    'case_type'        => trim((string)($input['case_type']        ?? '')),
    'customer_name'    => trim((string)($input['customer_name']    ?? '')),
    'customer_info'    => trim((string)($input['customer_info']    ?? '')),
    'service_name'     => trim((string)($input['service_name']     ?? '')),
    'service_period'   => trim((string)($input['service_period']   ?? '')),
    'before_situation' => trim((string)($input['before_situation'] ?? '')),
    'case_process'     => trim((string)($input['case_process']     ?? '')),
    'after_result'     => trim((string)($input['after_result']     ?? '')),
    'case_content'     => trim((string)($input['case_content']     ?? '')),
];

if ($case_data['before_situation'] === '' && $case_data['after_result'] === '') {
    echo json_encode(['error' => '사례 내용을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Python 스크립트 호출 (절대경로 사용 - PHP CWD가 달라도 동작)
$projectRoot = realpath(__DIR__ . '/..');
$activate    = $projectRoot . '/env/bin/activate';
$pyScript    = __DIR__ . '/case_ai.py';

$command = "bash -c 'source " . escapeshellarg($activate) . " && python3 " . escapeshellarg($pyScript) . " " .
    escapeshellarg(base64_encode(json_encode($case_data))) . " 2>&1'";

$output = shell_exec($command);

if ($output === null) {
    echo json_encode(['error' => 'Python 스크립트 실행에 실패했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$response = json_decode($output, true);

if (isset($response['response'])) {
    echo json_encode(['success' => true, 'data' => $response['response']], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['error' => 'AI 응답 처리 중 오류가 발생했습니다.', 'raw' => $output], JSON_UNESCAPED_UNICODE);
}
exit;
