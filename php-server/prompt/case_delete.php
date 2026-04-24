<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();
$memberPk = (int)($member['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: case_list.php');
    exit;
}

$caseId = (int)($_POST['case_id'] ?? 0);

if ($caseId <= 0) {
    header('Location: case_list.php');
    exit;
}

$pdo = db();

// 소유 확인 후 소프트 삭제
$stmt = $pdo->prepare('UPDATE caify_case SET status = 0 WHERE id = :id AND member_pk = :member_pk');
$stmt->execute([':id' => $caseId, ':member_pk' => $memberPk]);

header('Location: case_list.php');
exit;
