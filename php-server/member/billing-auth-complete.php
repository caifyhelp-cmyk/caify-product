<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member      = current_member();
$memberPk    = (int)($member['id'] ?? 0);
$memberEmail = (string)($member['member_id'] ?? '');

// TossPayments 리디렉션 파라미터
$authKey       = trim((string)($_GET['authKey']       ?? ''));
$customerKey   = trim((string)($_GET['customerKey']   ?? ''));
$billingMethod = trim((string)($_GET['billingMethod'] ?? 'CARD'));

$success     = false;
$error       = '';
$orderResult = [];

if ($authKey === '' || $customerKey === '') {
    $error = '잘못된 접근입니다.';
} else {
    $secretKey  = 'test_sk_Lex6BJGQOVDMe0X7q9JrW4w2zNbg';
    $credential = base64_encode($secretKey . ':');

    // ── Step 1: 빌링키 발급 ─────────────────────────────────
    $curl = curl_init('https://api.tosspayments.com/v1/billing/authorizations/issue');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $credential,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'customerKey' => $customerKey,
            'authKey'     => $authKey,
        ]),
    ]);
    $issueResponse = curl_exec($curl);
    $issueHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $issueCurlErr  = curl_errno($curl);
    curl_close($curl);

    if ($issueCurlErr) {
        $error = '빌링키 발급 서버 연결에 실패했습니다.';
    } elseif ($issueHttpCode !== 200) {
        $resArr = is_string($issueResponse) ? json_decode($issueResponse, true) : null;
        $error  = is_array($resArr)
            ? ($resArr['message'] ?? '빌링키 발급에 실패했습니다.')
            : '빌링키 발급에 실패했습니다.';
    } else {
        $issueResp = json_decode((string)$issueResponse, true);

        if (!is_array($issueResp) || empty($issueResp['billingKey'])) {
            $error = '빌링키 응답을 처리할 수 없습니다.';
        } else {
            $billingKey = (string)$issueResp['billingKey'];

            // 회원명 조회
            $memberName = '';
            try {
                $pdo  = db();
                $stmt = $pdo->prepare('SELECT member_name FROM caify_member WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $memberPk]);
                $row = $stmt->fetch();
                $memberName = (string)($row['member_name'] ?? '');
            } catch (\Throwable $e) { }

            // ── Step 2: 첫 번째 정기결제 실행 ───────────────────
            $payAmount    = 330000;
            $payOrderName = '블로그 자동화 구독';
            $orderId      = 'CAIFY_' . $memberPk . '_' . time();

            $curl2 = curl_init('https://api.tosspayments.com/v1/billing/' . urlencode($billingKey));
            curl_setopt_array($curl2, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . $credential,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'customerKey'   => $customerKey,
                    'amount'        => $payAmount,
                    'orderId'       => $orderId,
                    'orderName'     => $payOrderName,
                    'customerEmail' => $memberEmail,
                    'customerName'  => $memberName ?: '회원',
                ]),
            ]);
            $payResponse  = curl_exec($curl2);
            $payHttpCode  = curl_getinfo($curl2, CURLINFO_HTTP_CODE);
            $payCurlErr   = curl_errno($curl2);
            curl_close($curl2);

            if ($payCurlErr) {
                $error = '결제 서버 연결에 실패했습니다.';
            } elseif ($payHttpCode !== 200) {
                $resArr = is_string($payResponse) ? json_decode($payResponse, true) : null;
                $error  = is_array($resArr)
                    ? ($resArr['message'] ?? '결제에 실패했습니다.')
                    : '결제에 실패했습니다.';
            } else {
                $payResp = json_decode((string)$payResponse, true);

                if (!is_array($payResp)) {
                    $error = '결제 응답을 처리할 수 없습니다.';
                } else {
                    $paymentMethod = '기타';
                    $paymentStatus = '결제완료';
                    if (!empty($payResp['card']))            $paymentMethod = '신용카드';
                    elseif (!empty($payResp['transfer']))    $paymentMethod = '계좌이체';

                    $paymentKey = (string)($payResp['paymentKey'] ?? '');
                    $paidAt     = !empty($payResp['approvedAt'])
                        ? date('Y-m-d H:i:s', strtotime((string)$payResp['approvedAt']))
                        : date('Y-m-d H:i:s');

                    // ── Step 3: DB 저장 ──────────────────────────────
                    try {
                        $pdo = db();

                        // 빌링키 테이블 (없으면 자동 생성)
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS caify_billing_key (
                                id           INT AUTO_INCREMENT PRIMARY KEY,
                                member_pk    INT NOT NULL,
                                customer_key VARCHAR(100) NOT NULL,
                                billing_key  VARCHAR(200) NOT NULL,
                                method       VARCHAR(20),
                                status       VARCHAR(20) DEFAULT 'active',
                                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                UNIQUE KEY uq_member_method (member_pk, method)
                            ) DEFAULT CHARSET=utf8mb4
                        ");

                        // 구독 결제 기록 테이블 (없으면 자동 생성)
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS caify_subscription (
                                id             INT AUTO_INCREMENT PRIMARY KEY,
                                member_pk      INT NOT NULL,
                                order_id       VARCHAR(100) NOT NULL,
                                payment_key    VARCHAR(200),
                                billing_key    VARCHAR(200),
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

                        // 기존 테이블에 billing_key 컬럼이 없으면 추가
                        $colCheck = $pdo->prepare("
                            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME   = 'caify_subscription'
                              AND COLUMN_NAME  = 'billing_key'
                        ");
                        $colCheck->execute();
                        if ((int)$colCheck->fetchColumn() === 0) {
                            $pdo->exec("ALTER TABLE caify_subscription ADD COLUMN billing_key VARCHAR(200) AFTER payment_key");
                        }

                        // 빌링키 저장/갱신 (회원당 수단별 1건 유지)
                        $stmt = $pdo->prepare("
                            INSERT INTO caify_billing_key
                                (member_pk, customer_key, billing_key, method, status)
                            VALUES
                                (:member_pk, :customer_key, :billing_key, :method, 'active')
                            ON DUPLICATE KEY UPDATE
                                billing_key  = VALUES(billing_key),
                                customer_key = VALUES(customer_key),
                                status       = 'active',
                                updated_at   = NOW()
                        ");
                        $stmt->execute([
                            ':member_pk'    => $memberPk,
                            ':customer_key' => $customerKey,
                            ':billing_key'  => $billingKey,
                            ':method'       => $billingMethod,
                        ]);

                        // 결제 기록 저장
                        $stmt2 = $pdo->prepare("
                            INSERT INTO caify_subscription
                                (member_pk, order_id, payment_key, billing_key, amount,
                                 order_name, payment_method, payment_status, customer_email, paid_at)
                            VALUES
                                (:member_pk, :order_id, :payment_key, :billing_key, :amount,
                                 :order_name, :payment_method, :payment_status, :customer_email, :paid_at)
                            ON DUPLICATE KEY UPDATE
                                payment_key    = VALUES(payment_key),
                                payment_method = VALUES(payment_method),
                                payment_status = VALUES(payment_status),
                                paid_at        = VALUES(paid_at),
                                updated_at     = NOW()
                        ");
                        $stmt2->execute([
                            ':member_pk'      => $memberPk,
                            ':order_id'       => $orderId,
                            ':payment_key'    => $paymentKey,
                            ':billing_key'    => $billingKey,
                            ':amount'         => $payAmount,
                            ':order_name'     => $payOrderName,
                            ':payment_method' => $paymentMethod,
                            ':payment_status' => $paymentStatus,
                            ':customer_email' => $memberEmail,
                            ':paid_at'        => $paidAt,
                        ]);

                        $success     = true;
                        $orderResult = [
                            'orderId'        => $orderId,
                            'orderName'      => $payOrderName,
                            'amount'         => $payAmount,
                            'paymentMethod'  => $paymentMethod,
                            'paymentStatus'  => $paymentStatus,
                            'customerEmail'  => $memberEmail,
                            'paidAt'         => $paidAt,
                            'billingMethod'  => $billingMethod,
                        ];

                        error_log('[billing-auth-complete] 정기결제 등록 성공 - memberPk=' . $memberPk . ', orderId=' . $orderId);

                    } catch (\Throwable $e) {
                        error_log('[billing-auth-complete] DB error: ' . $e->getMessage());
                        $error = '결제 정보 저장 중 오류가 발생했습니다. (' . $e->getMessage() . ')';
                    }
                }
            }
        }
    }
}

// $currentPage = 'payment';
include '../header.inc.php';
?>

<style>
html, body { height: 100%; }
body { display: flex; flex-direction: column; }

/* ── 결제완료 카드 ── */
.bac-wrap {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 16px;
}
.bac-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 8px 40px rgba(0,0,0,.08);
    width: 100%;
    max-width: 460px;
    overflow: hidden;
}

/* 배너 */
.bac-banner {
    padding: 36px 32px 28px;
    text-align: center;
}
.bac-banner--success { background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%); }
.bac-banner--fail    { background: linear-gradient(135deg, #ffebee 0%, #fce4ec 100%); }

.bac-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}
.bac-icon--success { background: #2e7d32; }
.bac-icon--fail    { background: #c62828; }

.bac-banner__title {
    font-size: 20px;
    font-weight: 700;
    color: #111;
    margin: 0 0 6px;
    letter-spacing: -.3px;
}
.bac-banner__sub {
    font-size: 13px;
    color: #666;
    margin: 0;
    line-height: 1.7;
}

/* 본문 */
.bac-body { padding: 24px 32px 28px; }

.bac-amount {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    background: #f8f9fa;
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 20px;
}
.bac-amount__label { font-size: 13px; color: #888; }
.bac-amount__value { font-size: 26px; font-weight: 800; color: #111; letter-spacing: -.5px; }
.bac-amount__value span { font-size: 16px; font-weight: 600; margin-left: 2px; }

.bac-list {
    list-style: none;
    margin: 0 0 20px;
    padding: 0;
}
.bac-list__row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 14px;
}
.bac-list__row:last-child { border-bottom: none; }
.bac-list__key { color: #aaa; white-space: nowrap; flex-shrink: 0; }
.bac-list__val { color: #222; font-weight: 500; text-align: right; word-break: break-all; }
.bac-list__val--sm  { font-size: 12px; color: #999; }
.bac-badge {
    display: inline-block;
    background: #e8f5e9;
    color: #2e7d32;
    font-size: 12px;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 20px;
}

.bac-notice {
    font-size: 12px;
    color: #bbb;
    text-align: center;
    line-height: 1.8;
    margin: 0 0 22px;
}

/* 오류 박스 */
.bac-error {
    background: #fff8f8;
    border: 1px solid #ffcdd2;
    border-radius: 12px;
    padding: 20px 22px;
    margin-bottom: 22px;
    text-align: center;
}
.bac-error__title { font-size: 15px; font-weight: 700; color: #c62828; margin: 0 0 6px; }
.bac-error__desc  { font-size: 13px; color: #aaa; margin: 0; }

/* 버튼 */
.bac-btns { display: flex; gap: 10px; }
.bac-btn {
    flex: 1;
    height: 48px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: opacity .15s, transform .1s;
}
.bac-btn:active { transform: scale(.97); }
.bac-btn--primary { background: #111; color: #fff; }
.bac-btn--primary:hover { opacity: .82; }
.bac-btn--outline { background: #fff; border: 1.5px solid #ddd; color: #444; }
.bac-btn--outline:hover { background: #f5f5f5; }
.bac-btn--danger  { background: #c62828; color: #fff; }
.bac-btn--danger:hover { opacity: .82; }
</style>

<main class="payment payment-common main common__main" style="display:flex; flex-direction:column; flex:1;">
    <section class="payment__section payment-common__section bac-wrap">

        <div class="bac-card">
            <?php if ($success): ?>
            <!-- ── 성공 ── -->
            <div class="bac-banner bac-banner--success">
                <div class="bac-icon bac-icon--success">
                    <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
                        <path d="M6 16l8 8 10-12" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h2 class="bac-banner__title">구독이 시작되었습니다</h2>
                <p class="bac-banner__sub">
                    <?= $orderResult['billingMethod'] === 'TRANSFER' ? '계좌 자동결제' : '카드 자동결제' ?>
                    수단이 등록되었고<br>첫 번째 결제가 완료되었습니다.
                </p>
            </div>

            <div class="bac-body">
                <div class="bac-amount">
                    <span class="bac-amount__label">결제 금액</span>
                    <span class="bac-amount__value">
                        <?= number_format((int)$orderResult['amount']) ?><span>원</span>
                    </span>
                </div>

                <ul class="bac-list">
                    <li class="bac-list__row">
                        <span class="bac-list__key">주문명</span>
                        <span class="bac-list__val"><?= htmlspecialchars($orderResult['orderName'], ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                    <li class="bac-list__row">
                        <span class="bac-list__key">결제수단</span>
                        <span class="bac-list__val"><?= htmlspecialchars($orderResult['paymentMethod'], ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                    <li class="bac-list__row">
                        <span class="bac-list__key">결제상태</span>
                        <span class="bac-list__val">
                            <em class="bac-badge"><?= htmlspecialchars($orderResult['paymentStatus'], ENT_QUOTES, 'UTF-8') ?></em>
                        </span>
                    </li>
                    <li class="bac-list__row">
                        <span class="bac-list__key">결제일시</span>
                        <span class="bac-list__val"><?= htmlspecialchars($orderResult['paidAt'], ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                    <li class="bac-list__row">
                        <span class="bac-list__key">주문번호</span>
                        <span class="bac-list__val bac-list__val--sm"><?= htmlspecialchars($orderResult['orderId'], ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                </ul>

                <p class="bac-notice">
                    이후 매월 동일한 수단으로 자동 결제됩니다.<br>
                    구독 관련 문의는 고객센터로 연락해 주세요.
                </p>

                <div class="bac-btns">
                    <a href="/index.php" class="bac-btn bac-btn--outline">홈으로</a>
                    <a href="/prompt/prompt.php" class="bac-btn bac-btn--primary">서비스 시작하기</a>
                </div>
            </div>

            <?php else: ?>
            <!-- ── 실패 ── -->
            <div class="bac-banner bac-banner--fail">
                <div class="bac-icon bac-icon--fail">
                    <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
                        <path d="M8 8l14 14M22 8L8 22" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <h2 class="bac-banner__title">결제에 실패하였습니다</h2>
                <p class="bac-banner__sub">결제 처리 중 문제가 발생했습니다.</p>
            </div>

            <div class="bac-body">
                <div class="bac-error">
                    <p class="bac-error__title"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="bac-error__desc">문제가 지속될 경우 고객센터로 문의해 주세요.</p>
                </div>

                <div class="bac-btns">
                    <a href="/member/payment.php" class="bac-btn bac-btn--danger">다시 시도하기</a>
                    <a href="/index.php" class="bac-btn bac-btn--outline">홈으로</a>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </section>
</main>

<?php include '../footer.inc.php'; ?>
