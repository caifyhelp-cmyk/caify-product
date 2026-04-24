<?php
declare(strict_types=1);

session_start();

require "../inc/db.php";	

// 이미 로그인 상태면 원하는 페이지로 이동
if (!empty($_SESSION['member']['id'])) {
    header('Location: ../prompt/prompt_list.php');
    exit;
}

$error = '';
$memberIdPrefill = '';

// 아이디 저장 쿠키
if (!empty($_COOKIE['remember_member_id']) && is_string($_COOKIE['remember_member_id'])) {
    $memberIdPrefill = $_COOKIE['remember_member_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = trim((string)($_POST['member_id'] ?? ''));
    $passwd = (string)($_POST['passwd'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($member_id === '' || $passwd === '') {
        $error = '아이디와 비밀번호를 입력해주세요.';
    } else {
        try {
            $stmt = db()->prepare('SELECT id, member_id, passwd FROM caify_member WHERE member_id = :member_id LIMIT 1');
            $stmt->execute([':member_id' => $member_id]);
            $row = $stmt->fetch();

            $ok = false;
            if (is_array($row)) {
                $stored = (string)($row['passwd'] ?? '');
                $info = password_get_info($stored);

                if (!empty($info['algo'])) {
                    // 해시로 저장된 경우(권장)
                    $ok = password_verify($passwd, $stored);
                } else {
                    // 평문으로 저장된 경우(레거시 호환)
                    $ok = hash_equals($stored, $passwd);
                }
            }

            if ($ok && is_array($row)) {
                session_regenerate_id(true);
                $_SESSION['member'] = [
                    'id' => (int)$row['id'],
                    'member_id' => (string)$row['member_id'],
                ];

                if ($remember) {
                    setcookie('remember_member_id', $member_id, [
                        'expires' => time() + (60 * 60 * 24 * 30),
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                        'secure' => !empty($_SERVER['HTTPS']),
                    ]);
                } else {
                    setcookie('remember_member_id', '', [
                        'expires' => time() - 3600,
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                        'secure' => !empty($_SERVER['HTTPS']),
                    ]);
                }

                header('Location: ../prompt/prompt.php');
                exit;
            }

            $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
            $memberIdPrefill = $member_id;
        } catch (Throwable $e) {
            $error = '로그인 처리 중 오류가 발생했습니다. DB 설정을 확인해주세요.';
        }
    }
}
$currentPage = 'login';
?>
<?php include '../header.inc.php'; ?> 
    <main class="member__login main">
        <div class="member__login__inner">
            <div class="member__login__content">
                <div class="member__login__text">
                    <img src="../images/login_text.png" alt="카이파이">
                </div>
                <div class="member__login__form">
                    <h2 class="member__login__form__title">로그인</h2>
                    <?php if ($error !== ''): ?>
                        <p class="mgT10 text--center" style="color:#d32f2f; font-weight:600;">
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>

                    <form method="post" action="login.php" autocomplete="off">
                        <fieldset>
                            <input
                                type="text"
                                class="input--text"
                                name="member_id"
                                placeholder="아이디"
                                value="<?= htmlspecialchars($memberIdPrefill, ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                            <input
                                type="password"
                                class="input--text mgT10"
                                name="passwd"
                                placeholder="비밀번호"
                                required
                            >
                            <div class="checkbox__wrap">
                                <label for="remember" class="checkbox__id">
                                    <input type="checkbox" id="remember" name="remember" value="1" <?= $memberIdPrefill !== '' ? 'checked' : '' ?>>
                                    <span>아이디 저장</span>
                                </label>
                                <span class="btn__find"><span class="color--primary"> 아이디/비밀번호</span> 찾기</span>
                            </div>
                            <button class="btn btn--primary" type="submit">로그인</button>
                        </fieldset>
                    </form>

                    <p class="mgT20 text--center">아직 회원이 아니신가요? <a href="join.php" class="btn__join color--primary">회원가입</a></p>
                </div>
            </div>
        </div>
    </main>
    <?php include '../footer.inc.php'; ?> 
</body>
</html>

