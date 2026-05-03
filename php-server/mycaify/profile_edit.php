<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();
$memberPk = (int)($member['id'] ?? 0);

if ($memberPk <= 0) {
    header('Location: /login.php');
    exit;
}

$error = '';

function swal(string $title, string $text, string $icon, string $redirectUrl = ''): void
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
    echo 'function go(){ if (redirect) window.location.href = redirect; }';
    echo 'if (typeof Swal !== "undefined") {';
    echo '  Swal.fire({title:title,text:text,icon:icon,confirmButtonText:"확인"}).then(go);';
    echo '} else {';
    echo '  alert(title + "\\n\\n" + text); go();';
    echo '}';
    echo '</script></body></html>';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT member_id, company_name, member_name, phone, publish_align, publish_font FROM caify_member WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $memberPk]);
$row = $stmt->fetch();

if (!is_array($row)) {
    header('Location: /logout.php');
    exit;
}

$company_name  = (string)($row['company_name']  ?? '');
$member_name   = (string)($row['member_name']   ?? '');
$phone         = (string)($row['phone']         ?? '');
$member_id     = (string)($row['member_id']     ?? '');
$publish_align = (string)($row['publish_align'] ?? 'left');
$publish_font  = (string)($row['publish_font']  ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name  = trim((string)($_POST['company_name'] ?? ''));
    $member_name   = trim((string)($_POST['member_name']  ?? ''));
    $phone         = trim((string)($_POST['phone']        ?? ''));
    $new_pass      = (string)($_POST['new_pass']  ?? '');
    $new_pass2     = (string)($_POST['new_pass2'] ?? '');
    $publish_align = in_array($_POST['publish_align'] ?? '', ['left', 'center', 'right'], true)
                     ? (string)$_POST['publish_align'] : 'left';
    $publish_font  = (string)($_POST['publish_font'] ?? '');

    if ($company_name === '' || $member_name === '' || $phone === '') {
        $error = '회사명/이름/연락처는 필수입니다.';
    } elseif ($new_pass !== '' || $new_pass2 !== '') {
        if ($new_pass === '' || $new_pass2 === '') {
            $error = '새 비밀번호와 확인을 모두 입력해주세요.';
        } elseif ($new_pass !== $new_pass2) {
            $error = '새 비밀번호가 일치하지 않습니다.';
        } elseif (strlen($new_pass) < 5) {
            $error = '새 비밀번호는 최소 5자 이상 입력해주세요.';
        }
    }

    if ($error !== '') {
        swal('수정 오류', $error, 'error', 'profile_edit.php');
    }

    try {
        if ($new_pass !== '') {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $upd = $pdo->prepare(
                'UPDATE caify_member
                    SET company_name = :company_name,
                        member_name  = :member_name,
                        phone        = :phone,
                        passwd       = :passwd,
                        publish_align = :publish_align,
                        publish_font  = :publish_font
                  WHERE id = :id'
            );
            $upd->execute([
                ':company_name'  => $company_name,
                ':member_name'   => $member_name,
                ':phone'         => $phone,
                ':passwd'        => $hash,
                ':publish_align' => $publish_align,
                ':publish_font'  => $publish_font,
                ':id'            => $memberPk,
            ]);
        } else {
            $upd = $pdo->prepare(
                'UPDATE caify_member
                    SET company_name  = :company_name,
                        member_name   = :member_name,
                        phone         = :phone,
                        publish_align = :publish_align,
                        publish_font  = :publish_font
                  WHERE id = :id'
            );
            $upd->execute([
                ':company_name'  => $company_name,
                ':member_name'   => $member_name,
                ':phone'         => $phone,
                ':publish_align' => $publish_align,
                ':publish_font'  => $publish_font,
                ':id'            => $memberPk,
            ]);
        }

        swal('수정 완료', '개인정보가 저장되었습니다.', 'success', 'profile_edit.php');
    } catch (Throwable $e) {
        swal('오류', '저장 중 오류가 발생했습니다. DB를 확인해주세요.', 'error', 'profile_edit.php');
    }
}
// 현재 페이지 체크해서 css 로드 
$currentPage = 'bg_page';
?>
<?php include '../header.inc.php'; ?>
    <main class="main">
        <div class="container">
            <?php include '../inc/snb.inc.php'; ?>
            <div class="content__wrap">
                <div class="content__inner">
                    <div class="content__header">
                        <h2 class="content__header__title">개인정보 수정</h2>
                    </div>
                    <div class="content">
                        <form method="post" action="profile_edit.php" autocomplete="off">
                            <table class="table--prompt">
                                <tr>
                                    <th>회사명<span class="required--blue">*</span></th>
                                    <td><input type="text" class="input--text" name="company_name" value="<?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?>" required></td>
                                </tr>
                                <tr>
                                    <th>이름<span class="required--blue">*</span></th>
                                    <td><input type="text" class="input--text" name="member_name" value="<?= htmlspecialchars($member_name, ENT_QUOTES, 'UTF-8') ?>" required></td>
                                </tr>
                                <tr>
                                    <th>연락처<span class="required--blue">*</span></th>
                                    <td><input type="text" class="input--text" name="phone" value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>" required></td>
                                </tr>
                                <tr>
                                    <th>아이디(이메일)</th>
                                    <td><input type="text" class="input--text" value="<?= htmlspecialchars($member_id, ENT_QUOTES, 'UTF-8') ?>" readonly></td>
                                </tr>
                                <tr>
                                    <th>새 비밀번호</th>
                                    <td>
                                        <input type="password" class="input--text" name="new_pass" placeholder="변경 시에만 입력해주세요.">
                                        <p class="text--small mgT10">비밀번호는 최소 5자 이상</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>새 비밀번호 확인</th>
                                    <td><input type="password" class="input--text" name="new_pass2" placeholder="변경 시에만 입력해주세요."></td>
                                </tr>
                                <tr>
                                    <th>발행 본문 정렬</th>
                                    <td>
                                        <select name="publish_align" class="input--text" style="width:auto;">
                                            <option value="left"   <?= $publish_align === 'left'   ? 'selected' : '' ?>>☰ 왼쪽</option>
                                            <option value="center" <?= $publish_align === 'center' ? 'selected' : '' ?>>≡ 가운데</option>
                                            <option value="right"  <?= $publish_align === 'right'  ? 'selected' : '' ?>>☱ 오른쪽</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th>발행 본문 폰트</th>
                                    <td>
                                        <select name="publish_font" class="input--text" style="width:auto;">
                                            <?php
                                            $fonts = [
                                                ''                                                          => '기본',
                                                "NanumGothic, '나눔고딕', sans-serif"                       => '나눔고딕',
                                                "NanumMyeongjo, '나눔명조', serif"                          => '나눔명조',
                                                "NanumBarunGothic, '나눔바른고딕', sans-serif"              => '나눔바른고딕',
                                                "'Malgun Gothic', 'Apple SD Gothic Neo', sans-serif"        => '맑은고딕',
                                                "dotum, '돋움', sans-serif"                                 => '돋움',
                                                "gulim, '굴림', sans-serif"                                 => '굴림',
                                                "batang, '바탕', serif"                                     => '바탕',
                                                "gungsuh, '궁서', serif"                                    => '궁서',
                                                'Arial, sans-serif'                                         => 'Arial',
                                                'Verdana, sans-serif'                                       => 'Verdana',
                                                'D2Coding, monospace'                                       => 'D2Coding',
                                            ];
                                            foreach ($fonts as $fv => $fl):
                                            ?>
                                            <option value="<?= htmlspecialchars($fv, ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $publish_font === $fv ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($fl, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="text--small mgT10">네이버 블로그 발행 시 본문에 적용되는 폰트입니다.</p>
                                    </td>
                                </tr>
                            </table>

                            <div class="button__wrap">
                                <? // UIXLAB에 계정정보 전달 버튼 임시 비활성화 ?>
                                <!--<a href="../output/output_publish_account.php" class="btn"> UIXLAB에 계정정보 전달</a>-->
                                <button type="submit" class="btn btn--primary">개인정보 수정</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
<?php include '../footer.inc.php'; ?>

