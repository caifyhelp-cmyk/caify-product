<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member      = current_member();
$memberPk    = (int)($member['id'] ?? 0);
$memberEmail = (string)($member['member_id'] ?? '');

$memberName = '';
$isSubscribed = false;
$subscriptionInfo = [];

if ($memberPk > 0) {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT member_name FROM caify_member WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $memberPk]);
        $row = $stmt->fetch();
        $memberName = (string)($row['member_name'] ?? '');

        $subStmt = $pdo->prepare(
            "SELECT order_name, payment_method, payment_status, amount, customer_email, paid_at
               FROM caify_subscription
              WHERE member_pk = :member_pk AND payment_status = '결제완료'
              ORDER BY paid_at DESC LIMIT 1"
        );
        $subStmt->execute([':member_pk' => $memberPk]);
        $subRow = $subStmt->fetch();
        if (is_array($subRow) && !empty($subRow['paid_at'])) {
            $isSubscribed = true;
            $subscriptionInfo = $subRow;
        }
    } catch (\Throwable $e) { }
}

$emailParts  = explode('@', $memberEmail, 2);
$emailUser   = $emailParts[0] ?? '';
$emailDomain = $emailParts[1] ?? '';

$customerKey = 'caify_member_' . $memberPk;

$currentPage = 'payment';
include '../header.inc.php';
?>

<?php if (!$isSubscribed): ?>
<script src="https://js.tosspayments.com/v2/standard"></script>
<?php endif; ?>

    <main class="main">
        <div class="main__container">
            <section class="section payment payment--checkout">
                <div class="payment__aside">
                    <div class="payment__aside-img">
                        <img src="../images/common/caify_logo_glass_01.png" alt="caify_glass_logo_visual" class="caify-glass--visual">
                        <img src="../images/common/caify_logo_glass_00.png" alt="caify_glass_logo_text" class="caify-glass--text">
                    </div>
                </div>
                <div class="payment__contents">
                    <div class="payment__contents-inner">
                        <div class="payment__contents-title">
                            <img class="caify-logo" src="../images/common/caify_logo.png" alt="caify_logo">
                            <h2 class="title-main">서비스 구독하기</h2>
                        </div>

                        <div class="payment__contents-card">
                            <?php if ($isSubscribed): ?>
                            <!-- 구독 중 -->
                            <style>
                                .sub-active { text-align:center; padding:48px 0 32px; }
                                .sub-active__icon { width:72px; height:72px; margin:0 auto 20px; background:#e8f5e9; border-radius:50%; display:flex; align-items:center; justify-content:center; }
                                .sub-active__icon svg { width:36px; height:36px; }
                                .sub-active__heading { font-size:22px; font-weight:800; color:#111; margin:0; letter-spacing:-.02em; }
                                .sub-active__badge { display:inline-block; margin-top:12px; padding:6px 18px; background:#e8f5e9; color:#2e7d32; font-size:13px; font-weight:700; border-radius:20px; letter-spacing:.01em; }
                                .sub-active__card { margin:32px 0 0; background:#f8fafb; border:1px solid #e9ecef; border-radius:16px; padding:28px 32px; text-align:left; }
                                .sub-active__plan { display:flex; align-items:center; justify-content:space-between; padding-bottom:20px; margin-bottom:20px; border-bottom:1px solid #e9ecef; }
                                .sub-active__plan-name { font-size:17px; font-weight:700; color:#111; margin:0; }
                                .sub-active__plan-price { font-size:20px; font-weight:800; color:#111; margin:0; }
                                .sub-active__rows { list-style:none; margin:0; padding:0; }
                                .sub-active__row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; }
                                .sub-active__row + .sub-active__row { border-top:1px solid #f0f0f0; }
                                .sub-active__row-label { font-size:14px; color:#888; font-weight:500; }
                                .sub-active__row-value { font-size:14px; color:#333; font-weight:600; }
                                .sub-active__note { text-align:center; color:#aaa; font-size:13px; margin:24px 0 0; }
                                .sub-active__actions { display:flex; gap:12px; margin-top:32px; }
                                .sub-active__actions .btn-caify { flex:1; text-align:center; }
                            </style>
                            <div class="sub-active">
                                <div class="sub-active__icon">
                                    <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M8 19l7 7 13-14" stroke="#2e7d32" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <p class="sub-active__heading">현재 구독중입니다</p>
                                <span class="sub-active__badge">구독 활성화</span>
                            </div>
                            <div class="sub-active__card">
                                <div class="sub-active__plan">
                                    <p class="sub-active__plan-name"><?= htmlspecialchars((string)($subscriptionInfo['order_name'] ?? '블로그 자동화'), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="sub-active__plan-price"><?= number_format((int)($subscriptionInfo['amount'] ?? 0)) ?>원</p>
                                </div>
                                <ul class="sub-active__rows">
                                    <li class="sub-active__row">
                                        <span class="sub-active__row-label">결제수단</span>
                                        <span class="sub-active__row-value"><?= htmlspecialchars((string)($subscriptionInfo['payment_method'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                    <li class="sub-active__row">
                                        <span class="sub-active__row-label">결제상태</span>
                                        <span class="sub-active__row-value" style="color:#2e7d32;"><?= htmlspecialchars((string)($subscriptionInfo['payment_status'] ?? '결제완료'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                    <?php if (!empty($subscriptionInfo['customer_email'])): ?>
                                    <li class="sub-active__row">
                                        <span class="sub-active__row-label">청구서 이메일</span>
                                        <span class="sub-active__row-value"><?= htmlspecialchars((string)$subscriptionInfo['customer_email'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                    <?php endif; ?>
                                    <li class="sub-active__row">
                                        <span class="sub-active__row-label">결제일</span>
                                        <span class="sub-active__row-value"><?= htmlspecialchars((string)($subscriptionInfo['paid_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                </ul>
                            </div>
                            <p class="sub-active__note">구독 관련 문의는 고객센터로 연락해 주세요.</p>
                            <div class="sub-active__actions">
                                <a href="/index.php" class="btn-caify">홈으로</a>
                                <a href="/prompt/prompt.php" class="btn-caify">서비스 이용하기</a>
                            </div>

                            <?php else: ?>
                            <!-- 구독정보 -->
                            <div class="payment__subscription-order">
                                <h3 class="payment__subscription-title payment__box-wrap payment__box-title">
                                    구독정보</h3>
                                <div class="payment__box payment__subscription-plan">
                                    <strong class="payment__plan-name">블로그 자동화</strong>
                                    <p class="payment__plan-info">고품질 블로그 게시글 60개<br>(게시글 한 개당 텍스트 500자 이상 + 이미지 6장)</p>
                                    <div class="payment__total">
                                        <span class="payment__total-label">총 결제 금액</span>
                                        <div class="payment__total-price-wrap">
                                            <span class="payment__total-note">(1년 약정 / VAT 포함)</span>
                                            <span class="payment__total-price">330,000원</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- // end : 구독정보 -->
                            <!-- 개인정보 제3자 제공 동의 -->
                            <div class="payment__agree">
                                <div class="payment__agree-header payment__box-wrap">
                                    <input class="payment__agree-checkbox" type="checkbox" id="agree_item_2"/>
                                    <label class="payment__agree-label payment__box-title" for="agree_item_2">
                                        개인정보 처리방침<span class="text-required">*</span> (필수)
                                    </label>
                                </div>
                                <div class="payment__box payment__agree-inner">
                                    <?php include '../member/privacy.inc.php'; ?>
                                </div>
                            </div>
                            <!-- // end : 개인정보 제3자 제공 동의 -->
                            <!-- 결제수단 -->
                            <div class="payment__method">
                                <span class="form-title">자동결제 수단
                                    <span class="text-required">*</span>
                                </span>
                                <div class="payment__method-options">
                                    <label class="payment__method-label">
                                        <input class="payment__method-input" type="radio" name="billing_method" value="CARD">
                                        <span class="payment__method-text">카드 자동결제</span>
                                    </label>
                                    <label class="payment__method-label">
                                        <input class="payment__method-input" type="radio" name="billing_method" value="TRANSFER">
                                        <span class="payment__method-text">계좌 자동결제</span>
                                    </label>
                                </div>
                            </div>
                            <!-- // end : 결제수단 -->
                            <!-- 청구서 수신 이메일 -->
                            <div class="email-form">
                                <label for="user_email" class="form-title">청구서 수신 이메일</label>
                                <div class="email-form__big-wrap">
                                    <div class="email-form__input-wrap">
                                        <input class="email-form__input form-input--focus-border" type="text" id="user_email" name="user_email"
                                            placeholder="이메일 주소를 입력해주세요"
                                            value="<?= htmlspecialchars($emailUser, ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="email-form__at">@</span>
                                        <div class="email-form__select-wrap">
                                            <select class="email-form__select-box form-input--focus-border" name="select_email" id="select_email">
                                                <option value="" selected disabled hidden class="email-form__select-option">선택하기</option>
                                                <option value="self_write">직접입력</option>
                                                <option value="naver.com">naver.com</option>
                                                <option value="gmail.com">gmail.com</option>
                                                <option value="daum.net">daum.net</option>
                                                <option value="nate.com">nate.com</option>
                                                <option value="hanmail.net">hanmail.net</option>
                                                <option value="hotmail.com">hotmail.com</option>
                                                <option value="yahoo.com">yahoo.com</option>
                                            </select>
                                            <input type="text" id="domain_input" class="email-form__domain-input form-input--focus-border"
                                                placeholder="도메인 입력"
                                                style="display:none;"
                                                value="">
                                        </div>
                                    </div>
                                    <div class="email-form__notice">
                                        *미 입력시 회원가입 시 입력한 이메일 주소로 청구서가 발송 됩니다.
                                    </div>
                                </div>
                            </div>
                            <!-- // end : 청구서 수신 이메일 -->
                            <!-- 결제하기 -->
                            <div class="payment__submit">
                                <span class="payment__all-agree-text"><span class="text-required">*</span>모든 필수 약관 내용을 확인하였고, 이에 동의합니다.</span>
                                <button type="button" class="btn-caify" onclick="requestBillingAuth()">자동결제 수단 등록하기</button>
                            </div>
                            <!-- // end : 결제하기 -->
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

<?php if (!$isSubscribed): ?>
<script>
(function () {
    var selectEl           = document.getElementById('select_email');
    var domainEl           = document.getElementById('domain_input');
    var emailDomainDefault = '<?= htmlspecialchars($emailDomain, ENT_QUOTES, 'UTF-8') ?>';

    function setDomainOption(val) {
        if (!val) return;
        var found = false;
        for (var i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === val) {
                selectEl.value = val;
                found = true;
                break;
            }
        }
        if (!found) {
            selectEl.value         = 'self_write';
            domainEl.value         = val;
            domainEl.style.display = '';
        }
    }

    if (emailDomainDefault) setDomainOption(emailDomainDefault);

    selectEl.addEventListener('change', function () {
        if (this.value === 'self_write') {
            domainEl.style.display = '';
            domainEl.focus();
        } else {
            domainEl.style.display = 'none';
            domainEl.value = '';
        }
    });
})();

var clientKey    = 'live_ck_ALnQvDd2VJd7dab2EZZa8Mj7X41m';
var customerKey  = '<?= htmlspecialchars($customerKey, ENT_QUOTES, 'UTF-8') ?>';
var customerName = '<?= htmlspecialchars($memberName ?: '회원', ENT_QUOTES, 'UTF-8') ?>';
var customerEmail = '<?= htmlspecialchars($memberEmail, ENT_QUOTES, 'UTF-8') ?>';

var tossPayments = TossPayments(clientKey);
var tossPayment  = tossPayments.payment({ customerKey: customerKey });

function getFullEmail() {
    var user   = document.getElementById('user_email').value.trim();
    var selVal = document.getElementById('select_email').value;
    var domain = (selVal === 'self_write')
        ? document.getElementById('domain_input').value.trim()
        : selVal;
    if (!user || !domain || domain === '') return customerEmail;
    return user + '@' + domain;
}

async function requestBillingAuth() {
    if (!document.getElementById('agree_item_2').checked) {
        alert('개인정보 처리방침에 동의하셔야 결제가 가능합니다.');
        return;
    }

    var selected = document.querySelector('input[name="billing_method"]:checked');
    if (!selected) {
        alert('자동결제 수단을 선택해주세요.');
        return;
    }

    var method = selected.value;
    var email  = getFullEmail();

    try {
        await tossPayment.requestBillingAuth({
            method: method,
            successUrl: window.location.origin
                + '/member/billing-auth-complete.php?billingMethod=' + method + '&',
            failUrl: window.location.origin + '/member/payment-fail.php',
            customerEmail: email,
            customerName: customerName,
        });
    } catch (error) {
        alert(error.message || '자동결제 수단 등록 중 오류가 발생했습니다.');
    }
}
</script>
<?php endif; ?>

<?php include '../footer.inc.php'; ?>
