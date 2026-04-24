<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();

$memberPk = (int)($member['id'] ?? 0);

$TYPE_CONFIG = [
    'problem_solve' => [
        'label' => '문제 해결 사례',
        'q1' => '[문제] 어떤 문제가 있었나요?',
        'q2' => '[해결] 해당 문제를 어떻게 해결했나요?',
        'q3' => '[결과] 문제 해결을 통해 어떤 변화가 생겼나요?',
    ],
    'process_work' => [
        'label' => '작업/진행 과정 사례',
        'q1' => '[대상] 어떤 작업(프로젝트)이었나요?',
        'q2' => '[핵심 단계] 어떤 과정/순서로 진행하셨나요?',
        'q3' => '[완료] 최종 결과는 어떻게 되었나요?',
    ],
    'consulting_qa' => [
        'label' => '상담/문의 사례',
        'q1' => '[질문] 고객이 문의한 내용은 무엇인가요?',
        'q2' => '[답변] 문의한 내용에 대해 어떻게 답했나요?',
        'q3' => '[결과] 상담 후 고객의 반응은 어땠나요?',
    ],
    'review_experience' => [
        'label' => '고객 경험/후기',
        'q1' => '[만족 포인트] 고객이 만족한 제품/서비스는 무엇인가요?',
        'q2' => '[고객 반응] 고객의 반응 또는 후기를 상세히 적어주세요.',
        'q3' => '[강조] 후기를 통해 고객에게 어필하고싶은 점은 무엇인가요?',
    ],
];

$caseRow = null;
$caseId = 0;
$caseFiles = [];
$caseFileMetaMap = [];

$editId = (int)($_GET['id'] ?? 0);
$type = trim((string)($_GET['type'] ?? ''));

if ($editId > 0 && $memberPk > 0) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM caify_case WHERE id = :id AND member_pk = :member_pk LIMIT 1');
    $stmt->execute([':id' => $editId, ':member_pk' => $memberPk]);
    $caseRow = $stmt->fetch();

    if (is_array($caseRow) && !empty($caseRow['id'])) {
        $caseId = (int)$caseRow['id'];
        $f = $pdo->prepare('SELECT * FROM caify_case_file WHERE case_id = :case_id AND member_pk = :member_pk ORDER BY id DESC');
        $f->execute([':case_id' => $caseId, ':member_pk' => $memberPk]);
        $caseFiles = $f->fetchAll();

        $fileIds = array_column($caseFiles ?: [], 'id');
        if (count($fileIds) > 0) {
            $in = implode(',', array_fill(0, count($fileIds), '?'));
            $m = $pdo->prepare("SELECT * FROM caify_case_file_meta WHERE file_id IN ($in)");
            $m->execute($fileIds);
            foreach ($m->fetchAll() as $metaRow) {
                if (!is_array($metaRow)) {
                    continue;
                }
                $caseFileMetaMap[(int)($metaRow['file_id'] ?? 0)] = json_decode((string)($metaRow['meta_json'] ?? '{}'), true) ?: [];
            }
        }
    } else {
        $caseRow = null;
    }
}

$structured = [];
if (is_array($caseRow) && !empty($caseRow['ai_structured_json'])) {
    $decoded = json_decode((string)$caseRow['ai_structured_json'], true);
    if (is_array($decoded)) {
        $structured = $decoded;
    }
}

if ($type === '' && !empty($structured['input_case_type'])) {
    $type = trim((string)$structured['input_case_type']);
}

if (!isset($TYPE_CONFIG[$type])) {
    header('Location: case_type_select.php');
    exit;
}

$typeCfg = $TYPE_CONFIG[$type];

$examples = [];
$examplesJson = '[]';

$typedForm = [];
if (is_array($structured['typed_form'] ?? null)) {
    $typedForm = $structured['typed_form'];
}
$typed_q1 = trim((string)($typedForm['q1'] ?? ''));
$typed_q2 = trim((string)($typedForm['q2'] ?? ''));
$typed_q3 = trim((string)($typedForm['q3'] ?? ''));

$legacyRawParts = [];
if (is_array($caseRow)) {
    $legacyMap = [
        '사례 유형' => (string)($caseRow['case_type'] ?? ''),
        '고객/대상' => trim(implode(' / ', array_filter([
            (string)($caseRow['customer_name'] ?? ''),
            (string)($caseRow['customer_info'] ?? ''),
        ]))),
        '서비스명' => (string)($caseRow['service_name'] ?? ''),
        '진행 시기' => (string)($caseRow['service_period'] ?? ''),
        '이용 전 상황' => (string)($caseRow['before_situation'] ?? ''),
        '진행 과정' => (string)($caseRow['case_process'] ?? ''),
        '결과/변화' => (string)($caseRow['after_result'] ?? ''),
        '추가 메모' => (string)($caseRow['case_content'] ?? ''),
    ];
    foreach ($legacyMap as $label => $value) {
        $value = trim($value);
        if ($value !== '') {
            $legacyRawParts[] = $label . ': ' . $value;
        }
    }
}

$case_title = is_array($caseRow) ? (string)($caseRow['case_title'] ?? '') : '';
if ($case_title === '' && is_array($caseRow)) {
    $case_title = (string)($caseRow['ai_title'] ?? ($caseRow['service_name'] ?? ''));
}

$raw_content = is_array($caseRow) ? (string)($caseRow['raw_content'] ?? '') : '';
if ($raw_content === '' && count($legacyRawParts) > 0) {
    $raw_content = implode("\n\n", $legacyRawParts);
}

$ai_title = is_array($caseRow) ? (string)($caseRow['ai_title'] ?? '') : '';
$ai_summary = is_array($caseRow) ? (string)($caseRow['ai_summary'] ?? '') : '';
$ai_body_draft = is_array($caseRow) ? (string)($caseRow['ai_body_draft'] ?? '') : '';
$ai_status = is_array($caseRow) ? (string)($caseRow['ai_status'] ?? 'pending') : 'pending';

if (count($structured) === 0 && is_array($caseRow)) {
    $structured = [
        'case_category' => (string)($caseRow['case_type'] ?? ''),
        'subject_label' => trim(implode(' / ', array_filter([
            (string)($caseRow['customer_name'] ?? ''),
            (string)($caseRow['customer_info'] ?? ''),
        ]))),
        'problem_summary' => (string)($caseRow['before_situation'] ?? ''),
        'process_summary' => (string)($caseRow['case_process'] ?? ''),
        'result_summary' => (string)($caseRow['after_result'] ?? ''),
    ];
}

$savedImageLayout = [];
if (is_array($structured['image_layout'] ?? null)) {
    $savedImageLayout = $structured['image_layout'];
}

$ai_case_category = (string)($structured['case_category'] ?? '');
$ai_industry_category = (string)($structured['industry_category'] ?? '');
$ai_subject_label = (string)($structured['subject_label'] ?? '');
$ai_problem_summary = (string)($structured['problem_summary'] ?? '');
$ai_process_summary = (string)($structured['process_summary'] ?? '');
$ai_result_summary = (string)($structured['result_summary'] ?? '');

$ai_h2_sections = [];
if (is_array($caseRow) && !empty($caseRow['ai_h2_sections'])) {
    $decoded = json_decode((string)$caseRow['ai_h2_sections'], true);
    if (is_array($decoded)) {
        $ai_h2_sections = $decoded;
    }
}

$hasAiResult = ($ai_status === 'done' && ($ai_title !== '' || $ai_case_category !== '' || $ai_body_draft !== '' || count(array_filter($ai_h2_sections)) > 0));

$caseImageLibrary = [];
foreach ($caseFiles as $fileRow) {
    if (!is_array($fileRow)) {
        continue;
    }

    $fileId = (int)($fileRow['id'] ?? 0);
    if ($fileId <= 0) {
        continue;
    }

    $meta = $caseFileMetaMap[$fileId] ?? [];
    $description = trim((string)($meta['description'] ?? ''));
    $subjectPrimary = trim((string)($meta['subject']['primary'] ?? ''));
    $sceneType = trim((string)($meta['scene']['scene_type'] ?? ''));
    $visualRole = trim((string)($meta['scene']['visual_role'] ?? ''));
    $moodLabel = trim((string)($meta['mood']['mood'] ?? ''));
    $subtitleCandidate = trim((string)($meta['audio_text']['subtitle_candidate'] ?? ''));
    $keywords = is_array($meta['keywords'] ?? null) ? array_values($meta['keywords']) : [];

    $summaryParts = array_filter([
        $subjectPrimary,
        $sceneType,
        $visualRole,
        $moodLabel,
    ], static function ($value): bool {
        return trim((string)$value) !== '';
    });

    $caseImageLibrary[] = [
        'id' => $fileId,
        'name' => (string)($fileRow['original_name'] ?? 'image'),
        'url' => '../' . ltrim((string)($fileRow['stored_path'] ?? ''), '/'),
        'summary' => count($summaryParts) > 0 ? implode(' / ', $summaryParts) : '이미지 메타 요약 없음',
        'description' => $description,
        'subtitle_candidate' => $subtitleCandidate,
        'keywords' => $keywords,
    ];
}

$caseImageLibraryJson = json_encode($caseImageLibrary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($caseImageLibraryJson === false) {
    $caseImageLibraryJson = '[]';
}

$savedImageLayoutJson = json_encode($savedImageLayout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($savedImageLayoutJson === false) {
    $savedImageLayoutJson = '{}';
}

$saved_keywords = '';
if (is_string($structured['target_keywords'] ?? null)) {
    $saved_keywords = trim((string)$structured['target_keywords']);
}

$saved_title_candidates = [];
if (is_array($structured['title_candidates'] ?? null)) {
    $saved_title_candidates = $structured['title_candidates'];
}
$titleCandidatesJson = json_encode($saved_title_candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($titleCandidatesJson === false) {
    $titleCandidatesJson = '[]';
}

$promptHash = '';
if ($memberPk > 0) {
    $pdoForPrompt = isset($pdo) && $pdo instanceof PDO ? $pdo : db();
    $pStmt = $pdoForPrompt->prepare('SELECT brand_name, product_name, industry, goal FROM caify_prompt WHERE member_pk = :member_pk LIMIT 1');
    $pStmt->execute([':member_pk' => $memberPk]);
    $pRow = $pStmt->fetch();
    if (is_array($pRow) && trim((string)($pRow['industry'] ?? '')) !== '') {
        $promptHash = md5(json_encode($pRow));
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
                    <h2 class="content__header__title"><?= htmlspecialchars($typeCfg['label'], ENT_QUOTES, 'UTF-8') ?> 등록</h2>
                </div>
                <div class="content content--case-form">
                    <section class="case-form-hero">
                        <div class="case-form-hero__text">
                            <span class="case-form-hero__eyebrow">AI Case Studio</span>
                            <h3 class="case-form-hero__title">사례 입력부터 구조화, 제목 후보, 본문 초안까지 AI가 한 번에 정리합니다</h3>
                            <p class="case-form-hero__desc">
                                유형별 질문에 답하면 AI가 사례를 읽기 쉬운 블로그 포맷으로 재구성합니다. 입력 내용은 구조화되고, 이미지 메타와 함께 연결되어 실제 발행 전 단계의 초안처럼 검토할 수 있습니다.
                            </p>
                        </div>
                        <div class="case-form-hero__side">
                            <div class="case-form-hero__meta">
                                <span class="case-form-hero__meta-item"><?= $caseId > 0 ? '수정 모드' : '새 사례 작성' ?></span>
                                <span class="case-form-hero__meta-item"><?= htmlspecialchars($typeCfg['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="case-form-hero__meta-item">AI Writing Flow</span>
                            </div>
                            <div class="case-form-hero__panel">
                                <div class="case-form-hero__panel-item">
                                    <strong>Input</strong>
                                    <span>유형별 질문 3개만 작성</span>
                                </div>
                                <div class="case-form-hero__panel-item">
                                    <strong>Analyze</strong>
                                    <span>요약, 제목 후보, H2 구조 생성</span>
                                </div>
                                <div class="case-form-hero__panel-item">
                                    <strong>Preview</strong>
                                    <span>블로그처럼 본문과 이미지 흐름 확인</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="case-progress-bar" id="caseProgressBar">
                        <div class="case-progress-bar__steps">
                            <div class="case-progress-step" data-step="title">
                                <span class="case-progress-step__num">1</span>
                                <span class="case-progress-step__label">사례명</span>
                            </div>
                            <div class="case-progress-step__line"></div>
                            <div class="case-progress-step" data-step="content">
                                <span class="case-progress-step__num">2</span>
                                <span class="case-progress-step__label">사례 내용</span>
                            </div>
                            <div class="case-progress-step__line"></div>
                            <div class="case-progress-step" data-step="image">
                                <span class="case-progress-step__num">3</span>
                                <span class="case-progress-step__label">이미지</span>
                            </div>
                            <div class="case-progress-step__line"></div>
                            <div class="case-progress-step" data-step="analyze">
                                <span class="case-progress-step__num">4</span>
                                <span class="case-progress-step__label">AI 분석</span>
                            </div>
                            <div class="case-progress-step__line"></div>
                            <div class="case-progress-step" data-step="draft">
                                <span class="case-progress-step__num">5</span>
                                <span class="case-progress-step__label">본문 초안</span>
                            </div>
                            <div class="case-progress-step__line"></div>
                            <div class="case-progress-step" data-step="save">
                                <span class="case-progress-step__num">6</span>
                                <span class="case-progress-step__label">저장</span>
                            </div>
                        </div>
                    </div>

                    <form id="caseForm" method="post" action="case_typed_submit.php" enctype="multipart/form-data">
                        <?php if ($caseId > 0): ?>
                            <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">
                        <?php endif; ?>
                        <input type="hidden" name="case_input_type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="typedFormJson" name="typed_form_json" value="">
                        <input type="hidden" id="rawContent" name="raw_content" value="<?= htmlspecialchars($raw_content, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="titleCandidatesJson" name="title_candidates_json" value="<?= htmlspecialchars($titleCandidatesJson, ENT_QUOTES, 'UTF-8') ?>">

                        <section class="case-form-section">
                            <div class="case-form-section__head">
                                <h3 class="content__title">사례 입력</h3>
                                <p class="case-form-section__desc">사례명을 짧게 적고, 아래 유형별 3개 항목에 답변을 작성해주세요. AI가 자동으로 하나의 사례 원문으로 합쳐서 분석합니다.</p>
                            </div>
                            <?php if ($caseId <= 0 && $promptHash !== ''): ?>
                            <div class="example-bar" id="exampleBar">
                                <span class="example-bar__label">맞춤 작성 예시</span>
                                <div class="example-bar__list">
                                    <span class="example-bar__loading">불러오는 중...</span>
                                </div>
                            </div>
                            <?php endif; ?>
                            <table class="table--prompt case-form-table">
                                <tr>
                                    <th>사례명 <span class="required--blue">*</span></th>
                                    <td>
                                        <input
                                            type="text"
                                            class="input--text"
                                            id="caseTitle"
                                            name="case_title"
                                            required
                                            placeholder="예) 강아지 슬개골 수술 사례 / 법인전환 절세 사례 / 욕실 리모델링 사례"
                                            value="<?= htmlspecialchars($case_title, ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                        <p class="text--guide">사용자가 직접 보는 제목입니다. AI 제목과 별도로 저장됩니다.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>타겟 키워드</th>
                                    <td>
                                        <input
                                            type="text"
                                            class="input--text"
                                            id="targetKeywords"
                                            name="target_keywords"
                                            placeholder="예) 슬개골 수술, 강남 동물병원"
                                            value="<?= htmlspecialchars($saved_keywords, ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                        <p class="text--guide">AI가 블로그 제목과 본문에 이 키워드를 자연스럽게 포함시킵니다. 비워두면 사례명을 기반으로 자동 설정됩니다.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>사례 내용 <span class="required--blue">*</span></th>
                                    <td>
                                        <div class="typed-case-block">
                                            <div class="typed-case-item">
                                                <label for="typedQ1"><?= htmlspecialchars($typeCfg['q1'], ENT_QUOTES, 'UTF-8') ?></label>
                                                <textarea id="typedQ1" class="input--text case-textarea" rows="5" required placeholder="답변을 입력하세요"><?= htmlspecialchars($typed_q1, ENT_QUOTES, 'UTF-8') ?></textarea>
                                            </div>
                                            <div class="typed-case-item">
                                                <label for="typedQ2"><?= htmlspecialchars($typeCfg['q2'], ENT_QUOTES, 'UTF-8') ?></label>
                                                <textarea id="typedQ2" class="input--text case-textarea" rows="5" required placeholder="답변을 입력하세요"><?= htmlspecialchars($typed_q2, ENT_QUOTES, 'UTF-8') ?></textarea>
                                            </div>
                                            <div class="typed-case-item">
                                                <label for="typedQ3"><?= htmlspecialchars($typeCfg['q3'], ENT_QUOTES, 'UTF-8') ?></label>
                                                <textarea id="typedQ3" class="input--text case-textarea" rows="5" required placeholder="답변을 입력하세요"><?= htmlspecialchars($typed_q3, ENT_QUOTES, 'UTF-8') ?></textarea>
                                            </div>
                                            <p class="text--guide">유형별 3개 항목을 작성하면 AI 분석 시 자동으로 하나의 사례 원문으로 합쳐집니다.</p>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </section>

                        <section class="case-form-section">
                            <div class="case-form-section__head">
                                <h3 class="content__title">이미지 첨부</h3>
                                <p class="case-form-section__desc">사례와 관련된 사진을 함께 저장할 수 있습니다. 이미지를 먼저 저장하면 AI가 본문 초안 생성 시 각 섹션에 어울리는 이미지를 자동으로 배치합니다.</p>
                            </div>
                            <table class="table--prompt case-form-table">
                                <tr>
                                    <th>이미지 업로드</th>
                                    <td>
                                        <div class="file__upload__wrap">
                                            <div class="file__upload__area" data-dropzone="case-images">
                                                <p class="text--guide text--center">
                                                    첨부할 이미지를 여기에 끌어다 놓거나,<br>
                                                    파일 선택 버튼을 눌러 직접 선택해 주세요.
                                                    <input type="file" id="caseImagesInput" name="case_images[]" accept="image/*" multiple class="mgT10">
                                                </p>
                                            </div>
                                        </div>
                                        <div class="case-upload-ai-note" id="caseUploadAiNote">
                                            <div class="case-upload-ai-note__badge">AI Image Reading</div>
                                            <div class="case-upload-ai-note__text">
                                                <strong>저장 후 자동 분석</strong>
                                                <span id="caseUploadAiNoteText">새 이미지를 첨부하면 저장 단계에서 AI가 이미지 설명과 키워드를 함께 생성합니다.</span>
                                            </div>
                                        </div>
                                        <div class="file__list__wrap">
                                            <?php
                                                $imageCount = is_array($caseFiles) ? count($caseFiles) : 0;
                                                $imageShowLimit = 8;
                                            ?>
                                            <div class="file__list__actions">
                                                <span class="text--small" style="color:#666;">총 <b><?= (int)$imageCount ?></b>개</span>
                                                <?php if ($imageCount > $imageShowLimit): ?>
                                                    <button type="button" class="btn btn--small" data-toggle-list="caseImageFileList" data-limit="<?= (int)$imageShowLimit ?>">더보기</button>
                                                <?php endif; ?>
                                            </div>
                                            <ul class="file__list filegrid" id="caseImageFileList">
                                                <?php if (is_array($caseFiles) && count($caseFiles) > 0): ?>
                                                    <?php $imgIdx = 0; ?>
                                                    <?php foreach ($caseFiles as $ff): ?>
                                                        <?php if (!is_array($ff)) continue; ?>
                                                        <?php $imgIdx++; $imgHidden = ($imgIdx > $imageShowLimit) ? ' is-hidden' : ''; ?>
                                                        <li class="file__list__item<?= $imgHidden ?>">
                                                            <a class="file__thumb" href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                                                                <img src="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="첨부 이미지">
                                                            </a>
                                                            <div class="file__meta">
                                                                <a class="file__name" href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" title="<?= htmlspecialchars((string)($ff['original_name'] ?? 'image'), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <?= htmlspecialchars((string)($ff['original_name'] ?? 'image'), ENT_QUOTES, 'UTF-8') ?>
                                                                </a>
                                                                <button type="submit" form="deleteCaseFileForm" name="file_id" value="<?= (int)($ff['id'] ?? 0) ?>" class="button--delete" onclick="return confirm('이 파일을 삭제할까요?');" style="background:none;border:0;cursor:pointer;">
                                                                    <img src="../images/x.svg" alt="삭제">
                                                                </button>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <li class="file__list__item"><span>첨부된 이미지가 없습니다.</span></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </section>

                        <div class="ai-analyze-wrap">
                            <button type="button" id="btnAnalyze" class="btn btn--primary btn--ai">
                                <span class="btn-text">AI로 분류하고 블로그 구조 만들기</span>
                                <span class="btn-loading" style="display:none;">분석 중...</span>
                            </button>
                            <p class="text--guide mgT10">유형별 입력 내용을 바탕으로 AI가 사례 분류와 블로그 초안을 동시에 생성합니다. 이미지를 먼저 저장하면 본문 초안에 이미지 배치도 함께 추천됩니다.</p>
                        </div>

                        <div id="aiResultWrap" class="ai-result-wrap" style="<?= $hasAiResult ? '' : 'display:none;' ?>">
                            <div class="case-form-section__head">
                                <h3 class="content__title">AI 분석 결과</h3>
                                <p class="case-form-section__desc">AI가 원문을 읽고 구조화한 분류 결과입니다. 필요하면 직접 수정할 수 있습니다.</p>
                            </div>

                            <div class="ai-grid">
                                <input type="hidden" id="aiIndustryCategory" name="ai_industry_category" value="<?= htmlspecialchars($ai_industry_category, ENT_QUOTES, 'UTF-8') ?>">
                                <section class="ai-card">
                                    <h4 class="ai-card__title">분류 결과</h4>
                                    <div class="ai-field-list">
                                        <div class="ai-field-item">
                                            <label class="ai-field-item__label" for="aiCaseCategory">사례 유형</label>
                                            <input type="text" class="input--text" id="aiCaseCategory" name="ai_case_category" value="<?= htmlspecialchars($ai_case_category, ENT_QUOTES, 'UTF-8') ?>" placeholder="AI가 판단한 사례 유형">
                                        </div>
                                        <div class="ai-field-item">
                                            <label class="ai-field-item__label" for="aiSubjectLabel">대상 요약</label>
                                            <input type="text" class="input--text" id="aiSubjectLabel" name="ai_subject_label" value="<?= htmlspecialchars($ai_subject_label, ENT_QUOTES, 'UTF-8') ?>" placeholder="고객/환자/의뢰인 요약">
                                        </div>
                                        <div class="ai-field-item">
                                            <label class="ai-field-item__label" for="aiProblemSummary">문제 상황 요약</label>
                                            <textarea class="input--text case-textarea" id="aiProblemSummary" name="ai_problem_summary" rows="4" placeholder="원문에서 파악한 문제 상황"><?= htmlspecialchars($ai_problem_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                        <div class="ai-field-item">
                                            <label class="ai-field-item__label" for="aiProcessSummary">진행 과정 요약</label>
                                            <textarea class="input--text case-textarea" id="aiProcessSummary" name="ai_process_summary" rows="4" placeholder="원문에서 파악한 진행 과정"><?= htmlspecialchars($ai_process_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                        <div class="ai-field-item">
                                            <label class="ai-field-item__label" for="aiResultSummary">결과 요약</label>
                                            <textarea class="input--text case-textarea" id="aiResultSummary" name="ai_result_summary" rows="4" placeholder="원문에서 파악한 결과/변화"><?= htmlspecialchars($ai_result_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                    </div>
                                </section>

                                <section class="ai-card">
                                    <h4 class="ai-card__title">블로그 초안</h4>
                                    <table class="table--prompt case-form-table">
                                        <tr>
                                            <th>블로그 제목</th>
                                            <td>
                                                <input type="text" class="input--text" id="aiTitle" name="ai_title" placeholder="AI가 생성한 블로그 제목" value="<?= htmlspecialchars($ai_title, ENT_QUOTES, 'UTF-8') ?>">
                                                <div id="titleCandidatesWrap" class="title-candidates-wrap" style="<?= count($saved_title_candidates) > 0 ? '' : 'display:none;' ?>">
                                                    <span class="title-candidates__label">AI 추천 제목 — 클릭하면 위 입력란에 반영됩니다</span>
                                                    <div id="titleCandidatesList" class="title-candidates__list">
                                                        <?php foreach ($saved_title_candidates as $ci => $ct): ?>
                                                            <button type="button" class="title-candidate-btn<?= ($ct === $ai_title) ? ' is-selected' : '' ?>" data-candidate-idx="<?= (int)$ci ?>"><?= htmlspecialchars((string)$ct, ENT_QUOTES, 'UTF-8') ?></button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>요약 (서머리)</th>
                                            <td>
                                                <textarea id="aiSummary" name="ai_summary" class="input--text case-textarea" rows="5" placeholder="AI가 생성한 요약"><?= htmlspecialchars($ai_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>H2 소제목</th>
                                            <td>
                                                <div id="h2SectionsWrap" class="h2-sections-wrap">
                                                    <?php for ($i = 0; $i < 6; $i++): ?>
                                                        <div class="h2-section-item">
                                                            <span class="h2-section-label">H2 <?= $i + 1 ?></span>
                                                            <input type="text" class="input--text" name="ai_h2[]" placeholder="소제목 <?= $i + 1 ?>" value="<?= htmlspecialchars($ai_h2_sections[$i] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                        </div>
                                                    <?php endfor; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr class="case-form-table__fullhead">
                                            <th colspan="2">본문 초안</th>
                                        </tr>
                                        <tr class="case-form-table__fullbody">
                                            <td colspan="2">
                                                <div class="draft-editor-wrap">
                                                    <div class="draft-actions">
                                                        <button type="button" id="btnGenerateDraft" class="btn draft-generate-btn">본문 초안 생성</button>
                                                        <span class="text--guide">제목, 요약, H2를 바탕으로 전체 본문 초안을 생성합니다. 저장된 이미지 메타가 있으면 섹션별 추천도 함께 붙습니다.</span>
                                                    </div>
                                                    <textarea id="aiBodyDraft" name="ai_body_draft" class="input--text case-textarea case-textarea--draft" rows="18" placeholder="여기에 AI가 생성한 본문 초안이 표시됩니다."><?= htmlspecialchars($ai_body_draft, ENT_QUOTES, 'UTF-8') ?></textarea>
                                                </div>
                                                <input type="hidden" id="aiImageLayoutJson" name="ai_image_layout_json" value="<?= htmlspecialchars($savedImageLayoutJson, ENT_QUOTES, 'UTF-8') ?>">
                                                <div id="draftImagePlacementWrap" class="draft-image-placement-wrap">
                                                    <div class="draft-image-placement__head">
                                                        <div>
                                                            <h5 class="draft-image-placement__title">본문 이미지 배치</h5>
                                                            <p class="draft-image-placement__desc">AI가 도입부와 각 H2에 어울리는 이미지를 최대 1~2장 추천합니다. 남은 이미지는 오른쪽 보관함에서 드래그해서 원하는 섹션으로 옮길 수 있습니다.</p>
                                                        </div>
                                                        <span class="draft-image-placement__badge">AI + Drag Drop</span>
                                                    </div>
                                                    <div class="draft-image-placement__grid">
                                                        <div id="draftImagePlacementSlots" class="draft-image-placement__slots"></div>
                                                        <aside class="draft-image-placement__bank">
                                                            <div class="draft-image-placement__bank-head">
                                                                <strong>이미지 보관함</strong>
                                                                <span id="draftImageBankCount">0장 대기</span>
                                                            </div>
                                                            <div id="draftImagePlacementBank" class="draft-image-placement__bank-drop"></div>
                                                            <p class="draft-image-placement__bank-note">새로 올린 이미지는 저장 후 메타 분석이 끝나야 여기 추천 대상으로 반영됩니다.</p>
                                                        </aside>
                                                    </div>
                                                </div>
                                                <div id="draftImagePreviewWrap" class="draft-image-preview-wrap">
                                                    <div class="draft-image-preview__head">
                                                        <div>
                                                            <h5 class="draft-image-preview__title">본문 미리보기</h5>
                                                            <p class="draft-image-preview__desc">현재 본문 초안과 이미지 배치 기준으로, 도입부와 각 H2 아래에 이미지가 어떻게 들어가는지 미리 확인할 수 있습니다.</p>
                                                        </div>
                                                        <span class="draft-image-preview__badge">Blog Style Preview</span>
                                                    </div>
                                                    <div id="draftImagePreview" class="draft-image-preview__body"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </section>
                            </div>
                            <input type="hidden" name="ai_status" id="aiStatusInput" value="<?= htmlspecialchars($ai_status, ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="button__wrap case-form-actions">
                            <a href="case_list.php" class="btn case-form-actions__secondary">취소</a>
                            <button type="submit" id="caseSubmitButton" class="btn btn--primary case-form-actions__primary">
                                <span class="case-submit-label">저장하기</span>
                                <span class="case-submit-loading" style="display:none;">저장 중...</span>
                            </button>
                        </div>
                    </form>

                    <form id="deleteCaseFileForm" method="post" action="case_file_delete.php" style="display:none;">
                        <?php if ($caseId > 0): ?>
                            <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">
                        <?php endif; ?>
                    </form>

                    <div id="aiPreviewModal" class="ai-preview-modal" aria-hidden="true">
                        <div class="ai-preview-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="aiPreviewModalTitle">
                            <div class="ai-preview-modal__header">
                                <div>
                                    <span class="ai-preview-modal__eyebrow">AI Writing Preview</span>
                                    <h3 id="aiPreviewModalTitle" class="ai-preview-modal__title">AI가 결과를 정리하고 있습니다</h3>
                                </div>
                                <button type="button" id="aiPreviewClose" class="ai-preview-modal__close">닫기</button>
                            </div>
                            <div id="aiPreviewBody" class="ai-preview-modal__body"></div>
                            <div class="ai-preview-modal__footer">
                                <button type="button" id="aiPreviewConfirm" class="btn draft-generate-btn">확인하고 편집하기</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.content--case-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.content__inner {
    background:
        radial-gradient(circle at top right, rgba(56, 189, 248, 0.08), transparent 24%),
        radial-gradient(circle at left top, rgba(99, 102, 241, 0.07), transparent 22%);
}

.case-form-hero {
    display: flex;
    align-items: stretch;
    justify-content: space-between;
    gap: 20px;
    padding: 28px 30px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 24px;
    background:
        radial-gradient(circle at top right, rgba(34, 211, 238, 0.22), transparent 28%),
        radial-gradient(circle at bottom left, rgba(59, 130, 246, 0.18), transparent 26%),
        linear-gradient(135deg, #0f172a 0%, #131c31 46%, #1e293b 100%);
    box-shadow: 0 28px 60px rgba(15, 23, 42, 0.18);
}

.case-form-hero__text {
    max-width: 760px;
}

.case-form-hero__eyebrow {
    display: inline-block;
    margin-bottom: 10px;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.14);
    color: #7dd3fc;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.case-form-hero__title {
    margin: 0;
    color: #f8fafc;
    font-size: 1.58rem;
    line-height: 1.45;
    font-weight: 800;
}

.case-form-hero__desc {
    margin: 12px 0 0;
    color: #cbd5e1;
    line-height: 1.7;
    font-size: 0.96rem;
}

.case-form-hero__side {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-width: 300px;
    gap: 14px;
}

.case-form-hero__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
}

.case-form-hero__meta-item {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 10px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.06);
    color: #dbeafe;
    font-size: 0.76rem;
    font-weight: 700;
}

.case-form-hero__panel {
    display: grid;
    gap: 10px;
}

.case-form-hero__panel-item {
    padding: 14px 16px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 16px;
    background: rgba(15, 23, 42, 0.28);
    backdrop-filter: blur(10px);
}

.case-form-hero__panel-item strong {
    display: block;
    color: #f8fafc;
    font-size: 0.88rem;
    font-weight: 800;
    letter-spacing: 0.01em;
}

.case-form-hero__panel-item span {
    display: block;
    margin-top: 6px;
    color: #cbd5e1;
    font-size: 0.83rem;
    line-height: 1.6;
}

.case-form-section {
    padding: 24px 24px;
    border: 1px solid #e2e8f0;
    border-radius: 22px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.98) 100%);
    box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
}

.case-form-section__head {
    margin-bottom: 16px;
}

.case-form-section__head .content__title {
    margin-bottom: 6px;
    color: #0f172a;
    font-size: 1.08rem;
    font-weight: 800;
}

.case-form-section__desc {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.65;
}

.case-form-table {
    margin: 0;
}

.case-form-table th {
    color: #334155;
    background: #f8fbff;
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1.6;
    letter-spacing: -0.01em;
    vertical-align: top;
}

.case-form-table td {
    background: #fff;
    font-size: 0.92rem;
    line-height: 1.7;
}

.case-form-table .input--text,
.ai-card .input--text {
    width: 100%;
    min-height: 46px;
    padding: 11px 14px;
    box-sizing: border-box;
    font-size: 0.92rem;
    line-height: 1.7;
    color: #1e293b;
    border-radius: 12px;
    border: 1px solid #dbe3ef;
    background: #fff;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.02);
}

.case-form-table textarea.input--text,
.ai-card textarea.input--text {
    min-height: 120px;
}

.case-form-table .text--guide,
.ai-card .text--guide,
.draft-actions .text--guide {
    display: block;
    margin-top: 8px;
    color: #64748b;
    font-size: 0.82rem;
    line-height: 1.65;
}

.case-textarea {
    resize: vertical;
    min-height: 100px;
    line-height: 1.75;
    font-size: 0.875rem;
    font-family: inherit;
    border: 0;
    outline: none;
    appearance: none;
    width: 100%;
    padding: 10px 12px;
    box-sizing: border-box;
    background: transparent;
}

.case-textarea--large {
    min-height: 280px;
}

.case-textarea--draft {
    min-height: 420px;
    margin-top: 12px;
    line-height: 1.85;
}

.example-bar {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 16px;
    padding: 18px 20px;
    border: 2px solid #2563EB;
    border-radius: 14px;
    background: #f0f5ff;
}

.example-bar__label {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border-radius: 8px;
    background: #2563EB;
    color: #fff;
    font-size: 0.82rem;
    font-weight: 700;
    white-space: nowrap;
}

.example-bar__label::before {
    content: "💡";
    font-size: 0.9rem;
}

.example-bar__list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding-top: 2px;
}

.example-bar__btn {
    padding: 7px 14px;
    border: 1px solid #c0d4f5;
    border-radius: 8px;
    background: #fff;
    color: #334155;
    font-size: 0.82rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
}

.example-bar__btn:hover {
    border-color: #2563EB;
    background: #e8f0fe;
    color: #1d4ed8;
}

.example-bar__loading {
    color: #94a3b8;
    font-size: 0.82rem;
    font-style: italic;
    padding: 7px 0;
}
.example-bar__retry-btn {
    background: #475569;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 5px 14px;
    font-size: 0.8rem;
    cursor: pointer;
    margin-left: 8px;
    transition: background 0.15s;
}
.example-bar__retry-btn:hover {
    background: #334155;
}

.example-bar__btn.is-active {
    border-color: #2563EB;
    background: #2563EB;
    color: #fff;
    font-weight: 700;
}

.typed-case-block {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.typed-case-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 14px;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

.typed-case-item label {
    color: #0f172a;
    font-size: 0.88rem;
    font-weight: 700;
    line-height: 1.5;
}

.typed-case-item textarea {
    min-height: 100px;
}

.ai-analyze-wrap {
    margin: 2px 0;
    padding: 24px 24px;
    background:
        radial-gradient(circle at top right, rgba(34, 211, 238, 0.12), transparent 20%),
        linear-gradient(135deg, #0f172a 0%, #162033 100%);
    border: 1px solid rgba(96, 165, 250, 0.18);
    border-radius: 22px;
    text-align: center;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.16);
}

.btn--ai {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    min-width: 270px;
    padding: 0 28px;
    border-radius: 12px;
    font-size: 0.96rem;
    letter-spacing: 0.02em;
    box-shadow: 0 18px 34px rgba(37, 99, 235, 0.24);
}

.ai-analyze-wrap .text--guide {
    color: #cbd5e1;
}

.btn--ai[disabled],
.draft-generate-btn[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
    pointer-events: none;
    box-shadow: none !important;
}

.draft-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.case-form-table__fullhead th {
    padding: 16px 18px;
    text-align: left;
}

.case-form-table__fullbody td {
    padding: 20px 18px 22px;
}

.draft-editor-wrap {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.draft-generate-btn {
    min-height: 40px;
    padding: 0 16px;
    border-radius: 10px;
    border: 1px solid #dbe3ef;
    background: linear-gradient(180deg, #ffffff 0%, #f5f8fc 100%);
    color: #1e293b;
    font-size:13px;
}

.draft-generate-btn:hover {
    border-color: #bfd5ff;
    background: #eef4ff;
    color: #1d4ed8;
}

.draft-image-placement-wrap {
    margin-top: 18px;
    padding: 18px;
    border: 1px solid #dbe7ff;
    border-radius: 18px;
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.08), transparent 24%),
        linear-gradient(180deg, #fbfdff 0%, #f5f8ff 100%);
}

.draft-image-placement__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
}

.draft-image-placement__title {
    margin: 0 0 6px;
    color: #0f172a;
    font-size: 0.98rem;
    font-weight: 800;
    line-height: 1.4;
}

.draft-image-placement__desc {
    margin: 0;
    color: #64748b;
    font-size: 0.83rem;
    line-height: 1.7;
}

.draft-image-placement__badge {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    padding: 0 12px;
    border-radius: 999px;
    background: #eef4ff;
    color: #2563eb;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    flex-shrink: 0;
}

.draft-image-placement__grid {
    display: grid;
    grid-template-columns: minmax(0, 2.2fr) minmax(340px, 1.1fr);
    gap: 18px;
}

.draft-image-placement__slots {
    display: grid;
    gap: 14px;
}

.draft-image-slot {
    padding: 14px;
    border: 1px solid #d8e4f5;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.96);
}

.draft-image-slot__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
}

.draft-image-slot__title {
    margin: 0;
    color: #0f172a;
    font-size: 0.9rem;
    font-weight: 800;
}

.draft-image-slot__meta {
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
}

.draft-image-slot__dropzone,
.draft-image-placement__bank-drop {
    min-height: 96px;
    border: 1px dashed #c7d6eb;
    border-radius: 14px;
    background: #f8fbff;
    padding: 10px;
    transition: border-color 0.16s ease, background 0.16s ease, transform 0.16s ease;
}

.draft-image-slot__dropzone.is-over,
.draft-image-placement__bank-drop.is-over {
    border-color: #60a5fa;
    background: #edf5ff;
    transform: translateY(-1px);
}

.draft-image-slot__cards,
.draft-image-placement__bank-cards {
    display: grid;
    gap: 10px;
}

.draft-image-slot__empty,
.draft-image-placement__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 74px;
    padding: 14px;
    border-radius: 12px;
    color: #94a3b8;
    font-size: 0.82rem;
    line-height: 1.6;
    text-align: center;
    background: rgba(255, 255, 255, 0.7);
}

.draft-image-card {
    display: grid;
    grid-template-columns: 76px minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 10px;
    border: 1px solid #dbe7ff;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
    cursor: grab;
}

.draft-image-card:active {
    cursor: grabbing;
}

.draft-image-card__thumb {
    width: 76px;
    height: 76px;
    border-radius: 12px;
    overflow: hidden;
    background: #eef2ff;
}

.draft-image-card__thumb img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.draft-image-card__body {
    min-width: 0;
}

.draft-image-card__name {
    display: block;
    margin-bottom: 4px;
    color: #0f172a;
    font-size: 0.85rem;
    font-weight: 800;
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.draft-image-card__summary {
    color: #475569;
    font-size: 0.79rem;
    line-height: 1.55;
}

.draft-image-card__keywords {
    display: block;
    margin-top: 5px;
    color: #2563eb;
    font-size: 0.74rem;
    line-height: 1.45;
}

.draft-image-card__action {
    min-height: 34px;
    padding: 0 10px;
    border: 1px solid #dbe3ef;
    border-radius: 10px;
    background: #fff;
    color: #475569;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
}

.draft-image-card__action:hover {
    border-color: #bfd5ff;
    background: #eef4ff;
    color: #1d4ed8;
}

.draft-image-placement__bank {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 14px;
    border: 1px solid #d8e4f5;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.94);
}

.draft-image-placement__bank-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    color: #334155;
    font-size: 0.82rem;
}

.draft-image-placement__bank-head strong {
    color: #0f172a;
    font-size: 0.9rem;
}

.draft-image-placement__bank-note {
    margin: 0;
    color: #64748b;
    font-size: 0.78rem;
    line-height: 1.6;
}

.draft-image-preview-wrap {
    margin-top: 18px;
    padding: 18px;
    border: 1px solid #dce7f6;
    border-radius: 18px;
    background:
        radial-gradient(circle at top left, rgba(34, 211, 238, 0.08), transparent 28%),
        linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

.draft-image-preview__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
}

.draft-image-preview__title {
    margin: 0 0 6px;
    color: #0f172a;
    font-size: 0.98rem;
    font-weight: 800;
    line-height: 1.4;
}

.draft-image-preview__desc {
    margin: 0;
    color: #64748b;
    font-size: 0.83rem;
    line-height: 1.7;
}

.draft-image-preview__badge {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    padding: 0 12px;
    border-radius: 999px;
    background: #ecfeff;
    color: #0f766e;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    flex-shrink: 0;
}

.draft-image-preview__body {
    padding: 0;
}

.draft-preview-blog {
    overflow: hidden;
    border: 1px solid #dbe5f3;
    border-radius: 28px;
    background: linear-gradient(180deg, #eef4ff 0%, #f8fbff 10%, #ffffff 28%);
    box-shadow: 0 26px 60px rgba(15, 23, 42, 0.09);
}

.draft-preview-blog__chrome {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 14px 18px;
    border-bottom: 1px solid #e2e8f0;
    background: rgba(255, 255, 255, 0.74);
}

.draft-preview-blog__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #cbd5e1;
}

.draft-preview-blog__dot.is-accent {
    background: linear-gradient(135deg, #38bdf8 0%, #2563eb 100%);
}

.draft-preview-blog__label {
    margin-left: 6px;
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.draft-preview-blog__content {
    padding: 30px 28px 32px;
}

.draft-preview-blog__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.draft-preview-blog__chip {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: #eef4ff;
    color: #1d4ed8;
    font-size: 0.75rem;
    font-weight: 700;
}

.draft-preview-blog__title {
    margin: 0;
    color: #0f172a;
    font-size: 1.8rem;
    font-weight: 800;
    line-height: 1.5;
    letter-spacing: -0.02em;
}

.draft-preview-blog__summary {
    margin: 14px 0 0;
    padding: 18px 20px;
    border: 1px solid #dce7fa;
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    color: #475569;
    font-size: 0.94rem;
    line-height: 1.85;
}

.draft-preview-blog__toc {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 18px 0 0;
}

.draft-preview-blog__toc-item {
    padding: 7px 12px;
    border-radius: 999px;
    background: #f8fafc;
    color: #475569;
    font-size: 0.78rem;
    font-weight: 700;
}

.draft-preview-blog__sections {
    display: grid;
    gap: 18px;
    margin-top: 26px;
}

.draft-preview-section {
    padding: 22px 22px;
    border: 1px solid #e2e8f0;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.98);
}

.draft-preview-section__title {
    margin: 8px 0 12px;
    color: #0f172a;
    font-size: 1.08rem;
    font-weight: 800;
    line-height: 1.45;
}

.draft-preview-section__eyebrow {
    display: inline-flex;
    align-items: center;
    min-height: 26px;
    padding: 0 10px;
    border-radius: 999px;
    background: #eff6ff;
    color: #2563eb;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.draft-preview-section__text {
    display: grid;
    gap: 10px;
}

.draft-preview-paragraph {
    margin: 0;
    color: #334155;
    font-size: 0.95rem;
    line-height: 1.92;
    word-break: break-word;
}

.draft-preview-section__images {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    margin-top: 18px;
}

.draft-preview-image {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px;
    border: 1px solid #d9e6fb;
    border-radius: 16px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
}

.draft-preview-image__thumb {
    width: 100%;
    height: 180px;
    border-radius: 14px;
    overflow: hidden;
    background: #eef2ff;
}

.draft-preview-image__thumb img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.draft-preview-image__name {
    color: #0f172a;
    font-size: 0.84rem;
    font-weight: 800;
    line-height: 1.45;
}

.draft-preview-image__summary {
    color: #475569;
    font-size: 0.78rem;
    line-height: 1.6;
}

.draft-preview-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 180px;
    padding: 20px;
    border: 1px dashed #cbd5e1;
    border-radius: 22px;
    background: linear-gradient(180deg, #fbfdff 0%, #f8fbff 100%);
    color: #94a3b8;
    font-size: 0.88rem;
    line-height: 1.8;
    text-align: center;
}

.ai-preview-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, 0.56);
    backdrop-filter: blur(6px);
    z-index: 10001;
}

.ai-preview-modal.is-open {
    display: flex;
}

.ai-preview-modal__dialog {
    width: min(960px, 100%);
    max-height: 86vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid rgba(219, 227, 239, 0.95);
    border-radius: 22px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    box-shadow: 0 28px 60px rgba(15, 23, 42, 0.24);
}

.ai-preview-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding: 22px 24px 18px;
    border-bottom: 1px solid #e9eef7;
}

.ai-preview-modal__eyebrow {
    display: inline-block;
    margin-bottom: 8px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #eef4ff;
    color: #2563EB;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.ai-preview-modal__title {
    margin: 0;
    color: #0f172a;
    font-size: 1.08rem;
    font-weight: 800;
    line-height: 1.45;
}

.ai-preview-modal__close {
    min-height: 38px;
    padding: 0 14px;
    border: 1px solid #dbe3ef;
    border-radius: 10px;
    background: #fff;
    color: #475569;
    cursor: pointer;
    font-size: 0.86rem;
    font-weight: 700;
}

.ai-preview-modal__close:hover {
    border-color: #bfd5ff;
    color: #1d4ed8;
    background: #eef4ff;
}

.ai-preview-modal__body {
    overflow-y: auto;
    padding: 22px 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.ai-preview-section {
    padding: 16px 18px;
    border: 1px solid #e6edf7;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.92);
}

.ai-preview-section__title {
    margin: 0 0 10px;
    color: #0f172a;
    font-size: 0.95rem;
    font-weight: 800;
}

.ai-preview-section__content {
    min-height: 34px;
    color: #334155;
    font-size: 0.9rem;
    line-height: 1.8;
    white-space: pre-wrap;
    word-break: break-word;
}

.ai-preview-section__content.is-typing::after {
    content: "";
    display: inline-block;
    width: 8px;
    height: 1.1em;
    margin-left: 4px;
    vertical-align: -0.15em;
    background: #2563EB;
    animation: aiPreviewCaret 0.8s steps(1) infinite;
}

@keyframes aiPreviewCaret {
    50% { opacity: 0; }
}

.ai-preview-modal__footer {
    display: flex;
    justify-content: flex-end;
    padding: 16px 24px 22px;
    border-top: 1px solid #e9eef7;
}

.ai-result-wrap {
    margin-top: 2px;
    padding: 24px 24px;
    background:
        radial-gradient(circle at top right, rgba(34, 211, 238, 0.12), transparent 24%),
        linear-gradient(180deg, #fbfeff 0%, #f8fbff 100%);
    border: 1px solid #d8ebff;
    border-radius: 24px;
    box-shadow: 0 20px 40px rgba(34, 211, 238, 0.05);
    animation: fadeIn 0.4s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

.ai-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.ai-card {
    padding: 20px;
    border: 1px solid #dbe5f3;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04);
}

.ai-card__title {
    margin: 0 0 14px;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.45;
}

.ai-field-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.ai-field-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ai-field-item__label {
    color: #334155;
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1.5;
}

.h2-sections-wrap {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.h2-section-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.h2-section-label {
    min-width: 42px;
    padding: 5px 9px;
    border: 1px solid #d6e4ff;
    border-radius: 999px;
    background: #eef4ff;
    color: #2563EB;
    font-size: 0.75rem;
    font-weight: 700;
    text-align: center;
    flex-shrink: 0;
}

.h2-section-item .input--text {
    flex: 1;
}

.file__upload__area[data-dropzone="case-images"] {
    padding: 18px;
    border: 1px dashed #c7d6eb;
    border-radius: 16px;
    background: linear-gradient(180deg, #fcfdff 0%, #f7faff 100%);
}

.file__upload__area[data-dropzone="case-images"].is-dragover {
    border-color: #7aa2ff;
    background: #f1f6ff;
}

.case-upload-ai-note {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: 14px;
    padding: 14px 16px;
    border: 1px solid #dbe7ff;
    border-radius: 16px;
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.12), transparent 28%),
        linear-gradient(180deg, #fbfdff 0%, #f3f7ff 100%);
}

.case-upload-ai-note__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 118px;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.1);
    color: #1d4ed8;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    flex-shrink: 0;
}

.case-upload-ai-note__text {
    display: flex;
    flex-direction: column;
    gap: 4px;
    color: #475569;
    font-size: 0.86rem;
    line-height: 1.65;
}

.case-upload-ai-note__text strong {
    color: #0f172a;
    font-size: 0.9rem;
}

.case-form-actions {
    margin-top: 4px;
    justify-content: flex-end;
    gap: 10px;
}

.case-form-actions__secondary {
    background: #fff !important;
    color: #334155 !important;
    border: 1px solid #dbe3ef !important;
}

.case-form-actions__secondary:hover {
    background: #f8fafc !important;
}

.case-form-actions__primary {
    min-width: 120px;
    border-radius: 12px;
}

.case-form-actions__primary[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
    pointer-events: none;
}

.case-form-actions__primary.is-submitting {
    pointer-events: none;
    opacity: 0.92;
}

.ai-loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
}

.ai-loading-spinner {
    width: 52px;
    height: 52px;
    border: 5px solid rgba(255, 255, 255, 0.25);
    border-top-color: #22D3EE;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

.ai-loading-text {
    color: #fff;
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

body.ai-preview-open {
    overflow: hidden;
}

body.case-submit-open {
    overflow: hidden;
}

.case-submit-overlay {
    position: fixed;
    inset: 0;
    z-index: 10020;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(2, 6, 23, 0.68);
    backdrop-filter: blur(10px);
}

.case-submit-overlay__dialog {
    width: min(560px, 100%);
    padding: 26px 24px 22px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 24px;
    background:
        radial-gradient(circle at top right, rgba(34, 211, 238, 0.16), transparent 30%),
        radial-gradient(circle at bottom left, rgba(37, 99, 235, 0.22), transparent 34%),
        linear-gradient(180deg, rgba(15, 23, 42, 0.96) 0%, rgba(17, 24, 39, 0.98) 100%);
    box-shadow: 0 30px 80px rgba(2, 6, 23, 0.45);
    color: #e2e8f0;
}

.case-submit-overlay__eyebrow {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    padding: 0 12px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.14);
    color: #93c5fd;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.case-submit-overlay__title {
    margin: 16px 0 8px;
    color: #f8fafc;
    font-size: 1.18rem;
    font-weight: 800;
    line-height: 1.45;
}

.case-submit-overlay__desc {
    margin: 0;
    color: #cbd5e1;
    font-size: 0.92rem;
    line-height: 1.75;
}

.case-submit-overlay__highlight {
    color: #67e8f9;
    font-weight: 700;
}

.case-submit-overlay__visual {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 22px;
    padding: 18px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 18px;
    background: rgba(15, 23, 42, 0.46);
}

.case-submit-overlay__spinner {
    position: relative;
    width: 62px;
    height: 62px;
    border-radius: 50%;
    flex-shrink: 0;
    background: conic-gradient(from 0deg, #22d3ee, #3b82f6, #818cf8, #22d3ee);
    animation: caseSubmitSpin 1.1s linear infinite;
}

.case-submit-overlay__spinner::before {
    content: "";
    position: absolute;
    inset: 7px;
    border-radius: 50%;
    background: #0f172a;
}

.case-submit-overlay__visual-copy {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.case-submit-overlay__visual-copy strong {
    color: #f8fafc;
    font-size: 0.97rem;
}

.case-submit-overlay__visual-copy span {
    color: #94a3b8;
    font-size: 0.82rem;
    line-height: 1.65;
}

.case-submit-overlay__steps {
    display: grid;
    gap: 10px;
    margin: 18px 0 0;
    padding: 0;
    list-style: none;
}

.case-submit-overlay__step {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: 14px;
    background: rgba(15, 23, 42, 0.38);
}

.case-submit-overlay__step-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #22d3ee;
    box-shadow: 0 0 0 6px rgba(34, 211, 238, 0.12);
    flex-shrink: 0;
    animation: caseSubmitPulse 1.2s ease-in-out infinite;
}

.case-submit-overlay__step-text {
    color: #dbeafe;
    font-size: 0.86rem;
    line-height: 1.6;
}

@keyframes caseSubmitSpin {
    to { transform: rotate(360deg); }
}

@keyframes caseSubmitPulse {
    0%, 100% { transform: scale(0.9); opacity: 0.75; }
    50% { transform: scale(1.15); opacity: 1; }
}

@media (max-width: 1024px) {
    .case-form-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .case-form-hero__side {
        width: 100%;
        min-width: 0;
    }

    .case-form-hero__meta {
        justify-content: flex-start;
    }

    .ai-preview-modal {
        padding: 12px;
    }

    .ai-preview-modal__header,
    .ai-preview-modal__body,
    .ai-preview-modal__footer {
        padding-left: 16px;
        padding-right: 16px;
    }

    .case-upload-ai-note {
        flex-direction: column;
        align-items: flex-start;
    }

    .case-submit-overlay__visual {
        align-items: flex-start;
    }

    .draft-image-placement__grid {
        grid-template-columns: 1fr;
    }

    .draft-preview-blog__content {
        padding: 24px 20px 24px;
    }

    .draft-image-placement__head,
    .draft-image-slot__head,
    .draft-image-placement__bank-head,
    .draft-image-preview__head {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 640px) {
    .case-form-hero {
        padding: 24px 20px;
    }

    .case-form-section,
    .ai-result-wrap,
    .ai-analyze-wrap {
        padding-left: 18px;
        padding-right: 18px;
    }

    .draft-image-card {
        grid-template-columns: 1fr;
    }

    .draft-image-card__thumb {
        width: 100%;
        height: 180px;
    }

    .draft-preview-blog__title {
        font-size: 1.34rem;
    }
}

.case-progress-bar {
    padding: 20px 24px;
    border: 1px solid #e8edf5;
    border-radius: 16px;
    background: #fff;
}

.case-progress-bar__steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
}

.case-progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.case-progress-step__num {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 2px solid #cbd5e1;
    background: #f8fafc;
    color: #94a3b8;
    font-size: 0.82rem;
    font-weight: 800;
    transition: all 0.25s ease;
}

.case-progress-step__label {
    color: #94a3b8;
    font-size: 0.76rem;
    font-weight: 600;
    white-space: nowrap;
    transition: color 0.25s ease;
}

.case-progress-step__line {
    flex: 1;
    min-width: 40px;
    max-width: 80px;
    height: 2px;
    background: #e2e8f0;
    margin: 0 6px;
    margin-bottom: 28px;
    border-radius: 2px;
    transition: background 0.25s ease;
}

.case-progress-step.is-done .case-progress-step__num {
    border-color: #22c55e;
    background: #22c55e;
    color: #fff;
}

.case-progress-step.is-done .case-progress-step__label {
    color: #16a34a;
}

.case-progress-step.is-active .case-progress-step__num {
    border-color: #2563eb;
    background: #2563eb;
    color: #fff;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
}

.case-progress-step.is-active .case-progress-step__label {
    color: #2563eb;
    font-weight: 700;
}

.case-progress-step__line.is-done {
    background: #22c55e;
}

.title-candidates-wrap {
    margin-top: 12px;
}

.title-candidates__label {
    display: block;
    margin-bottom: 10px;
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 600;
}

.title-candidates__list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.title-candidate-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fafcff;
    color: #334155;
    font-size: 0.9rem;
    font-weight: 500;
    line-height: 1.6;
    text-align: left;
    cursor: pointer;
    transition: all 0.15s ease;
}

.title-candidate-btn:hover {
    border-color: #93c5fd;
    background: #eff6ff;
    color: #1e40af;
}

.title-candidate-btn.is-selected {
    border-color: #2563eb;
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 700;
    box-shadow: 0 0 0 1px #2563eb;
}

.title-candidate-btn::before {
    content: "";
    display: block;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 2px solid #cbd5e1;
    flex-shrink: 0;
    transition: all 0.15s ease;
}

.title-candidate-btn.is-selected::before {
    border-color: #2563eb;
    background: #2563eb;
    box-shadow: inset 0 0 0 3px #fff;
}

@media (max-width: 640px) {
    .case-progress-step__line {
        min-width: 20px;
    }

    .case-progress-step__label {
        font-size: 0.68rem;
    }

    .case-progress-step__num {
        width: 28px;
        height: 28px;
        font-size: 0.75rem;
    }
}
</style>

<script>
(function () {
    var caseId = <?= (int)$caseId ?>;
    var caseInputType = <?= json_encode($type, JSON_UNESCAPED_UNICODE) ?>;
    var caseImageAssets = <?= $caseImageLibraryJson ?>;
    var initialImageLayout = <?= $savedImageLayoutJson ?>;
    var caseExamples = <?= $examplesJson ?>;
    var savedTitleCandidates = <?= $titleCandidatesJson ?>;
    var promptHash = <?= json_encode($promptHash) ?>;

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function updateProgressBar() {
        var steps = [
            { key: 'title', check: function () { return (document.getElementById('caseTitle').value || '').trim() !== ''; } },
            { key: 'content', check: function () {
                var q1 = (document.getElementById('typedQ1').value || '').trim();
                var q2 = (document.getElementById('typedQ2').value || '').trim();
                var q3 = (document.getElementById('typedQ3').value || '').trim();
                return q1 !== '' && q2 !== '' && q3 !== '';
            }},
            { key: 'image', check: function () {
                var hasExisting = caseImageAssets.length > 0;
                var fileInput = document.getElementById('caseImagesInput');
                var hasNew = fileInput && fileInput.files && fileInput.files.length > 0;
                return hasExisting || hasNew;
            }},
            { key: 'analyze', check: function () { return (document.getElementById('aiTitle').value || '').trim() !== ''; } },
            { key: 'draft', check: function () { return (document.getElementById('aiBodyDraft').value || '').trim() !== ''; } },
            { key: 'save', check: function () { return false; } }
        ];

        var lastDone = -1;
        for (var i = 0; i < steps.length; i++) {
            if (steps[i].check()) lastDone = i;
            else break;
        }

        var stepEls = document.querySelectorAll('.case-progress-step');
        var lineEls = document.querySelectorAll('.case-progress-step__line');

        stepEls.forEach(function (el, idx) {
            el.classList.remove('is-done', 'is-active');
            if (idx <= lastDone) {
                el.classList.add('is-done');
            } else if (idx === lastDone + 1) {
                el.classList.add('is-active');
            }
        });

        lineEls.forEach(function (el, idx) {
            el.classList.remove('is-done');
            if (idx <= lastDone - 1) {
                el.classList.add('is-done');
            }
        });
    }

    var keywordManuallyEdited = false;
    var keywordInput = document.getElementById('targetKeywords');
    if (keywordInput) {
        if (keywordInput.value.trim() !== '') {
            keywordManuallyEdited = true;
        }
        keywordInput.addEventListener('input', function () {
            keywordManuallyEdited = true;
        });
    }

    function autoFillKeyword() {
        if (keywordManuallyEdited || !keywordInput) return;
        var title = (document.getElementById('caseTitle').value || '').trim();
        keywordInput.value = title;
    }

    var caseTitleForKeyword = document.getElementById('caseTitle');
    if (caseTitleForKeyword) {
        caseTitleForKeyword.addEventListener('input', function () {
            autoFillKeyword();
            updateProgressBar();
        });
    }

    function renderTitleCandidates(candidates) {
        var wrap = document.getElementById('titleCandidatesWrap');
        var list = document.getElementById('titleCandidatesList');
        var hiddenEl = document.getElementById('titleCandidatesJson');
        if (!wrap || !list) return;

        savedTitleCandidates = candidates || [];
        if (hiddenEl) hiddenEl.value = JSON.stringify(savedTitleCandidates);

        if (!Array.isArray(candidates) || candidates.length === 0) {
            wrap.style.display = 'none';
            return;
        }

        list.innerHTML = candidates.map(function (t, i) {
            return '<button type="button" class="title-candidate-btn" data-candidate-idx="' + i + '">' + escapeHtml(t) + '</button>';
        }).join('');

        wrap.style.display = '';

        var aiTitleEl = document.getElementById('aiTitle');
        if (aiTitleEl && candidates[0]) {
            aiTitleEl.value = candidates[0];
            list.querySelector('[data-candidate-idx="0"]').classList.add('is-selected');
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.title-candidate-btn') : null;
        if (!btn) return;
        var idx = parseInt(btn.getAttribute('data-candidate-idx'), 10);
        if (isNaN(idx) || !savedTitleCandidates[idx]) return;

        document.querySelectorAll('.title-candidate-btn').forEach(function (b) { b.classList.remove('is-selected'); });
        btn.classList.add('is-selected');
        document.getElementById('aiTitle').value = savedTitleCandidates[idx];
    });

    function buildRaw() {
        var q1 = (document.getElementById('typedQ1').value || '').trim();
        var q2 = (document.getElementById('typedQ2').value || '').trim();
        var q3 = (document.getElementById('typedQ3').value || '').trim();
        var rawEl = document.getElementById('rawContent');
        var formJsonEl = document.getElementById('typedFormJson');

        if (formJsonEl) {
            formJsonEl.value = JSON.stringify({ q1: q1, q2: q2, q3: q3 });
        }

        if (q1 === '' || q2 === '' || q3 === '') {
            if (rawEl) rawEl.value = '';
            return;
        }

        var label1El = document.querySelector('#typedQ1').closest('.typed-case-item').querySelector('label');
        var label2El = document.querySelector('#typedQ2').closest('.typed-case-item').querySelector('label');
        var label3El = document.querySelector('#typedQ3').closest('.typed-case-item').querySelector('label');
        var l1 = label1El ? label1El.textContent.trim() : '';
        var l2 = label2El ? label2El.textContent.trim() : '';
        var l3 = label3El ? label3El.textContent.trim() : '';

        var lines = [];
        if (l1) lines.push(l1);
        lines.push(q1);
        lines.push('');
        if (l2) lines.push(l2);
        lines.push(q2);
        lines.push('');
        if (l3) lines.push(l3);
        lines.push(q3);

        if (rawEl) rawEl.value = lines.join('\n');
    }

    document.getElementById('typedQ1').addEventListener('input', function () { buildRaw(); updateProgressBar(); });
    document.getElementById('typedQ2').addEventListener('input', function () { buildRaw(); updateProgressBar(); });
    document.getElementById('typedQ3').addEventListener('input', function () { buildRaw(); updateProgressBar(); });
    buildRaw();
    updateProgressBar();

    function bindExampleButtons() {
        document.querySelectorAll('.example-bar__btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-example-idx'), 10);
                var ex = caseExamples[idx];
                if (!ex) return;

                document.querySelectorAll('.example-bar__btn').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');

                document.getElementById('caseTitle').setAttribute('placeholder', ex.title);
                document.getElementById('typedQ1').setAttribute('placeholder', ex.q1);
                document.getElementById('typedQ2').setAttribute('placeholder', ex.q2);
                document.getElementById('typedQ3').setAttribute('placeholder', ex.q3);
            });
        });
    }
    bindExampleButtons();

    function applyPersonalizedExamples(newExamples) {
        if (!Array.isArray(newExamples) || newExamples.length === 0) return;
        caseExamples = newExamples;

        var listEl = document.querySelector('.example-bar__list');
        if (!listEl) return;

        listEl.innerHTML = newExamples.map(function (ex, i) {
            return '<button type="button" class="example-bar__btn" data-example-idx="' + i + '">' + escapeHtml(ex.title) + '</button>';
        }).join('');

        bindExampleButtons();

        var barLabel = document.querySelector('.example-bar__label');
        if (barLabel) barLabel.textContent = '맞춤 작성 예시';
    }

    function loadPersonalizedExamples() {
        var listEl = document.querySelector('.example-bar__list');
        if (!listEl) return;
        listEl.innerHTML = '<span class="example-bar__loading">맞춤 예시를 불러오는 중...</span>';

        fetch('case_type_examples_ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ case_type: caseInputType })
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            console.log('[맞춤 예시 응답]', json);
            if (json.success && Array.isArray(json.data) && json.data.length > 0) {
                applyPersonalizedExamples(json.data);
                try { localStorage.setItem(EX_CACHE_KEY, JSON.stringify(json.data)); } catch (e) {}
            } else {
                var errMsg = (json.error || '') === 'no_prompt' ? '프롬프트 정보가 없습니다. 프롬프트를 먼저 등록해주세요.' : '예시 생성에 실패했습니다.';
                console.error('[맞춤 예시 오류]', json.error || json.raw || '');
                showExampleError(errMsg);
            }
        })
        .catch(function (err) {
            console.error('[맞춤 예시 네트워크 오류]', err);
            showExampleError('네트워크 오류가 발생했습니다.');
        });
    }

    function showExampleError(msg) {
        var listEl = document.querySelector('.example-bar__list');
        if (!listEl) return;
        listEl.innerHTML = '<span class="example-bar__loading">' + escapeHtml(msg) + ' </span>' +
            '<button type="button" class="example-bar__btn example-bar__retry-btn" id="exampleRetryBtn">다시 시도</button>';
        var retryBtn = document.getElementById('exampleRetryBtn');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                loadPersonalizedExamples();
            });
        }
    }

    if (caseId <= 0 && promptHash !== '' && document.getElementById('exampleBar')) {
        var EX_CACHE_KEY = 'caseTypeExamples_' + promptHash + '_' + caseInputType;
        var cachedExamples = null;
        try {
            var raw = localStorage.getItem(EX_CACHE_KEY);
            if (raw) cachedExamples = JSON.parse(raw);
        } catch (e) {}

        if (cachedExamples && Array.isArray(cachedExamples) && cachedExamples.length > 0) {
            applyPersonalizedExamples(cachedExamples);
        } else {
            loadPersonalizedExamples();
        }
    }

    function setupDropzone(zone, input, list) {
        if (!zone || !input) return;

        function renderSelected() {
            if (!list) return;
            list.querySelectorAll(".file__list__item--new").forEach(function (el) { el.remove(); });
            var files = Array.prototype.slice.call(input.files || []);
            if (!files.length) return;
            var frag = document.createDocumentFragment();
            files.forEach(function (f) {
                var li = document.createElement("li");
                li.className = "file__list__item file__list__item--new";
                li.innerHTML = '<div class="file__meta"><span class="file__name" title="' + escapeHtml(f.name) + '">[NEW] ' + escapeHtml(f.name) + '</span></div>';
                frag.appendChild(li);
            });
            list.prepend(frag);
            updateImageSelectionState();
        }

        input.addEventListener("change", function () { renderSelected(); updateProgressBar(); });
        zone.addEventListener("click", function (e) {
            if (e.target && e.target.closest && e.target.closest('input[type="file"]')) return;
            input.click();
        });

        ["dragenter", "dragover"].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                zone.classList.add("is-dragover");
            });
        });

        ["dragleave", "drop"].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                zone.classList.remove("is-dragover");
            });
        });

        zone.addEventListener("drop", function (e) {
            var dropped = (e.dataTransfer && e.dataTransfer.files) ? Array.prototype.slice.call(e.dataTransfer.files) : [];
            if (!dropped.length) return;
            var dt = new DataTransfer();
            Array.prototype.slice.call(input.files || []).forEach(function (f) { dt.items.add(f); });
            dropped.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
            renderSelected();
        });

        renderSelected();
    }

    function updateImageSelectionState() {
        var fileInput = document.getElementById('caseImagesInput');
        var noteText = document.getElementById('caseUploadAiNoteText');
        if (!noteText || !fileInput) return;

        var count = fileInput.files ? fileInput.files.length : 0;
        if (count > 0) {
            noteText.textContent = '현재 새 이미지 ' + count + '장이 선택되어 있습니다. 저장하면 AI가 각 이미지의 설명, 키워드, 장면 정보를 분석합니다.';
        } else {
            noteText.textContent = '새 이미지를 첨부하면 저장 단계에서 AI가 이미지 설명과 키워드를 함께 생성합니다.';
        }
    }

    var imagePlacementState = { slots: {} };
    var imageAssetsById = {};
    (caseImageAssets || []).forEach(function (asset) {
        if (!asset || !asset.id) return;
        imageAssetsById[String(asset.id)] = asset;
    });

    function getSectionDefinitions() {
        var defs = [{
            key: 'intro',
            title: '도입부',
            helper: '본문 시작 부분에 들어갈 이미지'
        }];
        var h2Inputs = document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]');
        h2Inputs.forEach(function (input, index) {
            var title = (input.value || '').trim();
            if (title !== '') {
                defs.push({
                    key: 'h2-' + (index + 1),
                    title: title,
                    helper: '이 H2 구간에 최대 2장'
                });
            }
        });
        return defs;
    }

    function normalizeSlotIds(ids) {
        if (!Array.isArray(ids)) return [];
        var out = [];
        ids.forEach(function (value) {
            var key = String(parseInt(value, 10) || 0);
            if (key && key !== '0' && imageAssetsById[key] && out.indexOf(key) === -1) {
                out.push(key);
            }
        });
        return out.slice(0, 2);
    }

    function normalizeImagePlacementState(rawState, defs) {
        var normalized = { slots: {} };
        var taken = {};
        var inputSlots = rawState && typeof rawState === 'object' && rawState.slots && typeof rawState.slots === 'object'
            ? rawState.slots
            : {};

        defs.forEach(function (def) {
            var values = normalizeSlotIds(inputSlots[def.key] || []);
            var deduped = [];
            values.forEach(function (id) {
                if (taken[id]) return;
                taken[id] = true;
                deduped.push(id);
            });
            normalized.slots[def.key] = deduped.slice(0, 2);
        });

        return normalized;
    }

    function buildStateFromRecommendations(defs, recommendations) {
        var base = { slots: {} };
        defs.forEach(function (def) {
            base.slots[def.key] = [];
        });

        if (!Array.isArray(recommendations)) {
            return base;
        }

        var taken = {};
        recommendations.forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            var slotKey = String(item.section_key || '').trim();
            if (!base.slots.hasOwnProperty(slotKey)) return;
            var ids = normalizeSlotIds(item.recommended_image_ids || []);
            ids.forEach(function (id) {
                if (taken[id]) return;
                if (base.slots[slotKey].length >= 2) return;
                taken[id] = true;
                base.slots[slotKey].push(id);
            });
        });

        return base;
    }

    function getUnusedImageIds(defs) {
        var used = {};
        defs.forEach(function (def) {
            (imagePlacementState.slots[def.key] || []).forEach(function (id) {
                used[String(id)] = true;
            });
        });

        return (caseImageAssets || []).map(function (asset) {
            return String(asset.id);
        }).filter(function (id) {
            return !used[id];
        });
    }

    function updateImageLayoutField() {
        var input = document.getElementById('aiImageLayoutJson');
        if (!input) return;
        input.value = JSON.stringify(imagePlacementState);
    }

    function formatPreviewText(text) {
        var normalized = String(text || '').trim();
        if (normalized === '') {
            return '';
        }

        return normalized
            .split(/\n{2,}/)
            .map(function (block) {
                var lineText = block
                    .split('\n')
                    .map(function (line) { return line.trim(); })
                    .filter(function (line) { return line !== ''; })
                    .join(' ');
                return lineText ? '<p class="draft-preview-paragraph">' + escapeHtml(lineText) + '</p>' : '';
            })
            .filter(function (block) {
                return block !== '';
            })
            .join('');
    }

    function parseDraftSections(text) {
        var source = String(text || '').replace(/\r\n/g, '\n');
        var lines = source.split('\n');
        var sections = [];
        var current = {
            key: 'intro',
            title: '도입부',
            lines: []
        };

        lines.forEach(function (line) {
            if (/^##\s+/.test(line)) {
                sections.push(current);
                current = {
                    key: '',
                    title: line.replace(/^##\s+/, '').trim(),
                    lines: []
                };
                return;
            }
            current.lines.push(line);
        });
        sections.push(current);

        var h2Index = 0;
        return sections.map(function (section) {
            var key = section.key;
            if (key === '') {
                h2Index += 1;
                key = 'h2-' + h2Index;
            }
            return {
                key: key,
                title: section.title || '섹션',
                text: section.lines.join('\n').trim()
            };
        });
    }

    function renderDraftPreview() {
        var preview = document.getElementById('draftImagePreview');
        var bodyDraftEl = document.getElementById('aiBodyDraft');
        if (!preview || !bodyDraftEl) return;

        var draftText = String(bodyDraftEl.value || '').trim();
        if (draftText === '') {
            preview.innerHTML = '<div class="draft-preview-empty">본문 초안을 생성하면 여기에 도입부와 각 H2 아래의 이미지 배치 미리보기가 표시됩니다.</div>';
            return;
        }

        var sections = parseDraftSections(draftText);
        var aiTitle = (document.getElementById('aiTitle').value || document.getElementById('caseTitle').value || '블로그 제목 미리보기').trim();
        var aiSummary = (document.getElementById('aiSummary').value || '').trim();
        var keywordValue = (document.getElementById('targetKeywords').value || '').trim();
        var keywordTokens = keywordValue === '' ? [] : keywordValue.split(',').map(function (token) {
            return token.trim();
        }).filter(function (token) {
            return token !== '';
        }).slice(0, 4);
        var tocHtml = sections.filter(function (section) {
            return section.key !== 'intro' && section.title;
        }).map(function (section) {
            return '<span class="draft-preview-blog__toc-item">' + escapeHtml(section.title) + '</span>';
        }).join('');
        var sectionsHtml = sections.map(function (section, index) {
            var assignedIds = (imagePlacementState.slots[section.key] || []).slice(0, 2);
            var imagesHtml = assignedIds.map(function (id) {
                var asset = imageAssetsById[String(id)];
                if (!asset) return '';
                return '' +
                    '<figure class="draft-preview-image">' +
                        '<div class="draft-preview-image__thumb"><img src="' + escapeHtml(asset.url) + '" alt="' + escapeHtml(asset.name) + '"></div>' +
                        '<figcaption>' +
                            '<div class="draft-preview-image__name">' + escapeHtml(asset.name) + '</div>' +
                            '<div class="draft-preview-image__summary">' + escapeHtml(asset.summary || asset.description || '이미지 메타 요약 없음') + '</div>' +
                        '</figcaption>' +
                    '</figure>';
            }).join('');

            return '' +
                '<section class="draft-preview-section" data-preview-section="' + escapeHtml(section.key) + '">' +
                    '<span class="draft-preview-section__eyebrow">' + escapeHtml(index === 0 ? 'INTRO' : 'SECTION ' + index) + '</span>' +
                    '<h6 class="draft-preview-section__title">' + escapeHtml(section.title) + '</h6>' +
                    '<div class="draft-preview-section__text">' + formatPreviewText(section.text) + '</div>' +
                    (imagesHtml !== '' ? '<div class="draft-preview-section__images">' + imagesHtml + '</div>' : '') +
                '</section>';
        }).join('');
        preview.innerHTML = '' +
            '<article class="draft-preview-blog">' +
                '<div class="draft-preview-blog__chrome">' +
                    '<span class="draft-preview-blog__dot"></span>' +
                    '<span class="draft-preview-blog__dot"></span>' +
                    '<span class="draft-preview-blog__dot is-accent"></span>' +
                    '<span class="draft-preview-blog__label">Blog Preview</span>' +
                '</div>' +
                '<div class="draft-preview-blog__content">' +
                    '<div class="draft-preview-blog__meta">' +
                        '<span class="draft-preview-blog__chip">AI Draft</span>' +
                        '<span class="draft-preview-blog__chip"><?= htmlspecialchars($typeCfg['label'], ENT_QUOTES, 'UTF-8') ?></span>' +
                        (keywordTokens.length ? keywordTokens.map(function (token) {
                            return '<span class="draft-preview-blog__chip">' + escapeHtml(token) + '</span>';
                        }).join('') : '') +
                    '</div>' +
                    '<h4 class="draft-preview-blog__title">' + escapeHtml(aiTitle) + '</h4>' +
                    (aiSummary !== '' ? '<div class="draft-preview-blog__summary">' + formatPreviewText(aiSummary) + '</div>' : '') +
                    (tocHtml !== '' ? '<div class="draft-preview-blog__toc">' + tocHtml + '</div>' : '') +
                    '<div class="draft-preview-blog__sections">' + sectionsHtml + '</div>' +
                '</div>' +
            '</article>';
    }

    function createImageCardHtml(asset, removeLabel) {
        var keywords = Array.isArray(asset.keywords) ? asset.keywords.slice(0, 4).join(', ') : '';
        return '' +
            '<article class="draft-image-card" draggable="true" data-file-id="' + escapeHtml(asset.id) + '">' +
                '<div class="draft-image-card__thumb"><img src="' + escapeHtml(asset.url) + '" alt="' + escapeHtml(asset.name) + '"></div>' +
                '<div class="draft-image-card__body">' +
                    '<strong class="draft-image-card__name">' + escapeHtml(asset.name) + '</strong>' +
                    '<div class="draft-image-card__summary">' + escapeHtml(asset.summary || asset.description || '이미지 메타 요약 없음') + '</div>' +
                    (keywords !== '' ? '<span class="draft-image-card__keywords">' + escapeHtml(keywords) + '</span>' : '') +
                '</div>' +
                '<button type="button" class="draft-image-card__action" data-remove-file-id="' + escapeHtml(asset.id) + '">' + escapeHtml(removeLabel) + '</button>' +
            '</article>';
    }

    function renderImagePlacementUI() {
        var wrap = document.getElementById('draftImagePlacementWrap');
        var slotsWrap = document.getElementById('draftImagePlacementSlots');
        var bankWrap = document.getElementById('draftImagePlacementBank');
        var bankCount = document.getElementById('draftImageBankCount');
        if (!wrap || !slotsWrap || !bankWrap || !bankCount) return;

        var defs = getSectionDefinitions();
        imagePlacementState = normalizeImagePlacementState(imagePlacementState, defs);

        if (!caseImageAssets.length) {
            wrap.style.display = '';
            slotsWrap.innerHTML = '<div class="draft-image-placement__empty">저장된 이미지 메타가 아직 없습니다. 이미지를 저장한 뒤 다시 본문 초안을 생성하면 AI가 섹션별 이미지를 추천합니다.</div>';
            bankWrap.innerHTML = '<div class="draft-image-placement__empty">대기 중인 이미지가 없습니다.</div>';
            bankCount.textContent = '0장 대기';
            updateImageLayoutField();
            return;
        }

        slotsWrap.innerHTML = defs.map(function (def) {
            var assignedIds = imagePlacementState.slots[def.key] || [];
            var cardsHtml = assignedIds.map(function (id) {
                var asset = imageAssetsById[String(id)];
                return asset ? createImageCardHtml(asset, '빼기') : '';
            }).join('');

            if (cardsHtml === '') {
                cardsHtml = '<div class="draft-image-slot__empty">여기에 이미지를 드래그해 넣으세요.<br>최대 2장까지 배치됩니다.</div>';
            }

            return '' +
                '<section class="draft-image-slot" data-slot-key="' + escapeHtml(def.key) + '">' +
                    '<div class="draft-image-slot__head">' +
                        '<h6 class="draft-image-slot__title">' + escapeHtml(def.title) + '</h6>' +
                        '<span class="draft-image-slot__meta">' + escapeHtml(def.helper) + '</span>' +
                    '</div>' +
                    '<div class="draft-image-slot__dropzone" data-slot-dropzone="' + escapeHtml(def.key) + '">' +
                        '<div class="draft-image-slot__cards">' + cardsHtml + '</div>' +
                    '</div>' +
                '</section>';
        }).join('');

        var unusedIds = getUnusedImageIds(defs);
        bankCount.textContent = unusedIds.length + '장 대기';
        if (!unusedIds.length) {
            bankWrap.innerHTML = '<div class="draft-image-placement__empty">남은 이미지가 없습니다.</div>';
        } else {
            bankWrap.innerHTML = '<div class="draft-image-placement__bank-cards">' + unusedIds.map(function (id) {
                var asset = imageAssetsById[id];
                return asset ? createImageCardHtml(asset, '대기') : '';
            }).join('') + '</div>';
        }

        updateImageLayoutField();
        renderDraftPreview();
    }

    function moveImageToSlot(fileId, targetSlotKey) {
        var defs = getSectionDefinitions();
        imagePlacementState = normalizeImagePlacementState(imagePlacementState, defs);

        defs.forEach(function (def) {
            imagePlacementState.slots[def.key] = (imagePlacementState.slots[def.key] || []).filter(function (id) {
                return String(id) !== String(fileId);
            });
        });

        if (targetSlotKey !== 'bank') {
            if (!imagePlacementState.slots.hasOwnProperty(targetSlotKey)) {
                return;
            }
            if ((imagePlacementState.slots[targetSlotKey] || []).length >= 2) {
                alert('한 섹션에는 이미지를 최대 2장까지 넣을 수 있습니다.');
                return;
            }
            imagePlacementState.slots[targetSlotKey].push(String(fileId));
        }

        renderImagePlacementUI();
    }

    function applyImageRecommendations(recommendations) {
        var defs = getSectionDefinitions();
        imagePlacementState = buildStateFromRecommendations(defs, recommendations || []);
        renderImagePlacementUI();
    }

    function fillH2Sections(sections) {
        var inputs = document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]');
        if (!Array.isArray(sections)) return;
        inputs.forEach(function (inp, idx) {
            inp.value = sections[idx] || '';
        });
        renderImagePlacementUI();
    }

    function fillStructuredFields(structured) {
        structured = structured || {};
        document.getElementById('aiIndustryCategory').value = structured.industry_category || '';
        document.getElementById('aiCaseCategory').value = structured.case_category || '';
        document.getElementById('aiSubjectLabel').value = structured.subject_label || '';
        document.getElementById('aiProblemSummary').value = structured.problem_summary || '';
        document.getElementById('aiProcessSummary').value = structured.process_summary || '';
        document.getElementById('aiResultSummary').value = structured.result_summary || '';
    }

    var aiPreviewModal = document.getElementById('aiPreviewModal');
    var aiPreviewBody = document.getElementById('aiPreviewBody');
    var aiPreviewClose = document.getElementById('aiPreviewClose');
    var aiPreviewConfirm = document.getElementById('aiPreviewConfirm');
    var aiPreviewTypingTimer = null;

    function closeAiPreviewModal() {
        if (aiPreviewTypingTimer) {
            clearTimeout(aiPreviewTypingTimer);
            aiPreviewTypingTimer = null;
        }
        if (aiPreviewModal) {
            aiPreviewModal.classList.remove('is-open');
            aiPreviewModal.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('ai-preview-open');
    }

    function typeSectionsSequentially(nodes, idx) {
        if (!nodes || idx >= nodes.length) return;

        var node = nodes[idx];
        var text = node.getAttribute('data-full-text') || '';
        var target = node.querySelector('.ai-preview-section__content');
        if (!target) {
            typeSectionsSequentially(nodes, idx + 1);
            return;
        }

        target.classList.add('is-typing');
        target.textContent = '';
        var cursor = 0;

        function step() {
            cursor = Math.min(text.length, cursor + 3);
            target.textContent = text.slice(0, cursor);
            if (cursor < text.length) {
                aiPreviewTypingTimer = setTimeout(step, 14);
            } else {
                target.classList.remove('is-typing');
                aiPreviewTypingTimer = setTimeout(function () {
                    typeSectionsSequentially(nodes, idx + 1);
                }, 140);
            }
        }

        step();
    }

    function buildPreviewSections(mode, data) {
        var sections = [];
        var structured = (data && data.structured) || {};

        if (mode === 'analyze') {
            sections.push({
                title: '분류 결과',
                content: [
                    '업종: ' + (structured.industry_category || '-'),
                    '사례 유형: ' + (structured.case_category || '-'),
                    '대상 요약: ' + (structured.subject_label || '-'),
                    '문제 상황: ' + (structured.problem_summary || '-'),
                    '진행 과정: ' + (structured.process_summary || '-'),
                    '결과 요약: ' + (structured.result_summary || '-')
                ].join('\n')
            });
            var titleDisplay = '제목: ' + (data.title || '-');
            if (Array.isArray(data.title_candidates) && data.title_candidates.length > 0) {
                titleDisplay = '추천 제목:\n' + data.title_candidates.map(function (t, i) {
                    return (i + 1) + '. ' + t;
                }).join('\n');
            }
            sections.push({
                title: '블로그 초안',
                content: [
                    titleDisplay,
                    '',
                    '요약:',
                    data.summary || '-',
                    '',
                    'H2 소제목:',
                    ((data.h2_sections || []).length
                        ? (data.h2_sections || []).map(function (item, index) {
                            return (index + 1) + '. ' + item;
                        }).join('\n')
                        : '-')
                ].join('\n')
            });
            sections.push({
                title: '다음 단계',
                content: '지금 결과를 바탕으로 아래에서 본문 초안도 이어서 생성할 수 있습니다.'
            });
        } else if (mode === 'draft') {
            sections.push({
                title: '본문 초안',
                content: (data.body_draft || '').trim() !== '' ? data.body_draft : '본문 초안이 생성되지 않았습니다.'
            });
        }

        return sections;
    }

    function openAiPreviewModal(mode, data) {
        if (!aiPreviewModal || !aiPreviewBody) return;

        closeAiPreviewModal();

        var sections = buildPreviewSections(mode, data);
        aiPreviewBody.innerHTML = sections.map(function (section) {
            return '' +
                '<section class="ai-preview-section" data-full-text="' + escapeHtml(section.content) + '">' +
                '  <h4 class="ai-preview-section__title">' + escapeHtml(section.title) + '</h4>' +
                '  <div class="ai-preview-section__content"></div>' +
                '</section>';
        }).join('');

        aiPreviewModal.classList.add('is-open');
        aiPreviewModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ai-preview-open');

        var nodes = Array.prototype.slice.call(aiPreviewBody.querySelectorAll('.ai-preview-section'));
        typeSectionsSequentially(nodes, 0);
    }

    function showLoading(show) {
        var btnAnalyze = document.getElementById('btnAnalyze');
        if (!btnAnalyze) return;

        var btnText = btnAnalyze.querySelector('.btn-text');
        var btnLoading = btnAnalyze.querySelector('.btn-loading');
        var existing = document.getElementById('aiLoadingOverlay');

        if (show) {
            btnAnalyze.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (btnLoading) btnLoading.style.display = '';
            if (!existing) {
                var overlay = document.createElement('div');
                overlay.id = 'aiLoadingOverlay';
                overlay.className = 'ai-loading-overlay';
                overlay.innerHTML = '<div class="ai-loading-spinner"></div><div class="ai-loading-text">AI가 유형별 사례를 분석하고 있습니다...</div>';
                document.body.appendChild(overlay);
            }
        } else {
            btnAnalyze.disabled = false;
            if (btnText) btnText.style.display = '';
            if (btnLoading) btnLoading.style.display = 'none';
            if (existing) existing.remove();
        }
    }

    function showSubmitOverlay(imageCount) {
        var existing = document.getElementById('caseSubmitOverlay');
        if (existing) return;

        var hasImages = imageCount > 0;
        var overlay = document.createElement('div');
        overlay.id = 'caseSubmitOverlay';
        overlay.className = 'case-submit-overlay';
        overlay.innerHTML =
            '<div class="case-submit-overlay__dialog">' +
                '<span class="case-submit-overlay__eyebrow">AI Save Pipeline</span>' +
                '<h3 class="case-submit-overlay__title">사례를 저장하고 있습니다</h3>' +
                '<p class="case-submit-overlay__desc">' +
                    (hasImages
                        ? '업로드한 <span class="case-submit-overlay__highlight">이미지 ' + imageCount + '장</span>을 함께 저장한 뒤, AI가 장면과 핵심 요소를 읽어 메타데이터를 생성하고 있습니다.'
                        : '사례 내용을 저장하고 AI 결과를 함께 반영하고 있습니다. 잠시만 기다려주세요.') +
                '</p>' +
                '<div class="case-submit-overlay__visual">' +
                    '<div class="case-submit-overlay__spinner"></div>' +
                    '<div class="case-submit-overlay__visual-copy">' +
                        '<strong>' + (hasImages ? '이미지 분석 진행 중' : '사례 저장 진행 중') + '</strong>' +
                        '<span>' + (hasImages ? '브라우저를 닫지 말고 잠시만 기다려주세요.' : '저장 완료 후 자동으로 다음 화면으로 이동합니다.') + '</span>' +
                    '</div>' +
                '</div>' +
                '<ul class="case-submit-overlay__steps">' +
                    '<li class="case-submit-overlay__step"><span class="case-submit-overlay__step-dot"></span><span class="case-submit-overlay__step-text">사례 데이터 저장</span></li>' +
                    '<li class="case-submit-overlay__step"><span class="case-submit-overlay__step-dot"></span><span class="case-submit-overlay__step-text">' + (hasImages ? '업로드 이미지 정리 및 AI 메타 분석' : '저장 결과 반영') + '</span></li>' +
                    '<li class="case-submit-overlay__step"><span class="case-submit-overlay__step-dot"></span><span class="case-submit-overlay__step-text">완료 후 상세 화면으로 이동</span></li>' +
                '</ul>' +
            '</div>';

        document.body.appendChild(overlay);
        document.body.classList.add('case-submit-open');
    }

    setupDropzone(
        document.querySelector('[data-dropzone="case-images"]'),
        document.getElementById('caseImagesInput'),
        document.getElementById('caseImageFileList')
    );
    updateImageSelectionState();

    document.querySelectorAll('[data-toggle-list]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var listId = btn.getAttribute('data-toggle-list');
            var limit = parseInt(btn.getAttribute('data-limit') || '0', 10);
            var ul = document.getElementById(listId);
            if (!ul || !limit) return;

            var items = Array.prototype.slice.call(ul.querySelectorAll('.file__list__item:not(.file__list__item--new)'));
            var isExpanded = btn.getAttribute('data-expanded') === '1';

            if (!isExpanded) {
                items.forEach(function (li) { li.classList.remove('is-hidden'); });
                btn.textContent = '접기';
                btn.setAttribute('data-expanded', '1');
            } else {
                items.forEach(function (li, idx) {
                    if (idx >= limit) li.classList.add('is-hidden');
                });
                btn.textContent = '더보기';
                btn.setAttribute('data-expanded', '0');
                ul.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    var btnAnalyze = document.getElementById('btnAnalyze');
    var btnGenerateDraft = document.getElementById('btnGenerateDraft');
    var aiResultWrap = document.getElementById('aiResultWrap');
    var aiStatusInput = document.getElementById('aiStatusInput');
    var submitButton = document.getElementById('caseSubmitButton');
    var isSubmitting = false;

    imagePlacementState = normalizeImagePlacementState(initialImageLayout || {}, getSectionDefinitions());
    renderImagePlacementUI();
    renderDraftPreview();

    document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]').forEach(function (input) {
        input.addEventListener('input', function () {
            renderImagePlacementUI();
        });
    });

    var aiBodyDraftElForPreview = document.getElementById('aiBodyDraft');
    if (aiBodyDraftElForPreview) {
        aiBodyDraftElForPreview.addEventListener('input', function () {
            renderDraftPreview();
            updateProgressBar();
        });
    }

    ['aiTitle', 'aiSummary', 'targetKeywords', 'caseTitle'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
            renderDraftPreview();
        });
    });

    document.addEventListener('dragstart', function (e) {
        var card = e.target && e.target.closest ? e.target.closest('.draft-image-card') : null;
        if (!card) return;
        var fileId = card.getAttribute('data-file-id');
        if (!fileId || !e.dataTransfer) return;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(fileId));
    });

    document.addEventListener('dragover', function (e) {
        var zone = e.target && e.target.closest ? e.target.closest('[data-slot-dropzone], #draftImagePlacementBank') : null;
        if (!zone) return;
        e.preventDefault();
        zone.classList.add('is-over');
    });

    document.addEventListener('dragleave', function (e) {
        var zone = e.target && e.target.closest ? e.target.closest('[data-slot-dropzone], #draftImagePlacementBank') : null;
        if (!zone) return;
        zone.classList.remove('is-over');
    });

    document.addEventListener('drop', function (e) {
        var zone = e.target && e.target.closest ? e.target.closest('[data-slot-dropzone], #draftImagePlacementBank') : null;
        if (!zone) return;
        e.preventDefault();
        zone.classList.remove('is-over');
        var fileId = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
        if (!fileId || !imageAssetsById[String(fileId)]) return;
        if (zone.id === 'draftImagePlacementBank') {
            moveImageToSlot(fileId, 'bank');
            return;
        }
        var targetSlotKey = zone.getAttribute('data-slot-dropzone');
        if (targetSlotKey) {
            moveImageToSlot(fileId, targetSlotKey);
        }
    });

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-remove-file-id]') : null;
        if (!btn) return;
        moveImageToSlot(btn.getAttribute('data-remove-file-id'), 'bank');
    });

    if (btnAnalyze) {
        btnAnalyze.addEventListener('click', function () {
            buildRaw();
            var caseTitleEl = document.getElementById('caseTitle');
            var rawContentEl = document.getElementById('rawContent');
            var q1 = (document.getElementById('typedQ1').value || '').trim();
            var q2 = (document.getElementById('typedQ2').value || '').trim();
            var q3 = (document.getElementById('typedQ3').value || '').trim();

            if (!caseTitleEl || caseTitleEl.value.trim() === '') {
                alert('사례명을 입력해주세요.');
                caseTitleEl.focus();
                return;
            }

            if (q1 === '' || q2 === '' || q3 === '') {
                alert('사례 내용 3개 항목을 모두 입력해주세요.');
                return;
            }

            var kwEl = document.getElementById('targetKeywords');
            var targetKw = kwEl ? kwEl.value.trim() : '';
            if (targetKw === '') targetKw = caseTitleEl.value.trim();

            var payload = {
                case_id: caseId,
                case_title: caseTitleEl.value.trim(),
                raw_content: rawContentEl.value.trim(),
                case_input_type: caseInputType,
                target_keywords: targetKw,
            };

            showLoading(true);

            fetch('case_typed_ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify(payload)
            })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                showLoading(false);
                if (json.error) {
                    alert('AI 분석 오류: ' + json.error);
                    return;
                }
                if (json.success && json.data) {
                    var d = json.data;
                    fillStructuredFields(d.structured || {});

                    if (Array.isArray(d.title_candidates) && d.title_candidates.length > 0) {
                        renderTitleCandidates(d.title_candidates);
                    } else {
                        document.getElementById('aiTitle').value = d.title || '';
                    }

                    document.getElementById('aiSummary').value = d.summary || '';
                    fillH2Sections(d.h2_sections || []);
                    if (aiStatusInput) aiStatusInput.value = 'done';
                    if (aiResultWrap) {
                        aiResultWrap.style.display = '';
                        setTimeout(function () {
                            aiResultWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 100);
                    }
                    updateProgressBar();
                    openAiPreviewModal('analyze', d);
                }
            })
            .catch(function (err) {
                showLoading(false);
                alert('네트워크 오류가 발생했습니다. 다시 시도해주세요.');
                console.error(err);
            });
        });
    }

    if (btnGenerateDraft) {
        btnGenerateDraft.addEventListener('click', function () {
            buildRaw();
            var caseTitleEl = document.getElementById('caseTitle');
            var rawContentEl = document.getElementById('rawContent');
            var aiTitleEl = document.getElementById('aiTitle');
            var aiSummaryEl = document.getElementById('aiSummary');
            var aiBodyDraftEl = document.getElementById('aiBodyDraft');
            var h2Inputs = document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]');
            var q1 = (document.getElementById('typedQ1').value || '').trim();
            var q2 = (document.getElementById('typedQ2').value || '').trim();
            var q3 = (document.getElementById('typedQ3').value || '').trim();

            if (!caseTitleEl || caseTitleEl.value.trim() === '' || !rawContentEl || rawContentEl.value.trim() === '') {
                alert('사례명과 사례 내용을 먼저 입력해주세요.');
                return;
            }

            if (q1 === '' || q2 === '' || q3 === '') {
                alert('사례 내용 3개 항목을 모두 입력해주세요.');
                return;
            }

            if (!aiTitleEl || aiTitleEl.value.trim() === '') {
                alert('먼저 AI 분석을 실행해서 제목과 구조를 생성해주세요.');
                return;
            }

            var h2Sections = [];
            h2Inputs.forEach(function (input) {
                var value = input.value.trim();
                if (value !== '') h2Sections.push(value);
            });

            var kwEl2 = document.getElementById('targetKeywords');
            var targetKw2 = kwEl2 ? kwEl2.value.trim() : '';
            if (targetKw2 === '') targetKw2 = caseTitleEl.value.trim();

            var payload = {
                mode: 'draft',
                case_id: caseId,
                case_title: caseTitleEl.value.trim(),
                raw_content: rawContentEl.value.trim(),
                case_input_type: caseInputType,
                target_keywords: targetKw2,
                ai_title: aiTitleEl.value.trim(),
                ai_summary: aiSummaryEl ? aiSummaryEl.value.trim() : '',
                ai_h2_sections: h2Sections,
                structured: {
                    industry_category: (document.getElementById('aiIndustryCategory') || { value: '' }).value.trim(),
                    case_category: (document.getElementById('aiCaseCategory') || { value: '' }).value.trim(),
                    subject_label: (document.getElementById('aiSubjectLabel') || { value: '' }).value.trim(),
                    problem_summary: (document.getElementById('aiProblemSummary') || { value: '' }).value.trim(),
                    process_summary: (document.getElementById('aiProcessSummary') || { value: '' }).value.trim(),
                    result_summary: (document.getElementById('aiResultSummary') || { value: '' }).value.trim()
                }
            };

            showLoading(true);

            fetch('case_typed_ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify(payload)
            })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                showLoading(false);
                if (json.error) {
                    alert('본문 초안 생성 오류: ' + json.error);
                    return;
                }
                if (json.success && json.data && aiBodyDraftEl) {
                    aiBodyDraftEl.value = json.data.body_draft || '';
                    applyImageRecommendations(json.data.image_placements || []);
                    aiBodyDraftEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    updateProgressBar();
                    openAiPreviewModal('draft', json.data);
                }
            })
            .catch(function (err) {
                showLoading(false);
                alert('네트워크 오류가 발생했습니다. 다시 시도해주세요.');
                console.error(err);
            });
        });
    }

    var form = document.getElementById('caseForm');
    if (aiPreviewClose) {
        aiPreviewClose.addEventListener('click', closeAiPreviewModal);
    }
    if (aiPreviewConfirm) {
        aiPreviewConfirm.addEventListener('click', closeAiPreviewModal);
    }
    if (aiPreviewModal) {
        aiPreviewModal.addEventListener('click', function (e) {
            if (e.target === aiPreviewModal) {
                closeAiPreviewModal();
            }
        });
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            buildRaw();
            var errors = [];
            var caseTitleEl = document.getElementById('caseTitle');
            var rawContentEl = document.getElementById('rawContent');
            var q1 = (document.getElementById('typedQ1').value || '').trim();
            var q2 = (document.getElementById('typedQ2').value || '').trim();
            var q3 = (document.getElementById('typedQ3').value || '').trim();

            if (!caseTitleEl || caseTitleEl.value.trim() === '') errors.push('사례명');
            if (q1 === '' || q2 === '' || q3 === '') errors.push('사례 내용 3개 항목');

            if (errors.length > 0) {
                e.preventDefault();
                alert('다음 필수 항목을 입력해주세요:\n\n' + errors.map(function (l) { return '• ' + l; }).join('\n'));
                if (caseTitleEl) {
                    caseTitleEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    caseTitleEl.focus();
                }
                return;
            }

            if (isSubmitting) {
                e.preventDefault();
                return;
            }

            isSubmitting = true;

            if (submitButton) {
                submitButton.classList.add('is-submitting');
                submitButton.disabled = true;
                var submitLabel = submitButton.querySelector('.case-submit-label');
                var submitLoading = submitButton.querySelector('.case-submit-loading');
                if (submitLabel) submitLabel.style.display = 'none';
                if (submitLoading) submitLoading.style.display = '';
            }
            if (btnAnalyze) btnAnalyze.disabled = true;
            if (btnGenerateDraft) btnGenerateDraft.disabled = true;

            var fileInput = document.getElementById('caseImagesInput');
            var selectedImageCount = fileInput && fileInput.files ? fileInput.files.length : 0;
            showSubmitOverlay(selectedImageCount);
            window.setTimeout(function () {
                if (submitButton && submitButton.scrollIntoView) {
                    submitButton.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 30);
        });
    }
})();
</script>

<?php include '../footer.inc.php'; ?>
