<?php
/**
 * GET /member/me
 * 내 정보 조회 — tier, 워크플로우 여부 포함
 *
 * Authorization: Bearer <api_token>
 * Response: { ok, member_pk, member_id, company_name, tier, has_workflows, n8n_workflow_ids }
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bearer_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$m = bearer_require();

$wfIds = null;
if (!empty($m['n8n_workflow_ids'])) {
    $decoded = json_decode((string)$m['n8n_workflow_ids'], true);
    $wfIds   = is_array($decoded) ? $decoded : null;
}

echo json_encode([
    'ok'              => true,
    'member_pk'       => (int)$m['id'],
    'member_id'       => (string)$m['member_id'],
    'company_name'    => (string)$m['company_name'],
    'tier'            => (int)$m['tier'],
    'has_workflows'   => $wfIds !== null,
    'n8n_workflow_ids'=> $wfIds,
], JSON_UNESCAPED_UNICODE);
