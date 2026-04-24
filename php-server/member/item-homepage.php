<?php
    $currentPage = 'item-homepage';
    include '../header.inc.php'; 
?> 
<main class="main item-homepage">
    <article class="item-wrap--padding">
        <section class="hero hero--homepage">
            <div class="hero__inner">
                <img class="hero__logo" src="../images/common/caify_logo.png" alt="caify_logo">
                <div class="hero__title-wrap">
                    <p class="hero__subtitle">20년차 UX/UI 디자이너와 협력하여 개발한</p>
                    <p class="hero__title">기업 맞춤형 <span class="point--gradient">홈페이지 제작</span> 시스템</p>
                </div>
                <div class="hero__cta-wrap">
                    <div class="hero__info">
                        <p class="hero__info-brand font--michroma">CAiFY AI</p>
                        <div class="hero__info-detail">
                            <div class="hero__info-img-wrap">
                                <img class="hero__info-icon" src="../images/common/icon_homepage.png" alt="icon_homepage">
                            </div>
                            <p class="hero__info-text">브랜드 홈페이지 제작 및 제공<br>
                                <span class="text__color-gray"></span>
                            </p>
                        </div>
                    </div>
                    <a href="/payment/payment.php" class="subscribe__btn">
                        구독하기
                    </a>
                </div>
            </div>
        </section>
    </article>
</main>
<?php include '../footer.inc.php'; ?> 
</body>
</html>