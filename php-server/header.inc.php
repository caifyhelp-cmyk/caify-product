<?php 
declare(strict_types=1);

session_start();
// customer_id 가져오기 (세션)
$customer_id = (int)($_SESSION['member']['id'] ?? 0);
$is_admin = ($customer_id === 10);

?>

<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:type" content="website">
    <meta property="og:title" content="카이파이">
    <meta property="og:description" content="카이파이">
    <meta property="og:image" content="/images/caify_og.png">
    <meta property="og:url" content="https://www.caify.ai">
    <link rel="icon" href="/images/favicon.ico">
    <meta name="Keywords" content="">
    <title>CAiFY</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if (!empty($currentPage)): ?>
    <link rel="stylesheet" href="/assets/css/<?= $currentPage ?>.css?mtime=<?= date("YmdHis") ?>">
    <?php endif; ?>   
    <script src="js/jquery-3.7.1.min.js"></script>
</head>

<body class="body">
    <header class="header">
        <div class="header__innear">
            <nav class="gnb">
                <div class="gnb__logo">
                    <a class="gnb__logo-link" href="/">
                        <img src="../images/common/caify_logo.png" alt="caify_logo">
                    </a>
                </div>
                <ul class="gnb__menu">
                    <!-- <li class="gnb__menu-item"><a href="#">회사소개</a></li> -->
                    <li class="gnb__menu-item gnb__menu-item--dropdown"><a class="gnb__menu-link" href="/service/service-offer.php">서비스</a></li>
                    <li class="gnb__menu-item"><a href="/member/subscribe.php">요금제</a></li>
                    <!-- <li class="gnb__menu-item"><a href="#">문의</a></li> -->
                </ul>
                <div class="gnb__login">
                    <img class="gnb__login-img" src="../images/common/login_user.png" alt="login_user">
                    <div class="login__wrap">
						<?php if(empty($customer_id)):?>
							<!-- 로그인시 a.gnb__login-link--logout{display:none;} -->
							<a href="/member/login.php" class="gnb__login-link gnb__login-link--before">로그인</a>
							<a href="/member/join.php" class="gnb__login-link gnb__login-link--before">회원가입</a>
							<!-- 로그인시 end -->
						<?php else:?>
							<!-- 로그아웃시 start
							a.gnb__login-link--before{display:none;} -->
							<a href="/mycaify/profile_edit.php" class="gnb__login-link gnb__login-link--after">마이페이지</a>
							<a href="/output/output_list.php" class="gnb__login-link gnb__login-link--after">산출물관리</a>
							<a href="/prompt/prompt.php" class="gnb__login-link gnb__login-link--after">프롬프트관리</a>
							<!-- <a href="#" class="gnb__login-link gnb__login-link--after">정보수정</a> -->
							<!-- 로그아웃버튼 클래스명 : gnb__login-link--exit -->
							<a href="/member/logout.php" class="gnb__login-link gnb__login-link--after gnb__login-link--exit">로그아웃</a>
							<!-- 로그아웃시 end -->
						<?php endif;?>
                    </div>
                </div>            
            </nav>
            <div class="gnb__dropdown">
                <div class="gnb__lnb lnb">
                    <div class="lnb__panel lnb__panel--service">
                        <a href="#" class="lnb__item">블로그</a>
                        <a href="#" class="lnb__item">유튜브쇼츠</a>
                        <a href="#" class="lnb__item">홈페이지</a>
                    </div>
                </div>
            </div>     
        </div>
    </header>