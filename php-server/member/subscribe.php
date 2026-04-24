<?php 
// 현재 페이지 체크해서 css 로드 
    $currentPage = 'subscribe'; 
    include '../header.inc.php'; 
?> 
<main class="main">
    <div class="main__container">
        <div class="subscribe-wrap">
            <section class="section subscribe">
                <div class="subscribe-title-wrap">
                    <p class="title--en">subscribe</p>
                    <p class="title--en title--rotate" aria-hidden="true">subscribe</p>
                </div>
                <ul class="subscribe-list">
                    <li class="subscribe-list__item subscribe-list__item-blog">
                        <div class="subscribe-list__info ">
                            <p class="subscribe-list__title">블로그 자동화</p>
                            <div class="subscribe-list__detail">
                                <div class="subscribe-list__price-group">
                                    <div class="subscribe-list__price-wrap">
                                        <div class="subscribe-list__price-original subscribe-list__price-original--hidden">
                                            <s>600,000</s>
                                        </div>
                                        <div class="subscribe-list__price-current">
                                            <span class="subscribe-list__price-symbol">￦</span><span class="subscribe-list__price">300,000</span><span class="subscribe-list__price-period">/mo</span>
                                        </div>
                                    </div>
                                    <div class="subscribe-list__price-note">( 월 단위 / VAT 별도 )
                                    </div>
                                </div>
                                <p class="subscribe-list__feature-label">포스팅 자동 생성</p>
                                <ul class="subscribe-list__feature-list">
                                    <li class="subscribe-list__feature-item">고품질 블로그 포스팅 매일 3개 (월~금)<br> / 월 60개 제공<br><span class="subscribe-list__note">( 맞춤형 포스팅 작성 + 이미지 생성 )</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <a href="/payment/payment.php" class="btn-subscribe">구독하기</a>
                        <a href="/member/item-blog.php" class="link-detail">상품 자세히보기</a>
                    </li>
                    <!-- <li class="subscribe-list__item subscribe-list__item-homepage">
                        <div class="subscribe-list__info">
                            <p class="subscribe-list__title">홈페이지 제작</p>
                            <div class="subscribe-list__detail">
                                <div class="subscribe-list__price-group">
                                    <div class="subscribe-list__price-wrap">
                                        <div class="subscribe-list__price-original subscribe-list__price-original--hidden">
                                            <s>600,000</s>
                                        </div>
                                        <div class="subscribe-list__price-current">
                                            <span class="subscribe-list__price-symbol">￦</span><span class="subscribe-list__price">300,000</span><span class="subscribe-list__price-period">/mo</span>
                                        </div>
                                    </div>
                                    <div class="subscribe-list__price-note">( 월 단위 / VAT 별도 )
                                    </div>
                                </div>
                                <p class="subscribe-list__feature-label">홈페이지 제작</p>
                                <ul class="subscribe-list__feature-list">
                                    <li class="subscribe-list__feature-item">브랜드 홈페이지 제작 및 제공<br>
                                        <span class="subscribe-list__note">
                                        (메인페이지 + 공지사항 + 홍보자료 + 문의폼)</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <a href="/payment/payment.php" class="btn-subscribe">구독하기</a>
                        <a href="/member/item-blog.php" class="link-detail">상품 자세히보기</a>
                    </li>
                    <li class="subscribe-list__item subscribe-list__item-package">
                        <div class="subscribe-list__info">
                            <p class="subscribe-list__title">패키지</p>
                            <div class="subscribe-list__detail">
                                <div class="subscribe-list__price-group">
                                    <div class="subscribe-list__price-wrap">
                                        <div class="subscribe-list__price-original">
                                            <s>600,000</s>
                                        </div>
                                        <div class="subscribe-list__price-current">
                                            <span class="subscribe-list__price-symbol">￦</span><span class="subscribe-list__price">500,000</span><span class="subscribe-list__price-period">/mo</span>
                                        </div>
                                    </div>
                                    <div class="subscribe-list__price-note">( 월 단위 / VAT 별도 )
                                    </div>
                                </div>
                                <p class="subscribe-list__feature-label">블로그 + 홈페이지 제작</p>
                                <ul class="subscribe-list__feature-list">
                                    <li class="subscribe-list__feature-item">고품질 블로그 게시글 월 60개<br>
                                        <span class="subscribe-list__note">(게시글 한 개당 텍스트 500자 이상 + 이미지 6장)</span>
                                    </li>
                                    <li class="subscribe-list__feature-item">브랜드 홈페이지<br>
                                        <span class="subscribe-list__note">
                                        (메인페이지 + 공지사항 + 홍보자료 + 문의폼)</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <a href="/payment/payment.php" class="btn-subscribe">구독하기</a>
                        <a href="/member/item-blog.php" class="link-detail">상품 자세히보기</a>
                    </li> -->
                </ul>
            </section>
        </div>
    </div>
</main>

<?php include '../footer.inc.php'; ?> 
</body>
</html>