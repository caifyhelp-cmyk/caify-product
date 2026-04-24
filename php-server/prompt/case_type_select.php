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
                    <h2 class="content__header__title">사례 유형 선택</h2>
                </div>
                <div class="content content--type-select">
                    <p class="ts-guide">등록할 사례 유형을 선택하세요.</p>

                    <form method="get" action="case_short_typed.php" id="typeSelectForm">
                        <div class="ts-grid">
                            <label class="ts-item">
                                <input type="radio" name="type" value="problem_solve" required checked>
                                <div class="ts-item__inner">
                                    <span class="ts-item__num">1</span>
                                    <div class="ts-item__text">
                                        <strong>문제 해결 사례</strong>
                                        <span class="ts-item__flow">문제 → 해결 → 결과</span>
                                        <span class="ts-item__desc" data-type-key="problem_solve">고객의 문제를 진단하고 해결한 과정을 기록할 때 사용하세요.</span>
                                        <span class="ts-item__example" data-type-example="problem_solve"></span>
                                    </div>
                                </div>
                            </label>
                            <label class="ts-item">
                                <input type="radio" name="type" value="process_work" required>
                                <div class="ts-item__inner">
                                    <span class="ts-item__num">2</span>
                                    <div class="ts-item__text">
                                        <strong>작업/진행 과정 사례</strong>
                                        <span class="ts-item__flow">대상 → 핵심 단계 → 완료</span>
                                        <span class="ts-item__desc" data-type-key="process_work">작업이나 프로젝트의 진행 과정을 단계별로 보여줄 때 사용하세요.</span>
                                        <span class="ts-item__example" data-type-example="process_work"></span>
                                    </div>
                                </div>
                            </label>
                            <label class="ts-item">
                                <input type="radio" name="type" value="consulting_qa" required>
                                <div class="ts-item__inner">
                                    <span class="ts-item__num">3</span>
                                    <div class="ts-item__text">
                                        <strong>상담/문의 사례</strong>
                                        <span class="ts-item__flow">질문 → 답변 → 결과</span>
                                        <span class="ts-item__desc" data-type-key="consulting_qa">고객의 문의에 전문적으로 답변한 상담 내용을 기록할 때 사용하세요.</span>
                                        <span class="ts-item__example" data-type-example="consulting_qa"></span>
                                    </div>
                                </div>
                            </label>
                            <label class="ts-item">
                                <input type="radio" name="type" value="review_experience" required>
                                <div class="ts-item__inner">
                                    <span class="ts-item__num">4</span>
                                    <div class="ts-item__text">
                                        <strong>고객 경험/후기</strong>
                                        <span class="ts-item__flow">만족 → 반응 → 강조</span>
                                        <span class="ts-item__desc" data-type-key="review_experience">서비스를 이용한 고객의 만족 경험을 후기 형태로 정리할 때 사용하세요.</span>
                                        <span class="ts-item__example" data-type-example="review_experience"></span>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div class="ts-actions">
                            <a href="case_list.php" class="ts-actions__back">목록으로</a>
                            <button type="submit" class="ts-actions__go">다음</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.content--type-select {
    max-width: 900px;
}

.ts-guide {
    margin: 0 0 18px;
    color: #555;
    font-size: 0.92rem;
}

.ts-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.ts-item {
    cursor: pointer;
}

.ts-item input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.ts-item__inner {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    padding: 22px 20px 20px;
    border: 1px solid #ddd;
    border-radius: 12px;
    background: #fff;
    transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
    min-height: 160px;
}

.ts-item:hover .ts-item__inner {
    border-color: #aaa;
}

.ts-item:has(input:checked) .ts-item__inner {
    border-color: #2563EB;
    background: #f7faff;
    box-shadow: 0 0 0 1px #2563EB;
}

.ts-item__num {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #f0f0f0;
    color: #666;
    font-size: 0.82rem;
    font-weight: 700;
    transition: background 0.15s, color 0.15s;
}

.ts-item:has(input:checked) .ts-item__num {
    background: #2563EB;
    color: #fff;
}

.ts-item__text {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ts-item__text strong {
    color: #222;
    font-size: 0.92rem;
    font-weight: 700;
    line-height: 1.35;
}

.ts-item__flow {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    background: #f3f4f6;
    color: #6b7280;
    font-size: 0.74rem;
    font-weight: 600;
    line-height: 1.4;
}

.ts-item:has(input:checked) .ts-item__flow {
    background: #dbeafe;
    color: #1d4ed8;
}

.ts-item__desc {
    display: block;
    margin-top: 4px;
    color: #555;
    font-size: 0.82rem;
    line-height: 1.65;
    transition: opacity 0.3s ease;
}

.ts-item__desc.is-loading {
    color: #94a3b8;
}

.ts-item__example {
    display: none;
    margin-top: 2px;
    padding: 6px 10px;
    border-radius: 8px;
    background: #f0f5ff;
    color: #1e40af;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1.5;
}

.ts-item__example.is-visible {
    display: inline-block;
}

.ts-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ts-actions__back {
    padding: 9px 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fff;
    color: #555;
    font-size: 0.88rem;
    font-weight: 600;
    text-decoration: none;
}

.ts-actions__back:hover {
    background: #f8f8f8;
}

.ts-actions__go {
    min-width: 80px;
    padding: 9px 24px;
    border: none;
    border-radius: 8px;
    background: #2563EB;
    color: #fff;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
}

.ts-actions__go:hover {
    background: #1d4ed8;
}

@media (max-width: 640px) {
    .ts-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
(function () {
    var hasPrompt = <?= $hasPrompt ? 'true' : 'false' ?>;
    var promptHash = <?= json_encode($promptHash) ?>;

    if (!hasPrompt) return;

    var CACHE_KEY = 'caseTypeDesc_' + promptHash;

    function applyDescriptions(data) {
        if (!data || typeof data !== 'object') return;
        var types = ['problem_solve', 'process_work', 'consulting_qa', 'review_experience'];
        types.forEach(function (key) {
            var info = data[key];
            if (!info) return;

            var descEl = document.querySelector('[data-type-key="' + key + '"]');
            var exEl = document.querySelector('[data-type-example="' + key + '"]');

            if (descEl && info.desc) {
                descEl.textContent = info.desc;
                descEl.classList.remove('is-loading');
            }
            if (exEl && info.example) {
                exEl.textContent = '예) ' + info.example;
                exEl.classList.add('is-visible');
            }
        });
    }

    var cached = null;
    try {
        var raw = localStorage.getItem(CACHE_KEY);
        if (raw) cached = JSON.parse(raw);
    } catch (e) {}

    if (cached) {
        applyDescriptions(cached);
        return;
    }

    var descEls = document.querySelectorAll('.ts-item__desc');
    descEls.forEach(function (el) {
        el.classList.add('is-loading');
        el.textContent = '맞춤 설명을 불러오는 중...';
    });

    fetch('case_type_desc_ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: '{}'
    })
    .then(function (res) { return res.json(); })
    .then(function (json) {
        if (json.success && json.data) {
            applyDescriptions(json.data);
            try {
                localStorage.setItem(CACHE_KEY, JSON.stringify(json.data));
            } catch (e) {}
        } else {
            descEls.forEach(function (el) {
                el.classList.remove('is-loading');
                var key = el.getAttribute('data-type-key');
                var fallback = {
                    problem_solve: '고객의 문제를 진단하고 해결한 과정을 기록할 때 사용하세요.',
                    process_work: '작업이나 프로젝트의 진행 과정을 단계별로 보여줄 때 사용하세요.',
                    consulting_qa: '고객의 문의에 전문적으로 답변한 상담 내용을 기록할 때 사용하세요.',
                    review_experience: '서비스를 이용한 고객의 만족 경험을 후기 형태로 정리할 때 사용하세요.'
                };
                el.textContent = fallback[key] || '';
            });
        }
    })
    .catch(function () {
        descEls.forEach(function (el) {
            el.classList.remove('is-loading');
        });
    });
})();
</script>

<?php include '../footer.inc.php'; ?>
