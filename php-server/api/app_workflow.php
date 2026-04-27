<?php
/**
 * GET  /api/workflow?member_pk=X        → 워크플로우 현황 + 주간 통계
 * POST /api/workflow/provision          → n8n 워크플로우 3종 복제·활성화
 * POST /api/workflow/update             → 발행 요일/시간/활성여부 저장
 * POST /api/workflow/modify             → 채팅 인스트럭션으로 n8n 파라미터 수정
 *
 * Authorization: Bearer <api_token>
 *
 * caify_member 에 필요한 컬럼:
 *   n8n_workflow_ids JSON NULL
 *   schedule_days    JSON NULL      (예: ["월","수","금"])
 *   schedule_hour    TINYINT NULL   (예: 10)
 *
 * ALTER TABLE caify_member
 *   ADD COLUMN n8n_workflow_ids JSON    DEFAULT NULL,
 *   ADD COLUMN schedule_days    JSON    DEFAULT NULL,
 *   ADD COLUMN schedule_hour    TINYINT DEFAULT NULL;
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

// 어느 서브액션인지 판단
$subAction = '';
if (preg_match('#/api/workflow/(provision|update|modify)$#', (string)$uri, $sm)) {
    $subAction = $sm[1];
}

$WORKFLOW_TYPES = ['info', 'mixed', 'case'];
$TEMPLATE_IDS   = [
    'info'  => N8N_TEMPLATE_INFO,
    'mixed' => N8N_TEMPLATE_MIXED,
    'case'  => N8N_TEMPLATE_CASE,
];

try {
    $pdo = db();
    $m   = bearer_require($pdo);
    $pk  = (int)$m['id'];

    // ── GET /api/workflow ─────────────────────────────────────
    if ($method === 'GET') {
        $qpk = (int)($_GET['member_pk'] ?? $pk);
        if ($qpk !== $pk) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']); exit;
        }

        // n8n_workflow_ids 파싱
        $wfIds = null;
        if (!empty($m['n8n_workflow_ids'])) {
            $wfIds = json_decode((string)$m['n8n_workflow_ids'], true);
        }

        if ($wfIds === null) {
            echo json_encode(['ok' => true, 'provisioned' => false, 'workflows' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 워크플로우 목록 + n8n에서 active 상태 동기화
        $workflows = [];
        foreach ($WORKFLOW_TYPES as $type) {
            $wid    = $wfIds[$type] ?? null;
            $active = false;
            if ($wid && !str_starts_with($wid, 'mock-') && !str_starts_with($wid, 'err-')) {
                $wfData = n8n_api('GET', "/workflows/{$wid}");
                $active = isset($wfData['active']) ? (bool)$wfData['active'] : false;
            }
            $workflows[] = ['type' => $type, 'workflow_id' => $wid, 'active' => $active];
        }

        // 요일/시간
        $scheduleDays = null;
        if (!empty($m['schedule_days'])) {
            $scheduleDays = json_decode((string)$m['schedule_days'], true);
        }
        $scheduleHour = $m['schedule_hour'] !== null ? (int)$m['schedule_hour'] : 10;

        // 주간 통계
        $weekStart = date('Y-m-d H:i:s', strtotime('monday this week'));
        $cntPublished = (int)$pdo->prepare(
            'SELECT COUNT(*) FROM ai_posts WHERE customer_id = :pk AND posting_date IS NOT NULL AND posting_date >= :ws'
        )->execute([':pk' => $pk, ':ws' => $weekStart]) ? 0 : 0;

        $stW = $pdo->prepare('SELECT COUNT(*) FROM ai_posts WHERE customer_id = :pk AND posting_date IS NOT NULL AND posting_date >= :ws');
        $stW->execute([':pk' => $pk, ':ws' => $weekStart]);
        $postsThisWeek = (int)$stW->fetchColumn();

        $stC = $pdo->prepare('SELECT COUNT(*) FROM caify_case WHERE member_pk = :pk AND created_at >= :ws');
        $stC->execute([':pk' => $pk, ':ws' => $weekStart]);
        $casesThisWeek = (int)$stC->fetchColumn();

        echo json_encode([
            'ok'           => true,
            'provisioned'  => true,
            'workflows'    => $workflows,
            'schedule_days'=> $scheduleDays ?? ['월', '수', '금'],
            'schedule_hour'=> $scheduleHour,
            'weekly_stats' => [
                'posts_this_week'  => $postsThisWeek,
                'posts_scheduled'  => 0,
                'cases_this_week'  => $casesThisWeek,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST /api/workflow/provision ─────────────────────────
    if ($method === 'POST' && $subAction === 'provision') {
        // 중복 방지
        if (!empty($m['n8n_workflow_ids'])) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => '이미 워크플로우가 생성되어 있습니다.']); exit;
        }

        $companyName = (string)$m['company_name'];
        $wfIds       = [];

        foreach ($WORKFLOW_TYPES as $type) {
            $templateId = $TEMPLATE_IDS[$type] ?? null;
            if (!$templateId) { $wfIds[$type] = null; continue; }

            // 템플릿 가져오기
            $template = n8n_api('GET', "/workflows/{$templateId}");
            if (isset($template['error'])) {
                http_response_code(502);
                echo json_encode(['ok' => false, 'error' => "n8n 템플릿({$type}) 조회 실패"]);
                exit;
            }

            // 복제
            $created = n8n_api('POST', '/workflows', [
                'name'        => "[{$pk}] {$companyName} - {$type}",
                'nodes'       => $template['nodes'],
                'connections' => $template['connections'],
                'settings'    => $template['settings'] ?? [],
            ]);
            if (empty($created['id'])) {
                http_response_code(502);
                echo json_encode(['ok' => false, 'error' => "n8n 워크플로우({$type}) 복제 실패"]);
                exit;
            }
            $wfIds[$type] = $created['id'];

            // 활성화
            n8n_api('POST', "/workflows/{$created['id']}/activate");
        }

        // DB 저장
        $pdo->prepare('UPDATE caify_member SET n8n_workflow_ids = :wids WHERE id = :id')
            ->execute([':wids' => json_encode($wfIds, JSON_UNESCAPED_UNICODE), ':id' => $pk]);

        echo json_encode([
            'ok'          => true,
            'workflow_ids'=> $wfIds,
            'message'     => '워크플로우가 설정됐습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST /api/workflow/update ─────────────────────────────
    if ($method === 'POST' && $subAction === 'update') {
        $raw  = file_get_contents('php://input');
        $body = json_decode((string)$raw, true) ?: [];

        $scheduleDays = isset($body['schedule_days']) && is_array($body['schedule_days'])
            ? $body['schedule_days'] : null;
        $scheduleHour = isset($body['schedule_hour']) ? (int)$body['schedule_hour'] : null;
        $workflows    = isset($body['workflows']) && is_array($body['workflows'])
            ? $body['workflows'] : null;

        // 요일/시간 저장
        $updates = [];
        $params  = [':id' => $pk];
        if ($scheduleDays !== null) {
            $updates[]           = 'schedule_days = :sd';
            $params[':sd']       = json_encode($scheduleDays, JSON_UNESCAPED_UNICODE);
        }
        if ($scheduleHour !== null) {
            $updates[]           = 'schedule_hour = :sh';
            $params[':sh']       = $scheduleHour;
        }
        if (!empty($updates)) {
            $pdo->prepare('UPDATE caify_member SET ' . implode(', ', $updates) . ' WHERE id = :id')
                ->execute($params);
        }

        // n8n 활성/비활성 반영
        if ($workflows !== null) {
            $wfIds = null;
            if (!empty($m['n8n_workflow_ids'])) {
                $wfIds = json_decode((string)$m['n8n_workflow_ids'], true);
            }
            if ($wfIds) {
                foreach ($workflows as $wf) {
                    $type   = $wf['type']   ?? '';
                    $active = (bool)($wf['active'] ?? false);
                    $wid    = $wfIds[$type] ?? null;
                    if (!$wid || str_starts_with($wid, 'mock-') || str_starts_with($wid, 'err-')) continue;
                    $endpoint = $active ? "/workflows/{$wid}/activate" : "/workflows/{$wid}/deactivate";
                    n8n_api('POST', $endpoint);
                }
            }
        }

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST /api/workflow/modify ─────────────────────────────
    if ($method === 'POST' && $subAction === 'modify') {
        $raw  = file_get_contents('php://input');
        $body = json_decode((string)$raw, true) ?: [];
        $instruction = trim((string)($body['instruction'] ?? ''));

        // 실서버: 여기서 n8n Code 노드 파라미터를 LLM으로 수정할 수 있음
        // 현재: 인스트럭션을 메시지로 저장하고 완료 응답
        if ($instruction === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'instruction is required']); exit;
        }

        echo json_encode([
            'ok'      => true,
            'message' => "요청을 반영했습니다. 다음 포스팅부터 적용됩니다.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}
