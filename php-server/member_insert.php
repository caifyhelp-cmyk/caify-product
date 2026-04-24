<?php
declare(strict_types=1);

require "./inc/db.php";	

$error = '';
$success = '';
$memberId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = trim((string)($_POST['member_id'] ?? ''));

    if ($memberId === '') {
        $error = '아이디(member_id)를 입력해주세요.';
    } else {
        try {
            // 중복 체크 (테이블에 UNIQUE가 없어도 동작하도록)
            $check = db()->prepare('SELECT id FROM caify_member WHERE member_id = :member_id LIMIT 1');
            $check->execute([':member_id' => $memberId]);
            $exists = $check->fetch();

            if (is_array($exists) && !empty($exists['id'])) {
                $error = '이미 존재하는 아이디입니다.';
            } else {
                $hashed = password_hash('12345', PASSWORD_DEFAULT);
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

                $stmt = db()->prepare(
                    'INSERT INTO caify_member (member_id, passwd, created_date, ip)
                     VALUES (:member_id, :passwd, NOW(), :ip)'
                );
                $stmt->execute([
                    ':member_id' => $memberId,
                    ':passwd' => $hashed,
                    ':ip' => $ip,
                ]);

                $success = '회원 생성 완료! 비밀번호는 12345 입니다.';
            }
        } catch (Throwable $e) {
            $error = '회원 생성 중 오류가 발생했습니다. DB 설정/테이블을 확인해주세요.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:type" content="website">
    <meta property="og:title" content="카이파이">
    <meta property="og:description" content="카이파이">
    <meta property="og:image" content="images/logo.png">
    <meta property="og:url" content="https://www.caify.ai">
    <meta name="Keywords" content="">
    <title>회원 생성</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <main class="member__login">
        <div class="member__login__inner">
            <div class="member__login__content">
                <div class="member__login__form" style="width: 420px; margin: 0 auto;">
                    <h2 class="member__login__form__title">회원 생성</h2>

                    <p class="mgT10 text--center" style="color:#666;">
                        비밀번호는 <b>12345</b>로 자동 설정됩니다. (해시로 저장)
                    </p>

                    <?php if ($error !== ''): ?>
                        <p class="mgT10 text--center" style="color:#d32f2f; font-weight:600;">
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <p class="mgT10 text--center" style="color:#2e7d32; font-weight:700;">
                            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <p class="mgT10 text--center">
                            생성된 아이디: <b><?= htmlspecialchars($memberId, ENT_QUOTES, 'UTF-8') ?></b>
                        </p>
                    <?php endif; ?>

                    <form method="post" action="member_insert.php" autocomplete="off">
                        <fieldset>
                            <input
                                type="text"
                                class="input--text"
                                name="member_id"
                                placeholder="아이디(member_id)"
                                value="<?= htmlspecialchars($memberId, ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                            <button class="btn btn--primary mgT10" type="submit">회원 생성</button>
                            <a class="btn mgT10" href="login.php">로그인 페이지로</a>
                        </fieldset>
                    </form>
                </div>
            </div>
        </div>
    </main>
</body>

</html>

