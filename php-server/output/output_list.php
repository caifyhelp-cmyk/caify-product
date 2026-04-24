<?php
declare(strict_types=1);

session_start();

require "../inc/db.php";	
// customer_id 가져오기 (세션)
$customer_id = (int)($_SESSION['member']['id'] ?? 0);
$is_admin = ($customer_id === 10);

if ($customer_id <= 0) {
    header('Location: ../member/login.php');
    exit;
}


// 페이징 설정
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

try {
    $pdo = db();

    // 관리자 승인 처리
    if (
        $is_admin &&
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        (string)($_POST['action'] ?? '') === 'approve'
    ) {
        $approve_id = (int)($_POST['id'] ?? 0);
        if ($approve_id > 0) {
            $approve_stmt = $pdo->prepare('UPDATE ai_posts SET status = 1 WHERE id = :id LIMIT 1');
            $approve_stmt->execute([':id' => $approve_id]);
        }
        $redirect_page = max(1, (int)($_POST['page'] ?? 1));
        header('Location: output_list.php?page=' . $redirect_page);
        exit;
    }

    // 일반회원: status=1 이고 created_at이 오늘 기준 2일 전 데이터만 노출
    $where_sql = $is_admin
        ? ''
        : " WHERE p.status = 1 AND DATE(p.created_at) <= DATE_SUB(CURDATE(), INTERVAL 2 DAY) AND customer_id = '".$customer_id."' ";

    // 전체 개수 조회
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM ai_posts p" . $where_sql);
    $count_stmt->execute();
    $total_count = (int)$count_stmt->fetch()['total'];
    
    // 데이터 조회
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.title,
            p.naver_html,
            p.created_at,
            p.status,
            p.customer_id,
            COALESCE(m.company_name, '') AS company_name
        FROM ai_posts p
        LEFT JOIN caify_member m ON m.id = p.customer_id
        {$where_sql}
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
	
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
    
    // naver_html에서 첫 번째 이미지 추출 함수
    function extractFirstImage($html) {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }
        return '../images/thum.png'; // 기본 이미지
    }
    
    // 총 페이지 수 계산
    $total_pages = ceil($total_count / $per_page);
    
} catch (Exception $e) {
    $posts = [];
    $total_count = 0;
    $total_pages = 0;
}

// 현재 페이지 체크해서 css 로드 
$currentPage = 'bg_page';

?>
<?php include '../header.inc.php'; ?>

<style>
    .gallery__item .detail .title a {
        display: block;
        font-size: 16px;
        font-weight: 700;
        line-height: 1.45;
        color: #111;
        margin-bottom: 8px;
    }
    .gallery__item .detail .company {
        display: inline-block;
        margin-bottom: 10px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        color: #4b5563;
        background: #f3f4f6;
    }
    .gallery__item .detail .date {
        margin-bottom: 12px;
        color: #9ca3af;
        font-size: 12px;
    }
    .btn__system.status {
        border: 0;
        cursor: default;
        font-weight: 700;
    }
    .btn__system.status--approved {
        background: #e8f8ee;
        color: #18794e;
    }
    .btn__system.status--pending {
        background: #fff4e5;
        color: #b45309;
    }
    .btn__system.status--action {
        background: #2563eb;
        color: #fff;
        cursor: pointer;
    }
    .btn__system.status--action:hover {
        background: #1d4ed8;
    }
</style>

    <main class="main">
        <div class="container">
            <?php include '../inc/snb.inc.php'; ?>
            <div class="content__wrap">
                <div class="content__inner">
                    <div class="content__header">
                        <h2 class="content__header__title">산출물 관리</h2>
                    </div>
                    <div class="content">
                        <div class="total__num">총 <strong><?= number_format($total_count) ?></strong>건의 산출물이 있습니다.</div>
                        
                        <div class="list__gallery">
                            <?php if (empty($posts)): ?>
                                <p style="text-align: center; padding: 50px 0;">산출물이 없습니다.</p>
                            <?php else: ?>
                                <?php foreach ($posts as $post): ?>
                                    <?php 
                                        $thumbnail = extractFirstImage($post['naver_html']);
                                        $date = date('Y.m.d.H:i', strtotime($post['created_at']));
                                        $is_approved = ((int)($post['status'] ?? 0) === 1);
                                    ?>
                                    <!-- item -->                           
                                    <div class="gallery__item">
                                        <div class="thumb">
                                            <a href="output_view.php?id=<?= $post['id'] ?>">
                                                <img src="<?= htmlspecialchars($thumbnail) ?>" alt="<?= htmlspecialchars($post['title']) ?>">
                                            </a>
                                        </div>
                                        <div class="detail">
                                            <div class="title">
                                                <a href="output_view.php?id=<?= $post['id'] ?>">
                                                    <?= htmlspecialchars($post['title']) ?>
                                                </a>
                                            </div>
                                            <div class="company">고객사: <?= htmlspecialchars($post['company_name'] ?: '회사명 없음') ?></div>
                                            <div class="date"><?= $date ?></div>
                                            <?php if ($is_admin): ?>
                                                <?php if ($is_approved): ?>
                                                    <button type="button" class="btn__system status status--approved" disabled>승인완료</button>
                                                <?php else: ?>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                                                        <input type="hidden" name="page" value="<?= (int)$page ?>">
                                                        <button type="submit" class="btn__system status status--action">미승인 · 승인하기</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <a href="output_publish_site.php?id=<?= (int)$post['id'] ?>" target="_blank" class="btn__system">사이트 게재</a>
                                        </div>
                                    </div>
                                    <!-- //item -->
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($total_pages > 0): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>" 
                                   class="pagination__item pagination__item--prev">이전</a>
                            <?php endif; ?>
                            
                            <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?page=<?= $i ?>" 
                                   class="pagination__item <?= $i === $page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>" 
                                   class="pagination__item pagination__item--next">다음</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

<?php include '../footer.inc.php'; ?>