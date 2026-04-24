<?php
declare(strict_types=1);

session_start();

require "../inc/db.php";
require "./bridge_uixlab.php";

function failAndRedirect(string $message): never
{
    echo "<script>alert('" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "'); location.href='output_list.php';</script>";
    exit;
}

function isBridgeDebugEnabled(bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }
    return (string)($_GET['debug'] ?? '') === '1';
}

function renderBridgeDebug(Throwable $e, array $context = []): never
{
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    $safe = static function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    echo "<h2>UIXLAB 브릿지 호출 에러</h2>";
    echo "<p><b>message</b>: " . $safe($e->getMessage()) . "</p>";
    echo "<p><b>type</b>: " . $safe(get_class($e)) . "</p>";
    echo "<p><b>file</b>: " . $safe($e->getFile()) . " : " . (int)$e->getLine() . "</p>";

    if (!empty($context)) {
        echo "<h3>context</h3>";
        echo "<pre style=\"white-space:pre-wrap; word-break:break-word;\">" . $safe(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . "</pre>";
    }

    echo "<h3>trace</h3>";
    echo "<pre style=\"white-space:pre-wrap; word-break:break-word;\">" . $safe($e->getTraceAsString()) . "</pre>";
    exit;
}

$customer_id = (int)($_SESSION['member']['id'] ?? 0);
$is_admin = ($customer_id === 10);
$debugEnabled = isBridgeDebugEnabled($is_admin);
$post_id = (int)($_GET['id'] ?? 0);

if ($customer_id <= 0) {
    header('Location: ../member/login.php');
    exit;
}

if ($post_id <= 0) {
    failAndRedirect('잘못된 요청입니다.');
}

try {
    $pdo = db();

    if ($is_admin) {
        $stmt = $pdo->prepare('
            SELECT id, title, naver_html, customer_id, status, created_at
            FROM ai_posts
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $post_id]);
    } else {
        $stmt = $pdo->prepare('
            SELECT id, title, naver_html, customer_id, status, created_at
            FROM ai_posts
            WHERE id = :id
              AND status = 1
              AND DATE(created_at) <= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
              AND customer_id = :customer_id
            LIMIT 1
        ');
        $stmt->execute([
            ':id' => $post_id,
            ':customer_id' => $customer_id,
        ]);
    }

    $post = $stmt->fetch();

    if (!is_array($post)) {
        failAndRedirect('게재 가능한 산출물을 찾을 수 없습니다.');
    }

    $title = trim((string)($post['title'] ?? ''));
    $raw_html = (string)($post['naver_html'] ?? '');

    // siteCode는 계정 정보(caify_member.uixlab_code)에서 uixlab_code값을 가져오도록 수정
    $member = $pdo->prepare('
        SELECT uixlab_code AS site_code
        FROM caify_member
        WHERE id = :id
        LIMIT 1
    ');
    $member->execute([':id' => $customer_id]);
    $member = $member->fetch();

    if (!is_array($member)) {
        $member = ['site_code' => 'theme_01'];
    } elseif ($member['site_code'] === '' || $member['site_code'] === null) {
        $member['site_code'] = 'theme_01';
    }else{
        $member['site_code'] = trim((string)$member['site_code']);
    }

    $decoded = html_entity_decode($raw_html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $content = strip_tags($decoded);
    $content = preg_replace("/\r\n?/", "\n", $content);
    $content = preg_replace("/[ \t]+\n/", "\n", $content);
    $content = preg_replace("/\n{3,}/", "\n\n", $content);
    $content = trim((string)$content);

    $bridgePayload = [
        'title' => $title,
        'content' => $content,
        'content_html' => $raw_html,
        'source_post_id' => (int)$post['id'],
        'source_customer_id' => (int)$post['customer_id'],
        'source_domain' => UIXLAB_SOURCE_DOMAIN,
        'site_code' => $member['site_code'],
    ];

    // UIXLAB 서버로 "임시저장(draft)" 생성 요청을 보냅니다.
    $apiResult = callUixlabBridgeApi(UIXLAB_DRAFT_CREATE_PATH, $bridgePayload);
    $draftToken = (string)($apiResult['data']['draft_token'] ?? '');
    if ($draftToken === '') {
        throw new RuntimeException('Draft token is missing.');
    }

    $writeUrl = normalizeJoinUrl(UIXLAB_API_BASE, UIXLAB_DRAFT_WRITE_PATH);
    if (!isAllowedBridgeUrl($writeUrl)) {
        throw new RuntimeException('Write URL scheme is not allowed.');
    }

    $redirectUrl = $writeUrl . '?draft_token=' . rawurlencode($draftToken);
    header('Location: ' . $redirectUrl, true, 302);
    exit;
} catch (Throwable $e) {
    if ($debugEnabled) {
        renderBridgeDebug($e, [
            'post_id' => $post_id,
            'customer_id' => $customer_id,
            'is_admin' => $is_admin ? 'Y' : 'N',
            'uixlab_api_base' => defined('UIXLAB_API_BASE') ? UIXLAB_API_BASE : '',
            'uixlab_draft_create_path' => defined('UIXLAB_DRAFT_CREATE_PATH') ? UIXLAB_DRAFT_CREATE_PATH : '',
            'uixlab_draft_write_path' => defined('UIXLAB_DRAFT_WRITE_PATH') ? UIXLAB_DRAFT_WRITE_PATH : '',
            'payload_summary' => [
                'title_len' => strlen($title ?? ''),
                'content_len' => strlen($content ?? ''),
                'content_html_len' => strlen($raw_html ?? ''),
                'site_code' => isset($bridgePayload) && is_array($bridgePayload)
                    ? (string)($bridgePayload['site_code'] ?? '')
                    : (is_array($member ?? null) ? (string)($member['site_code'] ?? '') : ''),
                'source_post_id' => $bridgePayload['source_post_id'] ?? '',
                'source_customer_id' => $bridgePayload['source_customer_id'] ?? '',
                'source_domain' => $bridgePayload['source_domain'] ?? '',
            ],
        ]);
    }

    failAndRedirect('사이트 게재 준비 중 오류가 발생했습니다.');
}
