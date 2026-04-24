<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();

// 현재 페이지 체크해서 css 로드 
$currentPage = 'bg_page';
?>

<?php include '../header.inc.php'; ?>   
     
    <main class="main">
        <div class="container">
            <?php include '../inc/snb.inc.php'; ?>
            <div class="content__wrap">
                <div class="content__inner">
                    <div class="content__header">
                        <h2 class="content__header__title">프롬프트 관리</h2>
                    </div>
                    <div class="content">
                        <div class="total__num">총 <strong>100</strong>건의 프롬프트가 있습니다.</div>
                        <table class="table table--list">
                            <colgroup>
                                <col style="width: 10%;">
                                <col style="width: auto;">
                                <col style="width: 20%;">
                            </colgroup>
                            <thead>
                                <th>번호</th>
                                <th>제목</th>
                                <th>신청일</th>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td class="text--left"><a href="prompt.php">프롬프트 제목</a></td>
                                    <td>2026.01.20.13:00</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="pagination">
                            <a href="" class="pagination__item pagination__item--prev">이전</a>
                            <a href="" class="pagination__item active">1</a>
                            <a href="" class="pagination__item">2</a>
                            <a href="" class="pagination__item">3</a>
                            <a href="" class="pagination__item">4</a>
                            <a href="" class="pagination__item">5</a>
                            <a href="" class="pagination__item pagination__item--next">다음</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
<?php include '../footer.inc.php'; ?>