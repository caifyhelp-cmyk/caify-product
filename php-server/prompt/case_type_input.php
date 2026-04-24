<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

require_login();

$type = trim((string)($_GET['type'] ?? ''));
$typeMap = [
    'problem_solve' => [
        'title' => '문제 해결 사례',
        'q1' => '[문제] 어떤 문제가 있었나요?',
        'q2' => '[해결] 해당 문제를 어떻게 해결했나요?',
        'q3' => '[결과] 문제 해결을 통해 어떤 변화가 생겼나요?',
    ],
    'process_work' => [
        'title' => '작업/진행 과정 사례',
        'q1' => '[대상] 어떤 작업(프로젝트)이었나요?',
        'q2' => '[핵심 단계] 어떤 과정/순서로 진행하셨나요?',
        'q3' => '[완료] 최종 결과는 어떻게 되었나요?',
    ],
    'consulting_qa' => [
        'title' => '상담/문의 사례',
        'q1' => '[질문] 고객이 문의한 내용은 무엇인가요?',
        'q2' => '[답변] 문의한 내용에 대해 어떻게 답했나요?',
        'q3' => '[결과] 상담 후 고객의 반응은 어땠나요?',
    ],
    'review_experience' => [
        'title' => '고객 경험/후기',
        'q1' => '[만족 포인트] 고객이 만족한 제품/서비스는 무엇인가요?',
        'q2' => '[고객 반응] 고객의 반응 또는 후기를 상세히 적어주세요.',
        'q3' => '[강조] 후기를 통해 고객에게 어필하고 싶은 점은 무엇인가요?',
    ],
];

if (!isset($typeMap[$type])) {
    header('Location: case_type_select.php');
    exit;
}

$tpl = $typeMap[$type];
$currentPage = 'bg_page';
?>
<?php include '../header.inc.php'; ?>

<main class="main">
    <div class="container">
        <?php include '../inc/snb.inc.php'; ?>
        <div class="content__wrap">
            <div class="content__inner">
                <div class="content__header">
                    <h2 class="content__header__title">유형별 사례 입력</h2>
                </div>
                <div class="content">
                    <form id="typedCaseForm" method="post" action="case_short.php" class="typed-form">
                        <input type="hidden" name="case_input_type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="raw_content" id="rawContentHidden" value="">

                        <section class="typed-panel">
                            <h3><?= htmlspecialchars($tpl['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p>아래 3개 항목을 입력하고 확인을 누르면 기존 사례 작성 화면으로 넘어가며, 이후 흐름은 동일합니다.</p>
                        </section>

                        <table class="table--prompt">
                            <tr>
                                <th>사례명 <span class="required--blue">*</span></th>
                                <td><input type="text" class="input--text" name="case_title" id="caseTitle" required></td>
                            </tr>
                            <tr>
                                <th><?= htmlspecialchars($tpl['q1'], ENT_QUOTES, 'UTF-8') ?> <span class="required--blue">*</span></th>
                                <td><textarea class="input--text" id="q1" rows="4" required></textarea></td>
                            </tr>
                            <tr>
                                <th><?= htmlspecialchars($tpl['q2'], ENT_QUOTES, 'UTF-8') ?> <span class="required--blue">*</span></th>
                                <td><textarea class="input--text" id="q2" rows="4" required></textarea></td>
                            </tr>
                            <tr>
                                <th><?= htmlspecialchars($tpl['q3'], ENT_QUOTES, 'UTF-8') ?> <span class="required--blue">*</span></th>
                                <td><textarea class="input--text" id="q3" rows="4" required></textarea></td>
                            </tr>
                        </table>

                        <div class="button__wrap">
                            <a href="case_type_select.php" class="btn">이전</a>
                            <button type="submit" class="btn btn--primary">확인하고 계속 작성</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    var form = document.getElementById('typedCaseForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        var q1 = document.getElementById('q1');
        var q2 = document.getElementById('q2');
        var q3 = document.getElementById('q3');
        var lines = [
            '유형: <?= htmlspecialchars($tpl['title'], ENT_QUOTES, 'UTF-8') ?>',
            '',
            '<?= htmlspecialchars($tpl['q1'], ENT_QUOTES, 'UTF-8') ?>',
            (q1 && q1.value ? q1.value.trim() : ''),
            '',
            '<?= htmlspecialchars($tpl['q2'], ENT_QUOTES, 'UTF-8') ?>',
            (q2 && q2.value ? q2.value.trim() : ''),
            '',
            '<?= htmlspecialchars($tpl['q3'], ENT_QUOTES, 'UTF-8') ?>',
            (q3 && q3.value ? q3.value.trim() : '')
        ];
        var body = lines.join('\n');
        if (!body.trim()) {
            e.preventDefault();
            alert('사례 내용을 입력해주세요.');
            return;
        }
        document.getElementById('rawContentHidden').value = body;
    });
})();
</script>

<?php include '../footer.inc.php'; ?>
