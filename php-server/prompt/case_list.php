<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();

$memberPk = (int)($member['id'] ?? 0);

$cases      = [];
$totalCount = 0;
$perPage    = 15;
$currentPageNum = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($currentPageNum - 1) * $perPage;

if ($memberPk > 0) {
    $pdo = db();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM caify_case WHERE member_pk = :member_pk AND status = 1');
    $countStmt->execute([':member_pk' => $memberPk]);
    $totalCount = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare(
        'SELECT id, case_title, raw_content, ai_structured_json, ai_title, ai_status, created_at
           FROM caify_case
          WHERE member_pk = :member_pk AND status = 1
          ORDER BY id DESC
          LIMIT :limit OFFSET :offset'
    );
    $listStmt->bindValue(':member_pk', $memberPk, PDO::PARAM_INT);
    $listStmt->bindValue(':limit',     $perPage,  PDO::PARAM_INT);
    $listStmt->bindValue(':offset',    $offset,   PDO::PARAM_INT);
    $listStmt->execute();
    $cases = $listStmt->fetchAll();
}

$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;

$currentPage = 'bg_page';
?>

<?php include '../header.inc.php'; ?>

<main class="main">
    <div class="container">
        <?php include '../inc/snb.inc.php'; ?>
        <div class="content__wrap">
            <div class="content__inner">
                <div class="content__header">
                    <h2 class="content__header__title">고객 사례 관리</h2>
                </div>
                <div class="content content--case-list">
                    <section class="case-hero">
                        <div class="case-hero__text">
                            <span class="case-hero__eyebrow">Case Workspace</span>
                            <h3 class="case-hero__title">사례 데이터를 정리하고 AI 초안 작성 흐름까지 한 번에 관리하세요</h3>
                            <p class="case-hero__desc">
                                자유 입력한 사례 원문을 보관하고, AI가 분석한 분류 결과와 블로그 초안을 함께 관리하는 페이지입니다.
                            </p>
                        </div>
                        <div class="case-hero__actions">
                            <a href="case_type_select_chat.php" class="btn btn--primary case-hero__button">+ 새 사례 등록</a>
                        </div>
                    </section>

                    <section class="case-stats">
                        <div class="case-stat-card">
                            <span class="case-stat-card__label">전체 사례</span>
                            <strong class="case-stat-card__value"><?= (int)$totalCount ?></strong>
                        </div>
                        <div class="case-stat-card">
                            <span class="case-stat-card__label">현재 페이지</span>
                            <strong class="case-stat-card__value"><?= (int)$currentPageNum ?></strong>
                        </div>
                        <div class="case-stat-card">
                            <span class="case-stat-card__label">페이지당 노출</span>
                            <strong class="case-stat-card__value"><?= (int)$perPage ?></strong>
                        </div>
                    </section>

                    <section class="case-limit-banner">
                        <strong>사용 안내</strong>
                        <span>사례를 저장한 뒤 AI 분류와 본문 초안 생성을 반복해서 사용할 수 있습니다.</span>
                    </section>

                    <div class="case-table-panel">
                    <div class="case-table-panel__head">
                        <div class="case-table-panel__title-wrap">
                            <h3 class="case-table-panel__title">사례 리스트</h3>
                            <p class="case-table-panel__meta">원문 사례와 AI 분류 결과를 함께 확인할 수 있습니다.</p>
                        </div>
                        <span class="case-table-panel__chip">AI Ready</span>
                    </div>

                    <table class="table table--list case-table">
                        <colgroup>
                            <col style="width: 6%;">
                            <col style="width: auto;">
                            <col style="width: 16%;">
                            <col style="width: 9%;">
                            <col style="width: 10%;">
                            <col style="width: 14%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>번호</th>
                                <th>사례명 / AI 제목</th>
                                <th>AI 분류</th>
                                <th>AI 상태</th>
                                <th>등록일</th>
                                <th>관리</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (is_array($cases) && count($cases) > 0): ?>
                                <?php foreach ($cases as $idx => $row): ?>
                                    <?php
                                        $rowNum       = $totalCount - $offset - $idx;
                                        $displayTitle = !empty($row['case_title'])
                                            ? $row['case_title']
                                            : (!empty($row['ai_title']) ? $row['ai_title'] : '-');
                                        $structured = [];
                                        if (!empty($row['ai_structured_json'])) {
                                            $decoded = json_decode((string)$row['ai_structured_json'], true);
                                            if (is_array($decoded)) {
                                                $structured = $decoded;
                                            }
                                        }
                                        $caseCategory = trim((string)($structured['case_category'] ?? ''));
                                        $subjectLabel = trim((string)($structured['subject_label'] ?? ''));
                                        $aiStatusLabel = match ($row['ai_status'] ?? 'pending') {
                                            'done'  => '<span class="badge badge--done">완료</span>',
                                            'error' => '<span class="badge badge--error">오류</span>',
                                            default => '<span class="badge badge--pending">미분석</span>',
                                        };
                                        $createdAt = !empty($row['created_at'])
                                            ? date('Y.m.d', strtotime((string)$row['created_at']))
                                            : '-';
                                    ?>
                                    <tr class="case-table__row">
                                        <td><?= (int)$rowNum ?></td>
                                        <td class="text--left">
                                            <a href="case_short_typed_chat_.php?id=<?= (int)$row['id'] ?>" class="case-table__title-link">
                                                <?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <?php if (!empty($row['ai_title']) && $row['ai_title'] !== $displayTitle): ?>
                                                <br><span class="case-table__subtext">
                                                    AI 제목: <?= htmlspecialchars((string)$row['ai_title'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($caseCategory !== ''): ?>
                                                <span class="case-type-tag"><?= htmlspecialchars($caseCategory, ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if ($subjectLabel !== ''): ?>
                                                    <br><span class="case-table__subtext"><?= htmlspecialchars($subjectLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#ccc;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $aiStatusLabel ?></td>
                                        <td><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="case-actions">
                                                <a href="case_short_typed_chat_.php?id=<?= (int)$row['id'] ?>"
                                                    class="case-action-link">수정</a>
                                                <span class="case-action-divider"></span>
                                                <button type="button" class="case-action-link case-action-link--danger"
                                                    onclick="deleteCase(<?= (int)$row['id'] ?>)">삭제</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:40px 0; color:#999;">
                                        등록된 고객 사례가 없습니다.<br>
                                        <a href="case_type_select_chat.php"
                                            style="color:#2563EB; margin-top:8px; display:inline-block;">
                                            + 첫 번째 사례 등록하기
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination case-pagination">
                            <?php if ($currentPageNum > 1): ?>
                                <a href="?page=<?= $currentPageNum - 1 ?>" class="pagination__item pagination__item--prev">이전</a>
                            <?php endif; ?>
                            <?php
                                $pageRange = 5;
                                $startPage = max(1, $currentPageNum - (int)floor($pageRange / 2));
                                $endPage   = min($totalPages, $startPage + $pageRange - 1);
                                if ($endPage - $startPage < $pageRange - 1) {
                                    $startPage = max(1, $endPage - $pageRange + 1);
                                }
                                for ($p = $startPage; $p <= $endPage; $p++):
                            ?>
                                <a href="?page=<?= $p ?>"
                                    class="pagination__item <?= $p === $currentPageNum ? 'active' : '' ?>">
                                    <?= $p ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($currentPageNum < $totalPages): ?>
                                <a href="?page=<?= $currentPageNum + 1 ?>" class="pagination__item pagination__item--next">다음</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.content--case-list {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.case-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 24px 28px;
    border: 1px solid #e7eef8;
    border-radius: 18px;
    background:
        radial-gradient(circle at top right, rgba(37, 99, 235, 0.07), transparent 28%),
        linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.05);
}

.case-hero__text {
    max-width: 760px;
}

.case-hero__eyebrow {
    display: inline-block;
    margin-bottom: 10px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #eef4ff;
    color: #2563EB;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.case-hero__title {
    margin: 0;
    color: #0f172a;
    font-size: 1.45rem;
    line-height: 1.45;
    font-weight: 800;
}

.case-hero__desc {
    margin: 12px 0 0;
    color: #64748b;
    line-height: 1.65;
    font-size: 0.94rem;
}

.case-hero__button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 148px;
    height: 42px;
    border-radius: 12px;
    padding: 0 18px;
    text-align: center;
    background: linear-gradient(180deg, #ffffff 0%, #f4f7fb 100%) !important;
    color: #1e293b !important;
    border: 1px solid #dbe3ef !important;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06) !important;
}

.case-hero__button:hover {
    background: #eef4ff !important;
    color: #1d4ed8 !important;
    border-color: #c9dafc !important;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.08) !important;
}

.case-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.case-limit-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    border: 1px solid #dbe7ff;
    border-radius: 14px;
    background: linear-gradient(180deg, #fbfdff 0%, #f5f8ff 100%);
    color: #475569;
    font-size: 0.84rem;
    line-height: 1.7;
}

.case-limit-banner strong {
    color: #1d4ed8;
    font-weight: 800;
    flex-shrink: 0;
}

.case-stat-card {
    padding: 16px 18px;
    border: 1px solid #e8edf5;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.03);
}

.case-stat-card__label {
    display: block;
    color: #64748b;
    font-size: 0.82rem;
    margin-bottom: 8px;
}

.case-stat-card__value {
    color: #0f172a;
    font-size: 1.35rem;
    font-weight: 800;
}

.case-table-panel {
    border: 1px solid #e8edf5;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.05);
    overflow: hidden;
}

.case-table-panel__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 22px;
    border-bottom: 1px solid #eef2f7;
    background: #fcfdff;
}

.case-table-panel__title {
    margin: 0;
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 800;
}

.case-table-panel__meta {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 0.86rem;
}

.case-table-panel__chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 30px;
    padding: 0 10px;
    border: 1px solid #dbe7ff;
    border-radius: 999px;
    background: #f5f9ff;
    color: #3157b8;
    font-size: 0.76rem;
    font-weight: 700;
}

.case-table {
    margin: 0;
}

.case-table thead th {
    background: #fafcff;
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 700;
    border-bottom: 1px solid #eef2f7;
}

.case-table tbody td {
    vertical-align: middle;
    border-bottom: 1px solid #f5f7fb;
    padding-top: 16px;
    padding-bottom: 16px;
}

.case-table__row:hover td {
    background: #fcfdff;
}

.case-table__title-link {
    color: #0f172a;
    font-weight: 700;
    line-height: 1.5;
    text-decoration: none;
}

.case-table__title-link:hover {
    color: #2563EB;
}

.case-table__subtext {
    display: inline-block;
    margin-top: 6px;
    color: #94a3b8;
    font-size: 0.8rem;
}

.badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 0.71rem;
    font-weight: 700;
    letter-spacing: 0.01em;
}
.badge--done    { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge--error   { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
.badge--pending { background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; }

.case-type-tag {
    display: inline-block;
    background: #f8fafc;
    color: #334155;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 5px 9px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}

.case-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
    flex-wrap: nowrap;
}

.case-action-link {
    appearance: none;
    border: 0;
    background: transparent;
    padding: 0;
    margin: 0;
    color: #475569;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    text-decoration: none;
}

.case-action-link:hover {
    color: #2563EB;
}

.case-action-link--danger {
    color: #dc2626;
}

.case-action-link--danger:hover {
    color: #b91c1c;
}

.case-action-divider {
    width: 1px;
    height: 12px;
    background: #dbe3ef;
}

.case-pagination {
    padding-top: 8px;
}

@media (max-width: 1024px) {
    .case-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .case-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<form id="deleteCaseItemForm" method="post" action="case_delete.php" style="display:none;">
    <input type="hidden" name="case_id" id="deleteCaseId" value="">
</form>

<script>
function deleteCase(caseId) {
    if (!confirm('이 사례를 삭제할까요?\n삭제된 사례는 복구할 수 없습니다.')) return;
    document.getElementById('deleteCaseId').value = caseId;
    document.getElementById('deleteCaseItemForm').submit();
}
</script>

<?php include '../footer.inc.php'; ?>
