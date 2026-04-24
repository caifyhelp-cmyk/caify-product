<?php
declare(strict_types=1);

session_start();

require "../inc/db.php";
require "./bridge_uixlab.php";

function failAndRedirect(string $message): never
{
    echo "<script>alert('" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "'); location.href='../mycaify/profile_edit.php';</script>";
    exit;
}

$customer_id = (int)($_SESSION['member']['id'] ?? 0);
if ($customer_id <= 0) {
    header('Location: ../member/login.php');
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT id, member_id, company_name, phone
        FROM caify_member
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $customer_id]);
    $member = $stmt->fetch();

    if (!is_array($member)) {
        failAndRedirect('회원 정보를 찾을 수 없습니다.');
    }

    $sourceMemberId = trim((string)($member['member_id'] ?? ''));
    $companyName = trim((string)($member['company_name'] ?? ''));
    $contactPhone = trim((string)($member['phone'] ?? ''));
    $email = $sourceMemberId; // 현재 CAI 로그인 ID(member_id)를 이메일로 사용

    if ($sourceMemberId === '' || $companyName === '' || $contactPhone === '' || $email === '') {
        failAndRedirect('계정 전달을 위한 필수 정보가 부족합니다. 내 정보에서 회사명/연락처를 먼저 확인해주세요.');
    }

    $bridgePayload = [
        'source_member_id' => $sourceMemberId,
        'company_name' => $companyName,
        'contact_phone' => $contactPhone,
        'email' => $sourceMemberId,
        'source_customer_id' => (int)$member['id'],
        'source_domain' => UIXLAB_SOURCE_DOMAIN,
        // 향후 정책 분기 확장을 위한 기본 스냅샷
        'policy_version' => 'v1',
        'eligibility_allowed' => 1,
        'eligibility_reason_code' => 'OK',
    ];

    $apiResult = callUixlabBridgeApi(UIXLAB_ACCOUNT_DRAFT_CREATE_PATH, $bridgePayload);
    $draftToken = (string)($apiResult['data']['draft_token'] ?? '');
    if ($draftToken === '') {
        throw new RuntimeException('Draft token is missing.');
    }

    $registerUrl = normalizeJoinUrl(UIXLAB_API_BASE, UIXLAB_ACCOUNT_REGISTER_PATH);
    if (!isAllowedBridgeUrl($registerUrl)) {
        throw new RuntimeException('Register URL scheme is not allowed.');
    }

    $redirectUrl = $registerUrl . '?bridge_token=' . rawurlencode($draftToken);
    header('Location: ' . $redirectUrl, true, 302);
    exit;
} catch (Throwable $e) {
    bridgeLog('account_publish_error', [
        'source_customer_id' => $customer_id,
        'message' => $e->getMessage(),
    ]);
    failAndRedirect('계정 전달 준비 중 오류가 발생했습니다.');
}
