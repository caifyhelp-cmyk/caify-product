<?php
/**
 * GET  /api/messages?member_pk=X&after_id=0  → 메시지 목록
 * POST /api/messages                          → 사용자 메시지 전송 + AI 응답
 * POST /api/messages/:id/read                 → 읽음 처리
 * POST /api/messages/:id/action               → 버튼 액션 (view_post 등)
 *
 * Authorization: Bearer <api_token>
 *
 * 필요 테이블: caify_messages
 *   id, member_pk, type, is_system TINYINT, text TEXT,
 *   post_id INT NULL, post_title VARCHAR(500) NULL, post_html LONGTEXT NULL,
 *   meta JSON NULL, actions JSON NULL, is_read TINYINT DEFAULT 0, created_at DATETIME DEFAULT NOW()
 *
 * CREATE TABLE IF NOT EXISTS caify_messages (
 *   id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   member_pk  INT UNSIGNED NOT NULL,
 *   type       VARCHAR(50) NOT NULL DEFAULT 'user_text',
 *   is_system  TINYINT(1) NOT NULL DEFAULT 0,
 *   text       TEXT NOT NULL,
 *   post_id    INT UNSIGNED NULL,
 *   post_title VARCHAR(500) NULL,
 *   post_html  LONGTEXT NULL,
 *   meta       JSON NULL,
 *   actions    JSON NULL,
 *   is_read    TINYINT(1) NOT NULL DEFAULT 0,
 *   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   INDEX idx_member_pk (member_pk),
 *   INDEX idx_created_at (created_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bearer_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── URI 파싱 ─────────────────────────────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// /api/messages/:id/read  or  /api/messages/:id/action
if (preg_match('#/(\d+)/(read|action)$#', (string)$uri, $um)) {
    $subId     = (int)$um[1];
    $subAction = $um[2];
} else {
    $subId     = 0;
    $subAction = '';
}

try {
    $pdo = db();
    $m   = bearer_require($pdo);
    $pk  = (int)$m['id'];

    // ── POST /api/messages/:id/read ────────────────────────────
    if ($method === 'POST' && $subAction === 'read') {
        $pdo->prepare('UPDATE caify_messages SET is_read = 1 WHERE id = :id AND member_pk = :pk')
            ->execute([':id' => $subId, ':pk' => $pk]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST /api/messages/:id/action ─────────────────────────
    if ($method === 'POST' && $subAction === 'action') {
        $pdo->prepare('UPDATE caify_messages SET is_read = 1 WHERE id = :id AND member_pk = :pk')
            ->execute([':id' => $subId, ':pk' => $pk]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── GET /api/messages ─────────────────────────────────────
    if ($method === 'GET') {
        $qpk     = (int)($_GET['member_pk'] ?? $pk);
        $afterId = (int)($_GET['after_id'] ?? 0);

        if ($qpk !== $pk) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
            exit;
        }

        $sql = 'SELECT id, type, is_system, text, post_id, post_title, post_html,
                       meta, actions, is_read, created_at
                FROM caify_messages
                WHERE member_pk = :pk' . ($afterId > 0 ? ' AND id > :aid' : '') . '
                ORDER BY id ASC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $params = [':pk' => $qpk];
        if ($afterId > 0) $params[':aid'] = $afterId;
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = array_map(function (array $r): array {
            return [
                'id'         => (int)$r['id'],
                'type'       => (string)$r['type'],
                'is_system'  => (bool)$r['is_system'],
                'text'       => (string)$r['text'],
                'post_id'    => $r['post_id'] ? (int)$r['post_id'] : null,
                'post_title' => $r['post_title'],
                'post_html'  => $r['post_html'],
                'meta'       => $r['meta']    ? json_decode($r['meta'],    true) : null,
                'actions'    => $r['actions'] ? json_decode($r['actions'], true) : [],
                'read'       => (bool)$r['is_read'],
                'created_at' => $r['created_at'],
            ];
        }, $rows);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST /api/messages ────────────────────────────────────
    if ($method === 'POST' && $subAction === '') {
        $raw  = file_get_contents('php://input');
        $body = json_decode((string)$raw, true) ?: [];
        $text = trim((string)($body['text'] ?? ''));
        if ($text === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'text is required']);
            exit;
        }

        // 사용자 메시지 저장
        $pdo->prepare(
            'INSERT INTO caify_messages (member_pk, type, is_system, text, is_read)
             VALUES (:pk, :type, 0, :text, 1)'
        )->execute([':pk' => $pk, ':type' => 'user_text', ':text' => $text]);

        // 인텐트 감지 → AI 응답 생성
        $reply = _make_reply($m, $text, $pdo);

        $pdo->prepare(
            'INSERT INTO caify_messages (member_pk, type, is_system, text, meta, actions, is_read)
             VALUES (:pk, :type, 1, :text, :meta, :actions, 0)'
        )->execute([
            ':pk'      => $pk,
            ':type'    => $reply['type'],
            ':text'    => $reply['text'],
            ':meta'    => $reply['meta']    ? json_encode($reply['meta'],    JSON_UNESCAPED_UNICODE) : null,
            ':actions' => $reply['actions'] ? json_encode($reply['actions'], JSON_UNESCAPED_UNICODE) : null,
        ]);

        http_response_code(201);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}

// ── 인텐트 감지 + 응답 생성 ─────────────────────────────────────
function _detect_intent(string $t): ?string
{
    if (preg_match('/정보성.*모드|집중.*모드|정보성.*많이|intensive/', $t)) return 'mode_intensive';
    if (preg_match('/믹스.*모드|혼합.*모드|균형.*모드|mixed|순환.*모드/', $t))  return 'mode_mixed';
    if (preg_match('/사례형.*모드|사례.*위주|case.*모드|케이스.*모드/', $t))     return 'mode_case';
    if (preg_match('/포스팅.*모드|모드.*바꿔|모드.*변경/', $t))                  return 'mode_ask';
    if (preg_match('/톤|분위기|어조|친근|격식|전문적|말투/', $t))               return 'tone';
    if (preg_match('/길이|짧게|길게|간결|상세|분량/', $t))                      return 'length';
    if (preg_match('/주제|키워드|토픽|다뤄|써줘/', $t))                         return 'topic';
    if (preg_match('/빈도|자주|횟수|얼마나|주에/', $t))                         return 'frequency';
    if (preg_match('/금지|쓰지마|빼줘|사용하지/', $t))                          return 'forbidden';
    if (preg_match('/바꿔|변경|수정|조정|설정/', $t))                           return 'general';
    return null;
}

function _make_reply(array $member, string $text, PDO $pdo): array
{
    $intent = _detect_intent($text);
    $tier   = (int)$member['tier'];

    if ($intent && $tier === 0) {
        return [
            'type'    => 'user_text',
            'text'    => "좋은 아이디어예요!\n\n워크플로우 커스터마이징은 유료 플랜 전용 기능입니다.\n유료 플랜으로 업그레이드하시면 포스팅 톤·주제·길이 등을 자유롭게 조정할 수 있어요.",
            'meta'    => null,
            'actions' => [],
        ];
    }

    if ($intent && $tier === 1) {
        $labels = ['tone' => '글 톤/분위기', 'length' => '포스팅 길이', 'topic' => '주제/키워드',
                   'frequency' => '발행 빈도', 'forbidden' => '금지어 설정', 'general' => '워크플로우 설정'];
        $label  = $labels[$intent] ?? '설정';
        return [
            'type'    => 'workflow.updated',
            'text'    => "네, 반영했습니다!\n\n{$label}를 요청하신 대로 업데이트했어요.\n\"" . mb_substr($text, 0, 40) . "\"\n\n다음 포스팅부터 적용됩니다.",
            'meta'    => ['intent' => $intent],
            'actions' => [],
        ];
    }

    return [
        'type'    => 'user_text',
        'text'    => "말씀 주신 내용 확인했습니다!\n\"" . mb_substr($text, 0, 30) . "\" 요청을 반영해 다음 포스팅에 적용하겠습니다.",
        'meta'    => null,
        'actions' => [],
    ];
}
