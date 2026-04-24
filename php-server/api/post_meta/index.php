<?php
declare(strict_types=1);

require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/n8n_client.php';

header('Content-Type: application/json; charset=UTF-8');

// ID 받기
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID가 필요합니다.']);
    exit;
}

// 매핑 (숫자 → 텍스트)
$map_service_types = [
    1 => '오프라인 매장',
    2 => '온라인 서비스',
    3 => '전국 서비스',
    4 => '프랜차이즈',
    5 => '기타',
];
$map_ages = [
    1 => '10대',
    2 => '20대',
    3 => '30대',
    4 => '40대',
    5 => '50대',
];
$map_strengths = [
    1 => '가격이 합리적이다.',
    2 => '결과·성과가 명확하다.',
    3 => '전문 인력이 직접 제공한다.',
    4 => '처리 속도가 빠르다.',
    5 => '경험·사례가 많다.',
    6 => '접근성이 좋다.',
    7 => '사후 관리가 잘 된다.',
    8 => '공식 인증·자격을 보유하고 있다.',
    9 => '기술력이 높다.',
    10 => '기타',
];
$map_tones = [
    1 => '차분하게 설명한다.',
    2 => '친절하게 쉽게 설명한다.',
    3 => '단호하고 확신 있게 말한다.',
    4 => '전문가가 조언하는 느낌.',
];
$map_goal = [
    1 => '매출을 늘리고 싶다',
    2 => '예약·방문을 늘리고 싶다.',
    3 => '문의·상담을 늘리고 싶다.',
    4 => '브랜드를 알리고 싶다.',
    5 => '신뢰를 확보하고 싶다.',
    6 => '기타',
];
$map_keep_style = [
    1 => '유지한다.',
    2 => '유지하지 않는다.',
];
$map_content_styles = [
    1 => '짧은 문장 위주',
    2 => '핵심 요약',
    3 => '질문으로 마무리',
    4 => '숫자·근거 강조',
];
$map_action_style = [
    1 => '정보만 제공하고 판단은 맡긴다.',
    2 => '관심이 생기도록 자연스럽게 유도한다.',
    3 => '지금 바로 행동하도록 안내한다.',
];
$map_expression = [
    1 => '과장된 표현',
    2 => '가격·할인 언급',
    3 => '타사 비교·비방 표현',
    4 => '기타',
];

// 배열 → 텍스트 변환 함수
$join = function (array $arr, array $map): string {
    $result = [];
    foreach ($arr as $num) {
        if (isset($map[(int)$num])) {
            $result[] = $map[(int)$num];
        }
    }
    return implode(' / ', $result);
};

try {
    $pdo = db();

    // 1) caify_prompt 조회
    $stmt = $pdo->prepare('SELECT * FROM caify_prompt WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => '데이터를 찾을 수 없습니다.']);
        exit;
    }

    // JSON 파싱
    $service_types = json_decode($row['service_types'] ?? '[]', true) ?: [];
    $ages = json_decode($row['ages'] ?? '[]', true) ?: [];
    $product_strengths = json_decode($row['product_strengths'] ?? '[]', true) ?: [];
    $tones = json_decode($row['tones'] ?? '[]', true) ?: [];
    $content_styles = json_decode($row['content_styles'] ?? '[]', true) ?: [];
    $expressions = json_decode($row['expressions'] ?? '[]', true) ?: [];

    // 2) 파일 목록 조회
    $f = $pdo->prepare('SELECT * FROM caify_prompt_file WHERE prompt_id = :id ORDER BY id DESC');
    $f->execute([':id' => $id]);
    $files = $f->fetchAll();


    // 3) 이미지 메타데이터 조회
    $fileIds = array_column($files ?: [], 'id');
    $imageMeta = [];
    if (count($fileIds) > 0) {
        $in = implode(',', array_fill(0, count($fileIds), '?'));
        $m = $pdo->prepare("SELECT * FROM caify_prompt_file_meta WHERE file_id IN ($in)");
        $m->execute($fileIds);
        foreach ($m->fetchAll() as $meta) {
            $imageMeta[(int)$meta['file_id']] = json_decode($meta['meta_json'] ?? '{}', true) ?: [];
        }
    }
	


    // 4) 파일 목록 정리 (URL + 메타)
    $baseUrl = n8n_base_url();
    $fileList = [];
    foreach ($files as $file) {
        $path = $file['stored_path'] ?? '';
        $fileList[] = [
            'id' => (int)$file['id'],
            'type' => $file['file_type'] ?? '',
            'name' => $file['original_name'] ?? '',
            'url' => $baseUrl ? rtrim($baseUrl, '/') . '/' . ltrim($path, '/') : $path,
            'meta' => $imageMeta[(int)$file['id']] ?? null,
        ];
    }
	
	
    // 5) 결과 반환 (텍스트 변환된 값)
    echo json_encode([
        'ok' => true,
        'id' => (int)$row['id'],
        'member_pk' => $row['member_pk'] ?? '',
        'brand_name' => $row['brand_name'] ?? '',
        'product_name' => $row['product_name'] ?? '',
        'industry' => $row['industry'] ?? '',
        'inquiry_channels' => $row['inquiry_channels'] ?? '',
        'service_types' => $join($service_types, $map_service_types),
        'address' => trim(($row['address1'] ?? '') . ' ' . ($row['address2'] ?? '')),
        'address_zip' => $row['address_zip'] ?? '',
        'goal' => isset($map_goal[(int)($row['goal'] ?? 0)]) ? $map_goal[(int)$row['goal']] : '',
        'ages' => $join($ages, $map_ages),
        'product_strengths' => $join($product_strengths, $map_strengths),
        'tones' => $join($tones, $map_tones),
        'postLengthModeRaw' => $row['postLengthModeRaw'] ?? '',
        'keep_style' => isset($map_keep_style[(int)($row['keep_style'] ?? 0)]) ? $map_keep_style[(int)$row['keep_style']] : '',
        'style_url' => $row['style_url'] ?? '',
        'content_styles' => $join($content_styles, $map_content_styles),
        'extra_strength' => $row['extra_strength'] ?? '',
        'action_style' => isset($map_action_style[(int)($row['action_style'] ?? 0)]) ? $map_action_style[(int)$row['action_style']] : '',
        'expression' => isset($map_expression[(int)($expressions[0] ?? 0)]) ? $map_expression[(int)$expressions[0]] : '',
        'forbidden_phrases' => $row['forbidden_phrases'] ?? '',
        'files' => $fileList,
    ], JSON_UNESCAPED_UNICODE);




} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
}
