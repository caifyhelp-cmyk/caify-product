
<?php
// 현재 페이지 체크해서 css 로드 
    $currentPage = 'service'; 
    include '../header.inc.php'; 
?> 

    <main class="main">
        <div class="main__container">
            <div class="service--offer-wrap">
                <section class="section service service--offer service--offer-each">
                    <div class="service__hero">
                        <div class="service__hero-logo">
                            <img class="star-line" src="../images/service/star_line.png" alt="starline">
                            <img class="caify-logo" src="../images/common/caify_logo.png" alt="caify_logo">
                        </div>
                        <div class="service__hero-title-wrap">
                            <h2 class="title--en">service</h2>
                            <p class="title--en title--rotate" aria-hidden="true">service</p>
                        </div>
                    </div>
                    <ul class="offer-list">
                        <li class="offer-list__item offer-list__item--blog">
                            <div class="offer-list__info">
                                <img src="../images/common/icon_blog_text.png" alt="icon_blog_text">
                                <p class="offer-list__title">블로그 자동화</p>
                                <p class="offer-list__text">고품질 블로그 포스팅 매일 3개 (월~금) / 월 60개 제공<br>
                                <span class="offer-list__note">( 맞춤형 포스팅 작성 + 이미지 생성 )</span>
                                </p>
                            </div>
                            <p class="offer-list__price">월 30만원<br>
                                <span class="offer-list__price-note">( VAT 별도 )</span>
                            </p>
                            <a href="/payment/payment.php" class="btn-subscribe">구독하기</a>
                            <a href="/member/item-blog.php" class="link-detail">상품 자세히보기</a>
                        </li>
                        <li class="offer-list__item offer-list__item--homepage">
                            <div class="offer-list__info">
                                <img src="../images/common/icon_homepage.png" alt="icon_homepage_text">
                                <p class="offer-list__title">자동형 홈페이지</p>
                                <p class="offer-list__text">브랜드 홈페이지 제작 및 제공
                                </p>
                            </div>
                            <p class="offer-list__price">월 30만원<br>
                                <span class="offer-list__price-note">( VAT 별도 )</span>
                            </p>
                            <a href="/payment/payment.php" class="btn-subscribe">구독하기</a>
                            <a href="/member/item-homepage.php" class="link-detail">상품 자세히보기</a>
                        </li>
                    </ul>
                </section>
                
                <section class="section service service--offer service--offer-package">
                    <div class="service__hero">
                        <div class="service__hero-logo">
                            <img class="star-line" src="../images/service/star_line.png" alt="starline">
                        </div>
                        <div class="service__hero-title-wrap">
                            <h3 class="title--en">package</h3>
                            <p class="title--en title--rotate" aria-hidden="true">package</p>
                        </div>
                    </div>
                    <div class="offer-package__discount">
                        <p class="discount__sub">기.간.한.정</p>
                        <p class="discount__title">초특가할인</p>
                    </div>
                    <div class="offer-package__date">
                        <span class="offer-package__date-num">~</span>
                        <span class="offer-package__date-num">2</span>
                        <span class="offer-package__date-num">0</span>
                        <span class="offer-package__date-num">2</span>
                        <span class="offer-package__date-num">6</span>
                        <span class="offer-package__date-text">년</span>
                        <span class="offer-package__date-num">0</span>
                        <span class="offer-package__date-num">8</span>
                        <span class="offer-package__date-text">월</span>
                        <span class="offer-package__date-num">2</span>
                        <span class="offer-package__date-num">0</span>
                        <span class="offer-package__date-text">일 까지</span>
                    </div>
                    <div class="offer-package__product">
                        <div class="offer-package__card">
                            <div class="offer-package__card-header">
                                <div class="offer-package__badge">패키지</div>
                                <div class="offer-package__price-wrap">
                                    <p class="offer-package__price-origin">월 60만원</p>
                                    <p class="offer-package__price">월 50만원</p>
                                </div>
                                <p class="offer-package__desc">사용자의 계정에 업로드하여<br>
                                이용 종료 후에도 <span class="offer-package__desc-point">콘텐츠는 그대로!</span></p>
                            </div>
                            <div class="offer-package__card-body">
                                <div class="offer-package__sub-title">2개 상품 패키지</div>
                                <div class="offer-package__logo-wrap">
                                    <img src="../images/common/logo_nblog_w.png" alt="logo_nblog_w">
                                    <p class="offer-package__logo-plus">+</p>
                                    <img src="../images/common/logo_homepage_w.png" alt="logo_homepage_w">
                                </div>
                                <ul class="offer-package__service-list">
                                    <li class="offer-package__service-item">블로그 글 자동화</li>
                                    <li class="offer-package__service-item">홈페이지 제작</li>
                                </ul>
                                <ul class="offer-package__detail-list">
                                    <li class="offer-package__detail-item">· 고품질 블로그 게시글 월 60개<br>
                                        <span class="offer-package__detail-note">(게시글 한 개당 텍스트 500자 이상 + 이미지 6장)</span>
                                    </li>
                                    <li class="offer-package__detail-item">· 브랜드 홈페이지 제작 및 제공<br>
                                        <span class="offer-package__detail-note">(메인페이지 + 공지사항 + 홍보자료 + 문의폼)</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <a href="/payment/payment.php" class="btn-subscribe-accent">
                            구독하기
                        </a>
                    </div>
                </section>
                <div class="section service service--footer">
                    <img src="../images/common/caify_logo.png" alt="caify_logo">
                    <img class="title--rotate" src="../images/common/caify_logo.png" alt="caify_logo">
                </div>
            </div>
        </div>
    </main>
<?php include '../footer.inc.php'; ?> 
</body>
</html>