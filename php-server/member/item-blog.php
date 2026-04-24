<?php
    $currentPage = 'item-blog';
    include '../header.inc.php'; 
?> 
<main class="main item-blog main__service">
    <article class="item-wrap--padding">
        <section class="hero hero--blog">
            <div class="hero__inner">
                <img class="hero__logo" src="../images/common/caify_logo.png" alt="caify_logo">
                <div class="hero__title-wrap">
                    <p class="hero__subtitle">10년차 개발자와 전문 마케팅 팀이 협력하여 만든</p>
                    <p class="hero__title">최첨단 <span class="point--gradient">블로그 자동화</span> 시스템</p>
                </div>
                <div class="hero__cta-wrap">
                    <div class="hero__info">
                        <p class="hero__info-brand font--michroma">CAiFY AI</p>
                        <div class="hero__info-detail">
                            <div class="hero__info-img-wrap">
                                <img class="hero__info-icon" src="../images/common/icon_blog_text.png" alt="icon_blog_text">
                            </div>
                            <p class="hero__info-text">고품질 블로그 게시글 60개<br>
                                <span class="text__color-gray">( 게시글 한 개당 텍스트 500자 이상 + 이미지 6장 )</span>
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