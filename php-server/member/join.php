<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';

$error = '';
$values = [
    'company_name' => '',
    'member_name' => '',
    'phone' => '',
    'member_id' => '',
    'agree_terms' => '',
    'agree_privacy' => '',
];

function swal_and_redirect(string $title, string $text, string $icon, string $redirectUrl): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $i = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $r = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>알림</title></head><body>';
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script>';
    echo 'var title="' . $t . '";';
    echo 'var text="' . $m . '";';
    echo 'var icon="' . $i . '";';
    echo 'var redirect="' . $r . '";';
    echo 'function go(){ window.location.href = redirect; }';
    echo 'if (typeof Swal !== "undefined") {';
    echo '  Swal.fire({title:title,text:text,icon:icon,confirmButtonText:"확인"}).then(go);';
    echo '} else {';
    echo '  alert(title + "\\n\\n" + text); go();';
    echo '}';
    echo '</script></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['company_name'] = trim((string)($_POST['company_name'] ?? ''));
    $values['member_name'] = trim((string)($_POST['member_name'] ?? ''));
    $values['phone'] = trim((string)($_POST['phone'] ?? ''));
    $values['member_id'] = trim((string)($_POST['member_id'] ?? ''));
    $passwd = (string)($_POST['passwd'] ?? '');
    $passwd2 = (string)($_POST['passwd2'] ?? '');
    $values['agree_terms'] = !empty($_POST['agree_terms']) ? '1' : '';
    $values['agree_privacy'] = !empty($_POST['agree_privacy']) ? '1' : '';

    if ($values['company_name'] === '' || $values['member_name'] === '' || $values['phone'] === '' || $values['member_id'] === '') {
        $error = '필수 항목을 모두 입력해주세요.';
    } elseif (!filter_var($values['member_id'], FILTER_VALIDATE_EMAIL)) {
        $error = '아이디(이메일) 형식이 올바르지 않습니다.';
    } elseif ($passwd === '' || $passwd2 === '') {
        $error = '비밀번호를 입력해주세요.';
    } elseif ($passwd !== $passwd2) {
        $error = '비밀번호가 일치하지 않습니다.';
    } elseif (strlen($passwd) < 5) {
        $error = '비밀번호는 최소 5자 이상 입력해주세요.';
    } elseif ($values['agree_terms'] !== '1' || $values['agree_privacy'] !== '1') {
        $error = '필수 약관에 동의해주세요.';
    } else {
        try {
            $pdo = db();
            $chk = $pdo->prepare('SELECT id FROM caify_member WHERE member_id = :member_id LIMIT 1');
            $chk->execute([':member_id' => $values['member_id']]);
            $exists = $chk->fetch();

            if (is_array($exists) && !empty($exists['id'])) {
                $error = '이미 사용 중인 아이디(이메일)입니다.';
            } else {
                $hash = password_hash($passwd, PASSWORD_DEFAULT);
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

                $ins = $pdo->prepare(
                    'INSERT INTO caify_member (member_id, passwd, company_name, member_name, phone, created_date, ip)
                     VALUES (:member_id, :passwd, :company_name, :member_name, :phone, NOW(), :ip)'
                );
                $ins->execute([
                    ':member_id' => $values['member_id'],
                    ':passwd' => $hash,
                    ':company_name' => $values['company_name'],
                    ':member_name' => $values['member_name'],
                    ':phone' => $values['phone'],
                    ':ip' => $ip,
                ]);

                swal_and_redirect('회원가입 완료', '로그인 페이지로 이동합니다.', 'success', './login.php');
            }
        } catch (Throwable $e) {
            $error = '회원가입 처리 중 오류가 발생했습니다. DB/테이블을 확인해주세요.';
        }
    }
}
$currentPage = 'join';
?>
<?php include '../header.inc.php'; ?>
    <main class="member__join main">
        <div class="member__inner">
            <div class="member__login__content">
                <h2 class="member__join__title">회원가입</h2>
                <div class="member__join__title__desc"><span class="required--blue">*</span>필수 입력 항목</div>

                <form method="post" action="join.php" autocomplete="off">
                    <table class="table--join">
                        <colgroup>
                            <col width="30%">
                            <col width="70%">
                        </colgroup>
                        <tr>
                            <th>회사명<span class="required--blue">*</span></th>
                            <td><input type="text" class="input--text" name="company_name" placeholder="회사명을 입력해주세요." value="<?= htmlspecialchars($values['company_name'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                        </tr>
                        <tr>
                            <th>이름<span class="required--blue">*</span></th>
                            <td><input type="text" class="input--text" name="member_name" placeholder="이름을 입력해주세요." value="<?= htmlspecialchars($values['member_name'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                        </tr>
                        <tr>
                            <th>연락처<span class="required--blue">*</span></th>
                            <td><input type="text" class="input--text" name="phone" placeholder="연락처를 입력해주세요." value="<?= htmlspecialchars($values['phone'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                        </tr>
                        <tr>
                            <th>아이디(이메일)<span class="required--blue">*</span></th>
                            <td><input type="email" class="input--text" name="member_id" placeholder="아이디(이메일)을 입력해주세요." value="<?= htmlspecialchars($values['member_id'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                        </tr>
                        <tr>
                            <th>비밀번호<span class="required--blue">*</span></th>
                            <td><input type="password" class="input--text" name="passwd" placeholder="비밀번호를 입력해주세요." required></td>
                        </tr>
                        <tr>
                            <th>비밀번호 확인<span class="required--blue">*</span></th>
                            <td><input type="password" class="input--text" name="passwd2" placeholder="비밀번호를 다시 입력해주세요." required></td>
                        </tr>
                    </table>

                    <div class="member__join__agree">
                        <label for="all">
                            <input type="checkbox" id="all">
                            <span>전체 동의합니다.</span>
                        </label>
                        <p>
                            전체동의는 필수 및 선택정보에 대한 동의도 포함되어 있으며, 개별적으로도 동의를 선택하실 수 있습니다.<br>
                            선택항목에 대한 동의를 거부하는 경우에도 회원가입 서비스는 이용 가능합니다.
                        </p>
                    </div>

                    <div class="terms__item lcp-accordion">
                        <div class="terms__header lcp-accordion__toggle">
                            <input class="terms__checkbox" type="checkbox" value="1" id="agree_terms" name="agree_terms" <?= $values['agree_terms'] === '1' ? 'checked' : '' ?>>
                            <label class="terms__label mgL5" for="agree_terms">이용약관 <span class="text--required">[필수]</span></label>
                            <p class="lcp-accordion__icon">내용보기</p>
                        </div>
                        <div class="terms__content lcp-accordion__content">
                            <div class="scroll__terms"><?php include 'policyrules.inc.php'; ?></div>
                        </div>
                    </div>

                    <div class="terms__item lcp-accordion">
                        <div class="terms__header lcp-accordion__toggle">
                            <input class="terms__checkbox" type="checkbox" value="1" id="agree_privacy" name="agree_privacy" <?= $values['agree_privacy'] === '1' ? 'checked' : '' ?>>
                            <label class="terms__label mgL5" for="agree_privacy">개인정보 수집 및 이용 동의 <span class="text--required">[필수]</span></label>
                            <p class="lcp-accordion__icon">내용보기</p>
                        </div>
                        <div class="terms__content lcp-accordion__content">
                            <div class="scroll__terms"><?php include 'privacy.inc.php'; ?>  </div>
                        </div>
                    </div>
                    <div class="terms__item lcp-accordion">
                        <div class="terms__header lcp-accordion__toggle">
                            <input class="terms__checkbox" type="checkbox" value="1" id="marketing_reception" name="marketing_reception" <?= $values['marketing_reception'] === '1' ? 'checked' : '' ?>>
                            <label class="terms__label mgL5" for="marketing_reception">마케팅 정보 수신 및 홍보 활용 동의 <span class="text--required">[필수]</span></label>
                            <p class="lcp-accordion__icon">내용보기</p>
                        </div>
                        <div class="terms__content lcp-accordion__content">
                            <div class="scroll__terms"><?php include 'marketing_reception.inc.php'; ?>    </div>
                        </div>
                    </div>
                    <div class="terms__item lcp-accordion">
                        <div class="terms__header lcp-accordion__toggle">
                            <input class="terms__checkbox" type="checkbox" value="1" id="policyrefund" name="policyrefund" <?= $values['policyrefund'] === '1' ? 'checked' : '' ?>>
                            <label class="terms__label mgL5" for="policyrefund">환불 및 청약철회 안내 <span class="text--required">[필수]</span></label>
                            <p class="lcp-accordion__icon">내용보기</p>
                        </div>
                        <div class="terms__content lcp-accordion__content">
                            <div class="scroll__terms"><?php include 'policyrefund.inc.php'; ?>    </div>
                        </div>
                    </div>

                    <div class="button__wrap">
                        <a href="/" class="btn">취소</a>
                        <button type="submit" class="btn btn--primary">회원가입</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="../assets/js/custom.js"></script>
    <script>
        (function () {
            var all = document.getElementById('all');
            var t = document.getElementById('agree_terms');
            var p = document.getElementById('agree_privacy');
            var f = document.getElementById('policyrefund');
            var m = document.getElementById('marketing_reception');

            if (!all || !t || !p || !f || !m) return;

            function syncAll() {
                all.checked = (t.checked && p.checked);
            }

            all.addEventListener('change', function () {
                t.checked = all.checked;
                p.checked = all.checked;
                f.checked = all.checked;
                m.checked = all.checked;
            });

            t.addEventListener('change', syncAll);
            p.addEventListener('change', syncAll);
            f.addEventListener('change', syncAll);
            m.addEventListener('change', syncAll);
            syncAll();
        })();
    </script>

    <?php if ($error !== ''): ?>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
            (function () {
                var msg = <?=
                    json_encode('' . $error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ?>;
                if (typeof Swal !== "undefined") {
                    Swal.fire({
                        icon: "error",
                        title: "회원가입 오류",
                        text: msg,
                        confirmButtonText: "확인"
                    });
                } else {
                    alert("회원가입 오류\n\n" + msg);
                }
            })();
        </script>
    <?php endif; ?>

    </body>
    </html>
