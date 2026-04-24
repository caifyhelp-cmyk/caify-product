<?php
declare(strict_types=1);

/**
 * 로그인 가드/유틸
 */

function require_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['member']['id'])) {
        header('Location: /member/login.php');
        exit;
    }
}

function current_member(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $member = $_SESSION['member'] ?? null;
    if (!is_array($member)) {
        return [];
    }

    return $member;
}

function safe_member_folder(string $memberId, int $memberPk): string
{
    $folder = preg_replace('/[^a-zA-Z0-9_-]/', '_', $memberId);
    $folder = trim((string)$folder);

    if ($folder === '') {
        $folder = 'member_' . $memberPk;
    }

    return $folder;
}

