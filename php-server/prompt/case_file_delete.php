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

$fileId = (int)($_POST['file_id'] ?? 0);
$caseId = (int)($_POST['case_id'] ?? 0);

if ($fileId <= 0) {
    header('Location: case_list.php');
    exit;
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT f.id, f.stored_path, f.case_id
       FROM caify_case_file f
       JOIN caify_case c ON c.id = f.case_id
      WHERE f.id = :file_id AND f.member_pk = :member_pk AND c.member_pk = :member_pk2
      LIMIT 1'
);
$stmt->execute([
    ':file_id'    => $fileId,
    ':member_pk'  => $memberPk,
    ':member_pk2' => $memberPk,
]);
$file = $stmt->fetch();

if (!is_array($file) || empty($file['id'])) {
    header('Location: case_list.php');
    exit;
}

$actualCaseId = (int)$file['case_id'];

// DB에서 삭제
$del = $pdo->prepare('DELETE FROM caify_case_file WHERE id = :id AND member_pk = :member_pk');
$del->execute([':id' => $fileId, ':member_pk' => $memberPk]);

// 실제 파일 삭제
$storedPath = (string)($file['stored_path'] ?? '');
if ($storedPath !== '') {
    $absPath = realpath(__DIR__ . '/../' . $storedPath);
    if ($absPath !== false && is_file($absPath)) {
        @unlink($absPath);
    }
}

header('Location: case.php?id=' . $actualCaseId . '&msg=deleted');
exit;
