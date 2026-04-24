<?php
// 현재 페이지 체크해서 css 로드 
    $currentPage = 'service'; 
    include 'header.inc.php'; 
?>  

    <main class="main">
        <div class="main__container">
            <section class="section service service--index">
                <div class="service__hero">
                    <h2 class="service__hero-logo">
                        <img src="../images/service/caify_logo_light_wrap.png" alt="caify_logo_light_wrap">
                        <span class="ir">caify</span>
                    </h2>
                </div>
                <ul class="service__card-list">
                    <li class="service__card">
                        <a href="/member/item-blog.php" class="service__card-link">
                            <img src="../images/common/logo_nblog_w.png" alt="nblog_w" class="service__card-img">
                            <p class="service__card-text">블로그 자동화</p>
                        </a>
                    </li>
                    <li class="service__card">
                        <a href="/member/item-homepage.php" class="service__card-link">
                            <img src="../images/common/logo_homepage_w.png" alt="nblog_w" class="service__card-img">
                            <p class="service__card-text">자동형 홈페이지</p>
                        </a>
                    </li>
                    <li class="service__card service__card--shorts">
                        <div class="service__card-test-wrap">
                            <a href="#" class="service__card-link">
                                <img src="../images/common/logo_shorts_w.png" alt="shorts_w" class="service__card-img">
                                <p class="service__card-text">유튜브 쇼츠 자동화</p>
                            </a>
                        </div>
                        <div class="service__card-badge-wrap">
                            <div class="service__card-badge">
                                <img src="../images/common/caify_header_logo_w.png" alt="test_w" class="service__card-badge-img">
                                <p class="service__card-badge-text">베타테스트중</p>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </main>
<?php include 'footer.inc.php'; ?> 
</body>
</html>

