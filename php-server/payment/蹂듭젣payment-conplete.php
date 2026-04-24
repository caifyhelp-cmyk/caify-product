<?php
    $currentPage = 'payment';
    include '../header.inc.php'; 
?> 
    <main class="main payment-common common__main payment--complete">
        <section class="section payment payment--complete">
                <div class="payment__aside">
                    <div class="payment__aside-img">
                        <img src="../images/common/caify_logo_glass_01.png" alt="caify_glass_logo_visual" class="caify-glass--visual">
                        <img src="../images/common/caify_logo_glass_00.png" alt="caify_glass_logo_text" class="caify-glass-text">
                    </div>
                </div>
                <div class="payment__contents">
                    <div class="payment__contents-inner">
                        <div class="payment__contents-title">
                            <img class="caify-logo" src="../images/common/caify_logo.png" alt="caify_logo">
                            <h2 class="title-main">서비스 구독하기</h2>
                        </div>
                        <div class="payment__contents-card">
                            <div class="payment--complete__title-wrap">
                                <img class="payment--complete__icon" src="../images/common/payment_check.png" alt="payment_check">
                                <p class="payment--complete__title">구독이 완료되었습니다</p>
                            </div>
                            <div class="payment--complete__contents">
                                <p class="payment--complete__plan-name">프리미엄 패키지</p>
                                <ul class="payment--complete__list">
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">결제일</span>
                                        <span class="payment--complete__value">2026.02.19</span>
                                    </li>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">이용기간</span>
                                        <span class="payment--complete__value">2026.02.19 ~ 2026.02.20</span>
                                    </li>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">가격</span>
                                        <span class="payment--complete__value">₩330,000</span>
                                    </li>
                                    <li class="payment--complete__item">
                                        <span class="payment--complete__label">이메일</span>
                                        <span class="payment--complete__value">caify@gmail.com</span>
                                    </li>
                                </ul>
                                <p class="payment--complete__note">*자세한 내용은 <a class="payment--complete__link" href="#">구독내역</a>에서 확인가능합니다.</p>
                            </div>
                            <button class="btn-caify" type="submit">확인</button>
                        </div>
                    </div>
                </div>
            </section>
    </main>
<?php include '../footer.inc.php'; ?> 
</body>
</html>