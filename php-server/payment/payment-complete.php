<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member    = current_member();
$memberPk  = (int)($member['id'] ?? 0);

$paymentKey = trim((string)($_GET['paymentKey'] ?? ''));
$orderId    = trim((string)($_GET['orderId']    ?? ''));
$amount     = (int)($_GET['amount'] ?? 0);

$success     = false;
$error       = '';
$orderResult = [];

if ($paymentKey === '' || $orderId === '' || $amount <= 0) {
    $error = '잘못된 접근입니다.';
} else {
    $secretKey  = 'live_sk_Z61JOxRQVEzYLQgZEQE0rW0X9bAq';
    $credential = base64_encode($secretKey . ':');

    $curl = curl_init('https://api.tosspayments.com/v1/payments/confirm');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $credential,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'paymentKey' => $paymentKey,
            'orderId'    => $orderId,
            'amount'     => $amount,
        ]),
    ]);

    $response  = curl_exec($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($curl);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($curlErrno) {
        $error = '결제 서버 연결에 실패했습니다. (' . $curlError . ')';
    } elseif ($httpCode === 200) {
        $resp = json_decode((string)$response, true);

        if (!is_array($resp)) {
            $error = '결제 응답을 처리할 수 없습니다.';
        } else {
            $paymentMethod = '기타';
            $paymentStatus = '결제완료';
            if (!empty($resp['card'])) {
                $paymentMethod = '신용카드';
            } elseif (!empty($resp['transfer'])) {
                $paymentMethod = '계좌이체';
            } elseif (!empty($resp['virtualAccount'])) {
                $paymentMethod = '가상계좌';
                $paymentStatus = '결제대기';
            }

            $orderName     = (string)($resp['orderName']     ?? '');
            $customerEmail = (string)($resp['customerEmail'] ?? '');
            $paidAt        = !empty($resp['approvedAt'])
                ? date('Y-m-d H:i:s', strtotime((string)$resp['approvedAt']))
                : date('Y-m-d H:i:s');

            try {
                $pdo = db();

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS caify_subscription (
                        id             INT AUTO_INCREMENT PRIMARY KEY,
                        member_pk      INT NOT NULL,
                        order_id       VARCHAR(100) NOT NULL,
                        payment_key    VARCHAR(200),
                        amount         INT NOT NULL DEFAULT 0,
                        order_name     VARCHAR(200),
                        payment_method VARCHAR(50),
                        payment_status VARCHAR(50) DEFAULT '결제완료',
                        customer_email VARCHAR(200),
                        paid_at        DATETIME,
                        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_order_id (order_id)
                    ) DEFAULT CHARSET=utf8mb4
                ");

                $stmt = $pdo->prepare("
                    INSERT INTO caify_subscription
                        (member_pk, order_id, payment_key, amount, order_name,
                         payment_method, payment_status, customer_email, paid_at)
                    VALUES
                        (:member_pk, :order_id, :payment_key, :amount, :order_name,
                         :payment_method, :payment_status, :customer_email, :paid_at)
                    ON DUPLICATE KEY UPDATE
                        payment_key    = VALUES(payment_key),
                        payment_method = VALUES(payment_method),
                        payment_status = VALUES(payment_status),
                        paid_at        = VALUES(paid_at),
                        updated_at     = NOW()
                ");
                $stmt->execute([
                    ':member_pk'      => $memberPk,
                    ':order_id'       => $orderId,
                    ':payment_key'    => $paymentKey,
                    ':amount'         => $amount,
                    ':order_name'     => $orderName,
                    ':payment_method' => $paymentMethod,
                    ':payment_status' => $paymentStatus,
                    ':customer_email' => $customerEmail,
                    ':paid_at'        => $paidAt,
                ]);

                $success = true;
                $orderResult = [
                    'orderId'       => $orderId,
                    'orderName'     => $orderName,
                    'amount'        => $amount,
                    'paymentMethod' => $paymentMethod,
                    'paymentStatus' => $paymentStatus,
                    'customerEmail' => $customerEmail,
                    'paidAt'        => $paidAt,
                ];
            } catch (\Throwable $e) {
                error_log('[payment-complete] DB error: ' . $e->getMessage());
                $error = '결제 정보 저장 중 오류가 발생했습니다.';
            }
        }
    } else {
        $resArr = is_string($response) ? json_decode($response, true) : null;
        $error  = is_array($resArr)
            ? ($resArr['message'] ?? '결제 확인에 실패했습니다.')
            : '결제 확인에 실패했습니다.';
    }
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
                            <?php if ($success): ?>
                                <h2 class="title-main">결제가 완료되었습니다</h2>
                            <?php else: ?>
                                <h2 class="title-main">결제에 실패하였습니다</h2>
                            <?php endif; ?>
                        </div>
                        <div class="payment__contents-card">
                            <?php if ($success): ?>
                            <div class="alert__title-wrap">
                                <img class="payment--complete__icon" src="../images/common/payment_check.png" alt="payment_check">
                                <p class="alert__title">구독이 완료되었습니다</p>
                            </div>
                            <div class="payment--complete__contents">
                                <p class="payment--complete__plan-name"><?= htmlspecialchars($orderResult['orderName'], ENT_QUOTES, 'UTF-8') ?></p>
                                <ul class="payment--complete__list">
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">주문번호</span>
                                        <span class="payment--complete__value"><?= htmlspecialchars($orderResult['orderId'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">결제수단</span>
                                        <span class="payment--complete__value"><?= htmlspecialchars($orderResult['paymentMethod'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">결제상태</span>
                                        <span class="payment--complete__value"><?= htmlspecialchars($orderResult['paymentStatus'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">결제금액</span>
                                        <span class="payment--complete__value"><?= number_format((int)$orderResult['amount']) ?>원</span>
                                    </li>
                                    <?php if (!empty($orderResult['customerEmail'])): ?>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">이메일</span>
                                        <span class="payment--complete__value"><?= htmlspecialchars($orderResult['customerEmail'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                    <?php endif; ?>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">결제일시</span>
                                        <span class="payment--complete__value"><?= htmlspecialchars($orderResult['paidAt'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                </ul>
                                <p class="payment--complete__note">구독 서비스 이용 관련 문의는 고객센터로 연락해 주세요.</p>
                            </div>
                            <div style="display:flex; gap:12px;">
                                <a href="/index.php" class="btn-caify" style="flex:1; text-align:center;">홈으로</a>
                                <a href="/prompt/prompt.php" class="btn-caify" style="flex:1; text-align:center;">서비스 시작하기</a>
                            </div>

                            <?php else: ?>
                            <div class="alert__title-wrap">
                                <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="32" cy="32" r="32" fill="#FFEBEE"/>
                                    <path d="M20 20l24 24M44 20L20 44" stroke="#c62828" stroke-width="4" stroke-linecap="round"/>
                                </svg>
                                <p class="alert__title">결제에 실패하였습니다</p>
                            </div>
                            <div class="payment--complete__contents" style="text-align:center;">
                                <p style="color:#c62828; font-weight:600; font-size:16px; margin:0 0 8px;">
                                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <p style="color:#888; font-size:13px; margin:0;">
                                    문제가 지속될 경우 고객센터로 문의해 주세요.
                                </p>
                            </div>
                            <div style="display:flex; gap:12px;">
                                <a href="/payment/payment.php" class="btn-caify" style="flex:1; text-align:center;">다시 결제하기</a>
                                <a href="/index.php" class="btn-caify" style="flex:1; text-align:center;">홈으로</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
<?php include '../footer.inc.php'; ?>
