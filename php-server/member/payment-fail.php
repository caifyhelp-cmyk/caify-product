<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$errorCode    = trim((string)($_GET['code']    ?? ''));
$errorMessage = trim((string)($_GET['message'] ?? ''));

if ($errorMessage === '') {
    $errorMessage = '결제 처리 중 오류가 발생했습니다.';
}

$currentPage = 'payment';
include '../header.inc.php';
?>
    <main class="main">
        <div class="main__container">
            <section class="section payment payment--complete">
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
                            <h2 class="title-main">결제 실패</h2>
                        </div>
                        <div class="payment__contents-card">
                            <style>
                                .pay-fail { text-align:center; padding:48px 0 32px; }
                                .pay-fail__icon { width:72px; height:72px; margin:0 auto 20px; background:#ffebee; border-radius:50%; display:flex; align-items:center; justify-content:center; }
                                .pay-fail__icon svg { width:32px; height:32px; }
                                .pay-fail__heading { font-size:22px; font-weight:800; color:#111; margin:0; letter-spacing:-.02em; }
                                .pay-fail__card { margin:32px 0 0; background:#fffbfb; border:1px solid #ffcdd2; border-radius:16px; padding:28px 32px; text-align:left; }
                                .pay-fail__row { display:flex; justify-content:space-between; align-items:flex-start; padding:10px 0; }
                                .pay-fail__row + .pay-fail__row { border-top:1px solid #fce4ec; }
                                .pay-fail__row-label { font-size:14px; color:#888; font-weight:500; white-space:nowrap; flex-shrink:0; margin-right:16px; }
                                .pay-fail__row-value { font-size:14px; color:#333; font-weight:600; text-align:right; word-break:keep-all; }
                                .pay-fail__row-value--error { color:#c62828; }
                                .pay-fail__note { text-align:center; color:#aaa; font-size:13px; margin:24px 0 0; }
                                .pay-fail__actions { display:flex; gap:12px; margin-top:32px; }
                                .pay-fail__actions .btn-caify { flex:1; text-align:center; }
                            </style>

                            <div class="pay-fail">
                                <div class="pay-fail__icon">
                                    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M8 8l16 16M24 8L8 24" stroke="#c62828" stroke-width="3.5" stroke-linecap="round"/>
                                    </svg>
                                </div>
                                <p class="pay-fail__heading">결제에 실패하였습니다</p>
                            </div>

                            <div class="pay-fail__card">
                                <?php if ($errorCode !== ''): ?>
                                <div class="pay-fail__row">
                                    <span class="pay-fail__row-label">오류 코드</span>
                                    <span class="pay-fail__row-value"><?= htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="pay-fail__row">
                                    <span class="pay-fail__row-label">오류 내용</span>
                                    <span class="pay-fail__row-value pay-fail__row-value--error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>

                            <p class="pay-fail__note">문제가 지속될 경우 고객센터로 문의해 주세요.</p>

                            <div class="pay-fail__actions">
                                <a href="/payment/payment.php" class="btn-caify">다시 결제하기</a>
                                <a href="/index.php" class="btn-caify">홈으로</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
<?php include '../footer.inc.php'; ?>
