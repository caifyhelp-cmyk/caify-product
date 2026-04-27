<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// =======================
// 1. JSON 수신
// =======================
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON', 'data' => $data]);
    exit;
}

// =======================
// 2. 필수값 체크
// =======================
$title         = trim((string)($data['title'] ?? ''));
$html          = trim((string)($data['html'] ?? ''));
$naverHtml     = trim((string)($data['naverHtml'] ?? ''));
$customerId    = trim((string)($data['customer_id'] ?? ''));
$promptId      = trim((string)($data['prompt_id'] ?? ''));
$promptNodeId  = trim((string)($data['promptNodeId'] ?? ''));

if ($title === '' || $naverHtml === '' || $customerId === '' || $promptNodeId === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'title, html, naverHtml, customer_id, promptNodeId are required'
    ]);
    exit;
}

// 선택값
$subject = trim((string)($data['subject'] ?? ''));
$intro   = trim((string)($data['intro'] ?? ''));
$caseId  = (int)($data['case_id'] ?? 0);  // 사례형 워크플로우에서 전달

// =======================
// 3. DB 저장 (INSERT ONLY)
// =======================
try {
    $pdo = db();

    $sql = "
    INSERT INTO ai_posts
    (
        customer_id,
        prompt_id,
        prompt_node_id,
        title,
        subject,
        intro,
        html,
        naver_html
    )
    VALUES
    (
        :customer_id,
        :prompt_id,
        :prompt_node_id,
        :title,
        :subject,
        :intro,
        :html,
        :naver_html
    )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':customer_id'   => $customerId,
        ':prompt_id'     => $promptId,
        ':prompt_node_id'=> $promptNodeId,
        ':title'         => $title,
        ':subject'       => $subject ?: null,
        ':intro'         => $intro ?: null,
        ':html'          => $html,
        ':naver_html'    => $naverHtml,
    ]);

    $insertId = (int)$pdo->lastInsertId();

    // 사례형 워크플로우: case_id 있으면 caify_case.ai_status 를 done으로 업데이트
    if ($caseId > 0) {
        try {
            $pdo->prepare(
                'UPDATE caify_case SET ai_status = :st WHERE id = :id'
            )->execute([':st' => 'done', ':id' => $caseId]);
        } catch (Throwable $ue) {
            // best-effort: ai_posts 저장은 성공했으므로 무시
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Inserted successfully',
        'insert_id' => $insertId
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}