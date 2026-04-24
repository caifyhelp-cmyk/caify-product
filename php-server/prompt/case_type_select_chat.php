<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();
$memberPk = (int)($member['id'] ?? 0);

$hasPrompt = false;
$promptHash = '';

if ($memberPk > 0) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT brand_name, product_name, industry, goal FROM caify_prompt WHERE member_pk = :member_pk LIMIT 1');
    $stmt->execute([':member_pk' => $memberPk]);
    $promptRow = $stmt->fetch();
    if (is_array($promptRow) && trim((string)($promptRow['industry'] ?? '')) !== '') {
        $hasPrompt = true;
        $promptHash = md5(json_encode($promptRow));
    }
}

$currentPage = 'bg_page';
?>
<?php include '../header.inc.php'; ?>
<main class="main">
    <div class="container">
        <?php include '../inc/snb.inc.php'; ?>
        <div class="content__wrap">
            <div class="content__inner">
                <div class="content__header">
                    <h2 class="content__header__title">상담형 사례 유형 선택</h2>
                </div>
                <div class="content content--type-select">
                    <p class="ts-guide">유형을 먼저 고르면, 이후에는 채팅 상담형 질문으로 사례를 정리합니다.</p>

                    <form method="get" action="case_short_typed_chat_.php" id="typeSelectForm">
                        <div class="ts-grid">
                            <?php
                            $types = [
                                'problem_solve' => ['badge' => '결과', 'title' => '문제 해결 사례', 'flow' => 'Before -> After', 'desc' => '고객의 문제를 해결한 결과를 중심으로 정리합니다.'],
                                'process_work' => ['badge' => '과정', 'title' => '진행 과정 사례', 'flow' => 'A -> B -> C -> D', 'desc' => '프로젝트나 작업의 진행 과정을 순서대로 보여줍니다.'],
                                'consulting_qa' => ['badge' => '상담', 'title' => '상담/문의 사례', 'flow' => 'Q -> A -> Result', 'desc' => '상담 내용과 고객 질문을 중심으로 사례화합니다.'],
                                'review_experience' => ['badge' => '후기', 'title' => '고객 경험 사례', 'flow' => 'Review -> Point', 'desc' => '상품이나 서비스를 이용한 고객 후기를 정리합니다.'],
                            ];
                            $first = true;
                            foreach ($types as $key => $info):
                            ?>
                            <label class="ts-item">
                                <input type="radio" name="type" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" required <?= $first ? 'checked' : '' ?>>
                                <div class="ts-item__inner">
                                    <div class="ts-item__head">
                                        <span class="ts-item__badge"><?= htmlspecialchars($info['badge'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="ts-item__text">
                                        <div class="ts-item__title-row">
                                            <strong><?= htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span class="ts-item__flow">(<?= htmlspecialchars($info['flow'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                        </div>
                                        <span class="ts-item__desc" data-type-key="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="ts-item__example-box">
                                            <span class="ts-item__example-label">ex)</span>
                                            <span class="ts-item__example" data-type-example="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"></span>
                                        </div>
                                    </div>
                                </div>
                            </label>
                            <?php $first = false; endforeach; ?>
                        </div>

                        <div class="ts-actions">
                            <a href="case_list.php" class="ts-actions__back">목록으로</a>
                            <button type="submit" class="ts-actions__go">상담 시작</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.content--type-select { max-width: 980px; }
.ts-guide { margin: 0 0 22px; color: #555; font-size: 0.94rem; line-height: 1.7; }
.ts-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-bottom: 26px; align-items: stretch; }
.ts-item { cursor: pointer; display: block; height: 100%; }
.ts-item input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.ts-item__inner {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 14px;
    height: 100%;
    min-height: 248px;
    padding: 20px 22px 18px;
    border: 1px solid #d9d9d9;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 2px 10px rgba(15, 23, 42, .03);
    transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease;
}
.ts-item:hover .ts-item__inner {
    border-color: #b8c4d3;
    box-shadow: 0 12px 28px rgba(15, 23, 42, .06);
    transform: translateY(-1px);
}
.ts-item:has(input:checked) .ts-item__inner {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, .12), 0 12px 28px rgba(37, 99, 235, .08);
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}
.ts-item__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ts-item__badge {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 0 10px;
    border-radius: 4px;
    background: #eaf2fb;
    color: #2563eb;
    font-size: .74rem;
    font-weight: 800;
    letter-spacing: .03em;
}
.ts-item__text { display: flex; flex-direction: column; gap: 12px; flex: 1 1 auto; min-height: 0; }
.ts-item__title-row { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.ts-item__text strong { color: #111827; font-size: 1.08rem; font-weight: 800; line-height: 1.35; }
.ts-item__flow { color: #6b7280; font-size: .88rem; font-weight: 700; line-height: 1.4; }
.ts-item__desc {
    display: -webkit-box;
    overflow: hidden;
    min-height: 56px;
    color: #1d4ed8;
    font-size: .95rem;
    font-weight: 700;
    line-height: 1.6;
    transition: opacity .3s ease;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.ts-item__desc.is-loading { color: #94a3b8; }
.ts-item__example-box {
    display: grid;
    grid-template-columns: 26px minmax(0, 1fr);
    gap: 8px;
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid #eceff3;
    min-height: 86px;
}
.ts-item__example-label {
    color: #777;
    font-size: .86rem;
    font-weight: 700;
    line-height: 1.6;
}
.ts-item__example {
    display: block;
    color: #7a7a7a;
    font-size: .84rem;
    line-height: 1.55;
    word-break: keep-all;
    overflow: hidden;
}
.ts-item__example.is-visible {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
}
.ts-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.ts-actions__back, .ts-actions__go { min-width: 140px; height: 44px; border-radius: 10px; font-size: .92rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
.ts-actions__back { border: 1px solid #d1d5db; color: #374151; background: #fff; }
.ts-actions__go { border: 0; color: #fff; background: #2563EB; cursor: pointer; }
@media (max-width: 768px) {
    .ts-grid { grid-template-columns: 1fr; }
    .ts-actions { flex-direction: column; align-items: stretch; }
    .ts-actions__back, .ts-actions__go { width: 100%; }
    .ts-item__inner { min-height: 220px; }
    .ts-item__desc { min-height: auto; }
    .ts-item__example-box { min-height: auto; }
}
</style>

<script>
(function () {
    var hasPrompt = <?= $hasPrompt ? 'true' : 'false' ?>;
    var promptHash = <?= json_encode($promptHash) ?>;
    var fallback = {
        problem_solve: { desc: '고객의 문제를 해결한 결과를 중심으로 보여주고 싶을 때 적합합니다.', example: '반복되던 증상이나 불편을 어떤 방식으로 해결했고, 이후 어떤 변화가 생겼는지 정리하는 사례' },
        process_work: { desc: '프로젝트나 시술, 작업 등을 과정 중심으로 보여주고 싶을 때 적합합니다.', example: '상담부터 준비, 진행, 완료까지 단계별 흐름이 중요한 작업 사례' },
        consulting_qa: { desc: '상담 내용이나 고객 질문을 중심으로 보여주고 싶을 때 적합합니다.', example: '실제 문의 배경과 답변 포인트, 상담 후 반응까지 담는 사례' },
        review_experience: { desc: '상품이나 서비스를 이용한 고객의 후기를 보여주고 싶을 때 적합합니다.', example: '고객이 실제로 만족한 포인트와 이용 후기를 자연스럽게 정리하는 사례' }
    };

    function applyDescriptions(data) {
        Object.keys(fallback).forEach(function (key) {
            var descEl = document.querySelector('[data-type-key="' + key + '"]');
            var exEl = document.querySelector('[data-type-example="' + key + '"]');
            var item = data && data[key] ? data[key] : fallback[key];
            if (descEl) {
                descEl.textContent = item.desc || fallback[key].desc;
                descEl.classList.remove('is-loading');
                descEl.title = item.desc || fallback[key].desc;
            }
            if (exEl) {
                exEl.textContent = item.example || fallback[key].example;
                exEl.classList.toggle('is-visible', !!exEl.textContent);
                exEl.title = item.example || fallback[key].example;
            }
        });
    }

    if (!hasPrompt || !promptHash) {
        applyDescriptions(fallback);
        return;
    }

    var cacheKey = 'caseTypeDescChat_' + promptHash;
    try {
        var cached = localStorage.getItem(cacheKey);
        if (cached) {
            var parsed = JSON.parse(cached);
            applyDescriptions(parsed);
            return;
        }
    } catch (e) {}

    document.querySelectorAll('.ts-item__desc').forEach(function (el) {
        el.classList.add('is-loading');
    });

    fetch('case_type_desc_ai.php', { method: 'POST' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success && json.data) {
                applyDescriptions(json.data);
                try { localStorage.setItem(cacheKey, JSON.stringify(json.data)); } catch (e) {}
            } else {
                applyDescriptions(fallback);
            }
        })
        .catch(function () {
            applyDescriptions(fallback);
        });
})();
</script>
<?php include '../footer.inc.php'; ?>
