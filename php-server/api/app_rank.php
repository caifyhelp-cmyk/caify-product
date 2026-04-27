<?php
/**
 * GET  /api/rank?member_pk=X   → 키워드 순위 목록
 * POST /api/rank/check         → 네이버 블로그 순위 크롤링 + 저장
 *
 * Authorization: Bearer <api_token>
 *
 * 필요 테이블: caify_rank
 *   id, member_pk, keyword VARCHAR(200), rank INT NULL, found TINYINT,
 *   checked_at DATETIME DEFAULT NOW()
 *
 * CREATE TABLE IF NOT EXISTS caify_rank (
 *   id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   member_pk  INT UNSIGNED NOT NULL,
 *   keyword    VARCHAR(200) NOT NULL,
 *   rank       INT NULL,
 *   found      TINYINT(1) NOT NULL DEFAULT 0,
 *   checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   INDEX idx_member_keyword (member_pk, keyword),
 *   INDEX idx_checked_at (checked_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
$isCheck = str_ends_with((string)$uri, '/check');

try {
    $pdo = db();
    $m   = bearer_require($pdo);
    $pk  = (int)$m['id'];

    // ── GET /api/rank ─────────────────────────────────────────
    if ($method === 'GET') {
        $qpk = (int)($_GET['member_pk'] ?? $pk);
        if ($qpk !== $pk) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']); exit;
        }

        // 각 키워드별 최신 2개 기록
        $sql = 'SELECT r1.keyword,
                       r1.rank      AS rank,
                       r1.found,
                       r1.checked_at,
                       r2.rank      AS prev_rank
                FROM caify_rank r1
                LEFT JOIN caify_rank r2 ON r2.id = (
                    SELECT id FROM caify_rank
                    WHERE member_pk = r1.member_pk AND keyword = r1.keyword AND id < r1.id
                    ORDER BY id DESC LIMIT 1
                )
                WHERE r1.id IN (
                    SELECT MAX(id) FROM caify_rank
                    WHERE member_pk = :pk GROUP BY keyword
                )
                ORDER BY r1.keyword';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pk' => $qpk]);
        $rows = $stmt->fetchAll();

        $ranks = array_map(function (array $r): array {
            return [
                'keyword'    => $r['keyword'],
                'rank'       => $r['rank'] !== null ? (int)$r['rank'] : null,
                'prev_rank'  => $r['prev_rank'] !== null ? (int)$r['prev_rank'] : null,
                'found'      => (bool)$r['found'],
                'checked_at' => $r['checked_at'],
            ];
        }, $rows);

        echo json_encode(['ok' => true, 'ranks' => $ranks], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST /api/rank/check ──────────────────────────────────
    if ($method === 'POST' && $isCheck) {
        $raw     = file_get_contents('php://input');
        $body    = json_decode((string)$raw, true) ?: [];
        $keyword = trim((string)($body['keyword'] ?? ''));
        $blogId  = trim((string)($body['blog_id'] ?? $m['blog_id'] ?? ''));

        if ($keyword === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'keyword is required']); exit;
        }
        if ($blogId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'blog_id is required']); exit;
        }

        $result = _naver_blog_rank($keyword, $blogId);

        // 이전 순위 조회
        $prev = $pdo->prepare(
            'SELECT rank FROM caify_rank WHERE member_pk = :pk AND keyword = :kw ORDER BY id DESC LIMIT 1'
        );
        $prev->execute([':pk' => $pk, ':kw' => $keyword]);
        $prevRow  = $prev->fetch();
        $prevRank = $prevRow ? ($prevRow['rank'] !== null ? (int)$prevRow['rank'] : null) : null;

        $pdo->prepare(
            'INSERT INTO caify_rank (member_pk, keyword, rank, found) VALUES (:pk, :kw, :rank, :found)'
        )->execute([
            ':pk'    => $pk,
            ':kw'    => $keyword,
            ':rank'  => $result['rank'],
            ':found' => $result['found'] ? 1 : 0,
        ]);

        echo json_encode([
            'ok'         => true,
            'keyword'    => $keyword,
            'rank'       => $result['rank'],
            'found'      => $result['found'],
            'prev_rank'  => $prevRank,
            'checked_at' => date('c'),
            'message'    => $result['found']
                ? $result['rank'] . '위에서 발견됐습니다.'
                : '상위 50위 내에서 발견되지 않았습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()]);
}

// ── 네이버 블로그 순위 크롤러 ─────────────────────────────────────
function _naver_blog_rank(string $keyword, string $blogId): array
{
    $maxResults = 50;
    $perPage    = 10;
    $pages      = (int)ceil($maxResults / $perPage);
    $seen       = [];
    $rank       = 0;

    for ($page = 1; $page <= $pages; $page++) {
        $start = ($page - 1) * $perPage + 1;
        $url   = 'https://search.naver.com/search.naver?where=blog&query='
                 . rawurlencode($keyword) . '&start=' . $start;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ko-KR,ko;q=0.9',
            'Referer: https://www.naver.com/',
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $html = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($html === false || $html === '') {
            if ($rank === 0) return ['rank' => null, 'found' => false];
            break;
        }

        preg_match_all(
            '#(?:https?://)?(?:m\.)?blog\.naver\.com/([a-zA-Z0-9_.:-]+)/(\d+)#',
            (string)$html, $matches, PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $foundId = $match[1];
            $postId  = $match[2];
            $key     = $foundId . '/' . $postId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $rank++;
            if (strcasecmp($foundId, $blogId) === 0) {
                return ['rank' => $rank, 'found' => true];
            }
            if ($rank >= $maxResults) {
                return ['rank' => null, 'found' => false];
            }
        }

        if ($page < $pages) usleep(300000); // 0.3s
    }

    return ['rank' => null, 'found' => false];
}
