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
        'q1' => '1. 어떤 문제가 있었나요?',
        'q2' => '2. 해당 문제를 어떻게 해결했나요?',
        'q3' => '3. 문제 해결을 통해 어떤 변화가 생겼나요?',
    ],
    'process_work' => [
        'label' => '작업/진행 과정 사례',
        'q1' => '1. 어떤 작업(프로젝트)이었나요?',
        'q2' => '2. 어떤 과정/순서로 진행했나요?',
        'q3' => '3. 최종 결과는 어떻게 되었나요?',
    ],
    'consulting_qa' => [
        'label' => '상담/문의 사례',
        'q1' => '1. 고객이 문의한 핵심 내용은 무엇이었나요?',
        'q2' => '2. 그 문의에 어떻게 답변했나요?',
        'q3' => '3. 상담 후 고객 반응은 어땠나요?',
    ],
    'review_experience' => [
        'label' => '고객 경험/후기',
        'q1' => '1. 고객이 만족한 제품/서비스는 무엇이었나요?',
        'q2' => '2. 고객 반응 또는 후기를 구체적으로 적어주세요.',
        'q3' => '3. 후기를 통해 강조하고 싶은 점은 무엇인가요?',
    ],
];

function build_chat_raw_content_from_flow(array $typeCfg, array $chatFlow): string
{
    $parts = [];
    $q1 = trim((string)($chatFlow['question1']['answer'] ?? ''));
    $q2Questions = is_array($chatFlow['question2']['questions'] ?? null) ? $chatFlow['question2']['questions'] : [];
    $q2Answer = trim((string)($chatFlow['question2']['answer'] ?? ''));
    if ($q1 !== '') {
        $parts[] = "[질문1]\n1. {$typeCfg['q1']}\n2. {$typeCfg['q2']}\n3. {$typeCfg['q3']}\n\n답변:\n{$q1}";
    }
    if (count($q2Questions) > 0 || $q2Answer !== '') {
        $block = "[질문2]";
        if (count($q2Questions) > 0) {
            $lines = [];
            foreach ($q2Questions as $idx => $question) {
                $question = trim((string)$question);
                if ($question !== '') {
                    $lines[] = ($idx + 1) . '. ' . $question;
                }
            }
            if (count($lines) > 0) {
                $block .= "\n" . implode("\n", $lines);
            }
        }
        if ($q2Answer !== '') {
            $block .= "\n\n답변:\n{$q2Answer}";
        }
        $parts[] = trim($block);
    }

    return implode("\n\n", array_filter($parts));
}

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
                if (!is_array($metaRow)) continue;
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
    header('Location: case_type_select_chat.php');
    exit;
}
$typeCfg = $TYPE_CONFIG[$type];

$chatFlow = is_array($structured['chat_flow'] ?? null) ? $structured['chat_flow'] : [];
$question1Answer = trim((string)($chatFlow['question1']['answer'] ?? ''));
$question2Questions = is_array($chatFlow['question2']['questions'] ?? null) ? array_values($chatFlow['question2']['questions']) : [];
$question2Answer = trim((string)($chatFlow['question2']['answer'] ?? ''));
$question3Questions = [];
$question3Answer = '';
$question3Skipped = true;

$case_title = is_array($caseRow) ? (string)($caseRow['case_title'] ?? '') : '';
$raw_content = is_array($caseRow) ? (string)($caseRow['raw_content'] ?? '') : '';
if ($raw_content === '' && count($chatFlow) > 0) {
    $raw_content = build_chat_raw_content_from_flow($typeCfg, $chatFlow);
}

$ai_title = is_array($caseRow) ? (string)($caseRow['ai_title'] ?? '') : '';
$ai_summary = is_array($caseRow) ? (string)($caseRow['ai_summary'] ?? '') : '';
$ai_body_draft = is_array($caseRow) ? (string)($caseRow['ai_body_draft'] ?? '') : '';
$ai_status = is_array($caseRow) ? (string)($caseRow['ai_status'] ?? 'pending') : 'pending';

$ai_case_category = (string)($structured['case_category'] ?? '');
$ai_industry_category = (string)($structured['industry_category'] ?? '');
$ai_subject_label = (string)($structured['subject_label'] ?? '');
$ai_problem_summary = (string)($structured['problem_summary'] ?? '');
$ai_process_summary = (string)($structured['process_summary'] ?? '');
$ai_result_summary = (string)($structured['result_summary'] ?? '');

$saved_keywords = '';
if (is_string($structured['target_keywords'] ?? null)) {
    $saved_keywords = trim((string)$structured['target_keywords']);
}

$saved_title_candidates = [];
if (is_array($structured['title_candidates'] ?? null)) {
    $saved_title_candidates = $structured['title_candidates'];
}
$titleCandidatesJson = json_encode($saved_title_candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($titleCandidatesJson === false) $titleCandidatesJson = '[]';

$savedImageLayout = [];
if (is_array($structured['image_layout'] ?? null)) {
    $savedImageLayout = $structured['image_layout'];
}
$savedImageLayoutJson = json_encode($savedImageLayout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($savedImageLayoutJson === false) $savedImageLayoutJson = '{}';

$ai_h2_sections = [];
if (is_array($caseRow) && !empty($caseRow['ai_h2_sections'])) {
    $decoded = json_decode((string)$caseRow['ai_h2_sections'], true);
    if (is_array($decoded)) {
        $ai_h2_sections = $decoded;
    }
}

$caseImageLibrary = [];
foreach ($caseFiles as $fileRow) {
    if (!is_array($fileRow)) continue;
    $fileId = (int)($fileRow['id'] ?? 0);
    if ($fileId <= 0) continue;

    $meta = $caseFileMetaMap[$fileId] ?? [];
    $description = trim((string)($meta['description'] ?? ''));
    $subjectPrimary = trim((string)($meta['subject']['primary'] ?? ''));
    $sceneType = trim((string)($meta['scene']['scene_type'] ?? ''));
    $visualRole = trim((string)($meta['scene']['visual_role'] ?? ''));
    $moodLabel = trim((string)($meta['mood']['mood'] ?? ''));
    $subtitleCandidate = trim((string)($meta['audio_text']['subtitle_candidate'] ?? ''));
    $keywords = is_array($meta['keywords'] ?? null) ? array_values($meta['keywords']) : [];

    $summaryParts = array_filter([$subjectPrimary, $sceneType, $visualRole, $moodLabel], static function ($value): bool {
        return trim((string)$value) !== '';
    });

    $caseImageLibrary[] = [
        'id' => $fileId,
        'name' => (string)($fileRow['original_name'] ?? 'image'),
        'url' => '../' . ltrim((string)($fileRow['stored_path'] ?? ''), '/'),
        'summary' => count($summaryParts) > 0 ? implode(' / ', $summaryParts) : '수동 추가 이미지',
        'description' => $description,
        'subtitle_candidate' => $subtitleCandidate,
        'keywords' => $keywords,
        'has_meta' => count($meta) > 0,
    ];
}

$caseImageLibraryJson = json_encode($caseImageLibrary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($caseImageLibraryJson === false) $caseImageLibraryJson = '[]';

$hasAiResult = ($ai_status === 'done' && ($ai_title !== '' || $ai_body_draft !== '' || count(array_filter($ai_h2_sections)) > 0));
$showFinalActions = $hasAiResult || count($question2Questions) > 0 || $question2Answer !== '';

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
                    <h2 class="content__header__title"><?= htmlspecialchars($typeCfg['label'], ENT_QUOTES, 'UTF-8') ?> 상담형 등록</h2>
                </div>

                <div class="content content--chat-case">
                    <section class="chat-hero">
                        <div>
                            <span class="chat-hero__eyebrow">Chat Consultation Flow</span>
                            <h3 class="chat-hero__title">지금부터 블로그 글을 작성하기 위한 채팅 상담을 시작합니다. <span>(약 10분 소요)</span></h3>
                            <p class="chat-hero__desc">먼저 사례의 핵심 내용을 적어주시면 AI가 후속 질문을 이어서 던지고, 마지막에는 글 구조와 본문 초안을 자동으로 정리합니다.</p>
                        </div>
                        <div class="chat-hero__meta">
                            <span><?= $caseId > 0 ? '수정 모드' : '새 사례' ?></span>
                            <span><?= htmlspecialchars($typeCfg['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span>AI 상담형</span>
                        </div>
                    </section>

                    <div class="chat-progress-bar" id="chatProgressBar">
                        <div class="chat-progress-bar__steps">
                            <div class="chat-progress-step" data-step="question1">
                                <span class="chat-progress-step__num">1</span>
                                <span class="chat-progress-step__label">질문 1</span>
                            </div>
                            <div class="chat-progress-step__line"></div>
                            <div class="chat-progress-step" data-step="question2">
                                <span class="chat-progress-step__num">2</span>
                                <span class="chat-progress-step__label">질문 2</span>
                            </div>
                            <div class="chat-progress-step__line"></div>
                            <div class="chat-progress-step" data-step="image">
                                <span class="chat-progress-step__num">3</span>
                                <span class="chat-progress-step__label">이미지</span>
                            </div>
                            <div class="chat-progress-step__line"></div>
                            <div class="chat-progress-step" data-step="analyze">
                                <span class="chat-progress-step__num">4</span>
                                <span class="chat-progress-step__label">AI 분석</span>
                            </div>
                            <div class="chat-progress-step__line"></div>
                            <div class="chat-progress-step" data-step="save">
                                <span class="chat-progress-step__num">5</span>
                                <span class="chat-progress-step__label">저장</span>
                            </div>
                        </div>
                    </div>

                    <form id="chatCaseForm" enctype="multipart/form-data">
                        <input type="hidden" id="caseId" name="case_id" value="<?= (int)$caseId ?>">
                        <input type="hidden" id="caseInputType" name="case_input_type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="caseTitleHidden" name="case_title" value="<?= htmlspecialchars($case_title, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="rawContentHidden" name="raw_content" value="<?= htmlspecialchars($raw_content, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="question2QuestionsJson" name="question2_questions_json" value="<?= htmlspecialchars(json_encode($question2Questions, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="question3QuestionsJson" name="question3_questions_json" value="<?= htmlspecialchars(json_encode($question3Questions, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="question3Skipped" name="question3_skipped" value="<?= $question3Skipped ? '1' : '0' ?>">
                        <input type="hidden" id="titleCandidatesJson" name="title_candidates_json" value="<?= htmlspecialchars($titleCandidatesJson, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="aiImageLayoutJson" name="ai_image_layout_json" value="<?= htmlspecialchars($savedImageLayoutJson, ENT_QUOTES, 'UTF-8') ?>">

                        <section class="chat-card" id="refFileCard">
                            <div class="chat-card__head">
                                <div>
                                    <span class="chat-card__step">참고 자료</span>
                                    <h3 class="chat-card__title">참고 문서가 있으면 먼저 첨부해주세요. AI가 내용을 분석해 질문 1을 자동으로 채워드립니다.</h3>
                                </div>
                            </div>
                            <div class="chat-card__body">
                                <p class="chat-card__guide">진료기록, 상담메모, 시공일지, 고객후기 등을 지원합니다. 문서(txt, pdf, docx) 또는 스캔/캡처 이미지(jpg, png)도 가능합니다. (최대 5MB)</p>
                                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <input type="file" id="refFileInput" accept=".txt,.md,.csv,.pdf,.doc,.docx,.log,.json,.html,.xml,.rtf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.tiff,.tif">
                                    <button type="button" id="btnAnalyzeFile" class="btn btn--primary" style="display:none;">
                                        <span class="btn-text">AI로 문서 분석하기</span>
                                        <span class="btn-loading" style="display:none;">분석 중...</span>
                                    </button>
                                </div>
                                <div id="refFileResult" style="display:none; margin-top:16px;">
                                    <div style="background:#f0f7ff; border:1px solid #d0e3f7; border-radius:16px; padding:20px 24px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                                            <span style="background:#1a73e8; color:#fff; font-size:11px; font-weight:700; padding:4px 10px; border-radius:6px;">AI 요약</span>
                                            <strong id="refFileName" style="font-size:14px; color:#333;"></strong>
                                        </div>
                                        <p id="refFileSummary" style="font-size:14px; color:#444; line-height:1.7; margin:0;"></p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="chat-card" style="margin-top:15px;">
                            <div class="chat-card__head">
                                <div>
                                    <span class="chat-card__step">질문 1</span>
                                    <h3 class="chat-card__title">작성하시고자 하는 사례를 아래 질문에 대한 답변을 포함하여 간략하게 적어주세요.</h3>
                                </div>
                                <button type="button" id="toggleExamplesBtn" class="chat-card__ghost">예제보기</button>
                            </div>
                            <div class="chat-card__body">
                                <ol class="chat-question-list">
                                    <li><?= htmlspecialchars($typeCfg['q1'], ENT_QUOTES, 'UTF-8') ?></li>
                                    <li><?= htmlspecialchars($typeCfg['q2'], ENT_QUOTES, 'UTF-8') ?></li>
                                    <li><?= htmlspecialchars($typeCfg['q3'], ENT_QUOTES, 'UTF-8') ?></li>
                                </ol>
                                <p class="chat-card__guide">각각 2~3문장 이상 입력해주셔야 더 좋은 글이 생성됩니다.</p>

                                <div id="examplePanel" class="example-panel" style="display:none;">
                                    <div class="example-panel__head">
                                        <strong>맞춤 작성 예시</strong>
                                        <span id="examplePanelDesc">내 프롬프트 기준으로 생성된 예시입니다.</span>
                                    </div>
                                    <div id="examplePanelList" class="example-panel__list">
                                        <div class="example-panel__loading">불러오는 중...</div>
                                    </div>
                                </div>

                                <textarea id="question1Answer" name="question1_answer" class="chat-textarea" rows="10" placeholder="질문 1 답변을 입력해주세요.&#10;&#10;이미지도 함께 있으면 아래 업로드 영역에서 같이 올려주세요."><?= htmlspecialchars($question1Answer, ENT_QUOTES, 'UTF-8') ?></textarea>

                                <div class="chat-upload-inline">
                                    <div class="chat-upload-inline__head">
                                        <div>
                                            <strong>질문 1과 함께 이미지도 올려주세요</strong>
                                            <p>여기 올린 이미지는 업로드 즉시 AI 메타 분석을 수행하고, 이후 본문 이미지 자동 배치 추천에 활용됩니다.</p>
                                        </div>
                                        <span class="chat-upload-inline__badge">AI 분석 대상</span>
                                    </div>
                                    <label class="upload-box" data-upload-target="caseImagesInput">
                                        <span>이미지 업로드</span>
                                        <input type="file" id="caseImagesInput" name="case_images[]" accept="image/*" multiple>
                                    </label>
                                    <div id="selectedAiImageList" class="selected-files"></div>

                                    <div class="image-library">
                                        <div class="image-library__head">
                                            <strong>현재 저장된 이미지</strong>
                                            <span id="savedImageCount"><?= count($caseImageLibrary) ?>장</span>
                                        </div>
                                        <div id="savedImageList" class="image-library__list"></div>
                                    </div>
                                </div>
                                <div class="chat-actions">
                                    <button type="button" id="btnQuestion1Complete" class="btn btn--primary">답변 완료하고 다음 단계로</button>
                                </div>
                            </div>
                        </section>

                        <section id="question2Card" class="chat-card" style="<?= (count($question2Questions) > 0 || $question2Answer !== '') ? '' : 'display:none;' ?> margin-top:10px;">
                            <div class="chat-card__head">
                                <div>
                                    <span class="chat-card__step">질문 2</span>
                                    <h3 class="chat-card__title">아래 추가 질문에 최대한 구체적으로 답변해주세요.</h3>
                                </div>
                            </div>
                            <div class="chat-card__body">
                                <div id="question2List" class="chat-followup-list"></div>
                                <textarea id="question2Answer" name="question2_answer" class="chat-textarea" rows="8" placeholder="질문 2 답변을 입력해주세요."><?= htmlspecialchars($question2Answer, ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        </section>

                        <div id="chatFinalActions" class="chat-final-actions" style="<?= $showFinalActions ? '' : 'display:none;' ?>">
                            <a href="case_list.php" class="btn chat-final-actions__secondary">목록으로</a>
                            <button type="button" id="btnSaveOnly" class="btn chat-final-actions__secondary">현재 내용만 저장</button>
                            <button type="button" id="btnSaveGenerate" class="btn btn--primary chat-final-actions__primary">저장하고 글 구조 생성하기</button>
                        </div>

                        <div id="aiResultWrap" class="ai-result-wrap" style="<?= $hasAiResult ? '' : 'display:none;' ?> margin-top:10px;">
                            <div class="chat-card__head">
                                <div>
                                    <span class="chat-card__step">AI 결과</span>
                                    <h3 class="chat-card__title">상담 내용을 바탕으로 구조화된 블로그 초안입니다.</h3>
                                </div>
                            </div>
                            <div class="ai-result-grid">
                                <section class="ai-panel">
                                    <h4 class="ai-panel__title">구조화 결과</h4>
                                    <input type="hidden" id="aiIndustryCategory" name="ai_industry_category" value="<?= htmlspecialchars($ai_industry_category, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="ai-field">
                                        <label for="aiCaseCategory">사례 유형</label>
                                        <input type="text" id="aiCaseCategory" name="ai_case_category" class="input--text" value="<?= htmlspecialchars($ai_case_category, ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="ai-field">
                                        <label for="aiSubjectLabel">대상 요약</label>
                                        <input type="text" id="aiSubjectLabel" name="ai_subject_label" class="input--text" value="<?= htmlspecialchars($ai_subject_label, ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="ai-field">
                                        <label for="aiProblemSummary">문제 상황 요약</label>
                                        <textarea id="aiProblemSummary" name="ai_problem_summary" class="input--text ai-textarea" rows="4"><?= htmlspecialchars($ai_problem_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                    <div class="ai-field">
                                        <label for="aiProcessSummary">진행 과정 요약</label>
                                        <textarea id="aiProcessSummary" name="ai_process_summary" class="input--text ai-textarea" rows="4"><?= htmlspecialchars($ai_process_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                    <div class="ai-field">
                                        <label for="aiResultSummary">결과 요약</label>
                                        <textarea id="aiResultSummary" name="ai_result_summary" class="input--text ai-textarea" rows="4"><?= htmlspecialchars($ai_result_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </section>

                                <section class="ai-panel">
                                    <h4 class="ai-panel__title">블로그 구조</h4>
                                    <div class="ai-field">
                                        <label for="targetKeywords">타겟 키워드</label>
                                        <input type="text" id="targetKeywords" name="target_keywords" class="input--text" value="<?= htmlspecialchars($saved_keywords, ENT_QUOTES, 'UTF-8') ?>" placeholder="예) 강남 동물병원, 슬개골 수술">
                                    </div>
                                    <div class="ai-field">
                                        <label for="aiTitle">블로그 제목</label>
                                        <input type="text" id="aiTitle" name="ai_title" class="input--text" value="<?= htmlspecialchars($ai_title, ENT_QUOTES, 'UTF-8') ?>">
                                        <div id="titleCandidatesWrap" class="title-candidates-wrap" style="<?= count($saved_title_candidates) > 0 ? '' : 'display:none;' ?>">
                                            <span class="title-candidates__label">AI 추천 제목</span>
                                            <div id="titleCandidatesList" class="title-candidates__list"></div>
                                        </div>
                                    </div>
                                    <div class="ai-field">
                                        <label for="aiSummary">요약</label>
                                        <textarea id="aiSummary" name="ai_summary" class="input--text ai-textarea" rows="5"><?= htmlspecialchars($ai_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                    <div class="ai-field">
                                        <label>H2 소제목</label>
                                        <div id="h2SectionsWrap" class="h2-wrap">
                                            <?php for ($i = 0; $i < 6; $i++): ?>
                                            <div class="h2-row">
                                                <span>H2 <?= $i + 1 ?></span>
                                                <input type="text" class="input--text" name="ai_h2[]" value="<?= htmlspecialchars($ai_h2_sections[$i] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <section class="chat-card chat-card--draft" style="margin-top:10px;">
                                <div class="chat-card__head">
                                    <div>
                                        <span class="chat-card__step">본문 초안</span>
                                        <h3 class="chat-card__title">AI가 만든 블로그 초안과 이미지 배치입니다.</h3>
                                    </div>
                                </div>
                                <div class="chat-card__body">
                                    <input type="hidden" id="aiStatusInput" name="ai_status" value="<?= htmlspecialchars($ai_status, ENT_QUOTES, 'UTF-8') ?>">
                                    <textarea id="aiBodyDraft" name="ai_body_draft" class="chat-textarea chat-textarea--draft" rows="18" placeholder="여기에 AI 본문 초안이 표시됩니다."><?= htmlspecialchars($ai_body_draft, ENT_QUOTES, 'UTF-8') ?></textarea>

                                    <div class="manual-upload-box">
                                        <div class="manual-upload-box__intro">
                                            <span class="manual-upload-box__badge">Manual Image Bank</span>
                                            <strong>이미지 추가하기</strong>
                                            <p>여기서 넣은 이미지는 AI 분석 없이 바로 이미지 보관함으로 들어갑니다. <Br/>이후 드래그 앤 드랍으로 원하는 본문 위치에 직접 배치하세요.</p>
                                        </div>
                                        <div class="manual-upload-box__actions">
                                            <label class="upload-box upload-box--small" data-upload-target="manualImagesInput">
                                                <span>추가 이미지 선택</span>
                                                <input type="file" id="manualImagesInput" name="manual_images[]" accept="image/*" multiple>
                                            </label>
                                            <button type="button" id="btnAddManualImages" class="btn manual-upload-box__submit">이미지 보관함에 추가</button>
                                        </div>
                                    </div>
                                    <div id="selectedManualImageList" class="selected-files"></div>

                                    <div id="draftImagePlacementWrap" class="draft-image-placement-wrap">
                                        <div class="draft-image-placement__head">
                                            <div>
                                                <h5 class="draft-image-placement__title">본문 이미지 배치</h5>
                                                <p class="draft-image-placement__desc">AI가 추천한 이미지를 바탕으로, 도입부와 각 H2에 이미지를 배치할 수 있습니다. 수동 추가 이미지는 보관함에서 직접 끌어다 놓으세요.</p>
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
                                            </aside>
                                        </div>
                                    </div>

                                </div>
                            </section>
                            <div class="ai-result-actions">
                                <a href="case_list.php" class="btn btn--primary ai-result-actions__complete">완료</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Reset & Base Typography */
.content--chat-case { display: flex; flex-direction: column; gap: 32px; padding-bottom: 80px; font-family: 'Pretendard', -apple-system, BlinkMacSystemFont, system-ui, Roboto, sans-serif; letter-spacing: -0.015em; }

/* Hero Section - Sleek & Minimal */
.chat-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; padding: 48px; border-radius: 32px; background: #ffffff; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -2px rgba(0, 0, 0, 0.02); color: #0a0a0a; position: relative; overflow: hidden; }
.chat-hero::after { content: ''; position: absolute; top: 0; right: 0; width: 40%; height: 100%; background: linear-gradient(to left, rgba(243, 244, 246, 0.5), transparent); pointer-events: none; }
.chat-hero__eyebrow { display: inline-flex; padding: 6px 16px; border-radius: 999px; background: #f4f4f5; color: #52525b; font-size: .75rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 20px; border: 1px solid #e4e4e7; }
.chat-hero__title { margin: 0; font-size: 2rem; line-height: 1.3; font-weight: 800; color: #09090b; letter-spacing: -0.03em; }
.chat-hero__title span { color: #a1a1aa; font-size: 1rem; font-weight: 500; margin-left: 12px; }
.chat-hero__desc { margin: 16px 0 0; color: #71717a; font-size: 1.05rem; line-height: 1.6; max-width: 600px; }
.chat-hero__meta { display: flex; flex-direction: column; gap: 12px; z-index: 1; align-items: flex-end; }
.chat-hero__meta span { display: inline-flex; justify-content: center; align-items: center; height: 40px; padding: 0 20px; border-radius: 12px; background: #fafafa; border: 1px solid #e4e4e7; color: #3f3f46; font-size: .85rem; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }

/* Progress Bar */
.chat-progress-bar { padding: 22px 26px; border: 1px solid #e5e7eb; border-radius: 24px; background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%); box-shadow: 0 12px 28px -18px rgba(0,0,0,0.08); }
.chat-progress-bar__steps { display: flex; align-items: center; justify-content: center; gap: 0; }
.chat-progress-step { display: flex; flex-direction: column; align-items: center; gap: 8px; flex-shrink: 0; }
.chat-progress-step__num { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 50%; border: 2px solid #d4d4d8; background: #f8fafc; color: #a1a1aa; font-size: 0.84rem; font-weight: 800; transition: all 0.25s ease; }
.chat-progress-step__label { color: #a1a1aa; font-size: 0.78rem; font-weight: 600; white-space: nowrap; transition: color 0.25s ease; }
.chat-progress-step__line { flex: 1; min-width: 38px; max-width: 78px; height: 2px; background: #e4e4e7; margin: 0 6px 28px; border-radius: 999px; transition: background 0.25s ease; }
.chat-progress-step.is-done .chat-progress-step__num { border-color: #16a34a; background: #16a34a; color: #fff; }
.chat-progress-step.is-done .chat-progress-step__label { color: #15803d; }
.chat-progress-step.is-active .chat-progress-step__num { border-color: #09090b; background: #09090b; color: #fff; box-shadow: 0 0 0 5px rgba(9,9,11,0.08); }
.chat-progress-step.is-active .chat-progress-step__label { color: #09090b; font-weight: 700; }
.chat-progress-step__line.is-done { background: linear-gradient(90deg, #22c55e 0%, #16a34a 100%); }

/* Card Containers */
.chat-card, .ai-result-wrap { border: 1px solid #e5e7eb; border-radius: 32px; background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.02), 0 20px 40px -20px rgba(0,0,0,0.04); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.chat-card__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; padding: 48px 48px 0; }
.chat-card__body { padding: 32px 48px 48px; }

/* Steps and Titles */
.chat-card__step { display: inline-flex; align-items: center; height: 32px; padding: 0 16px; border-radius: 10px; background: #09090b; color: #ffffff; font-size: .8rem; font-weight: 700; letter-spacing: .04em; margin-bottom: 16px; }
.chat-card__title { margin: 0; color: #09090b; font-size: 1.35rem; line-height: 1.5; font-weight: 800; letter-spacing: -0.02em; }
.chat-card__guide { margin: 12px 0 0; color: #71717a; font-size: .95rem; line-height: 1.6; }

/* Buttons */
.chat-card__ghost { background: #ffffff; border: 1px solid #e4e4e7; color: #18181b; border-radius: 12px; height: 44px; padding: 0 24px; font-size: .9rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
.chat-card__ghost:hover { background: #f4f4f5; border-color: #d4d4d8; }

.btn--primary { background: #09090b; color: #fff; height: 56px; padding: 0 40px; border-radius: 16px; font-size: 1.05rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: 0 4px 12px rgba(0,0,0,0.1); display: inline-flex; align-items: center; justify-content: center; }
.btn--primary:hover { background: #27272a; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }

/* System Questions */
.chat-question-list { margin: 0; padding: 28px 36px; border-radius: 20px; background: #fafafa; border: 1px solid #f4f4f5; color: #27272a; line-height: 1.8; font-size: 1.05rem; font-weight: 500; list-style-position: inside; }
.chat-question-list li { margin-bottom: 10px; }
.chat-question-list li:last-child { margin-bottom: 0; }

/* Textareas */
.chat-textarea { width: 100%; box-sizing: border-box; margin-top: 24px; padding: 24px 28px; border: 1px solid #e4e4e7; border-radius: 20px; background: #ffffff; color: #09090b; font-size: 1.05rem; line-height: 1.8; resize: vertical; min-height: 200px; transition: all 0.3s ease; box-shadow: inset 0 2px 4px rgba(0,0,0,0.01); }
.chat-textarea:focus { border-color: #09090b; outline: none; box-shadow: 0 0 0 1px #09090b; }
.chat-textarea::placeholder { color: #a1a1aa; font-weight: 400; }
.chat-textarea--draft { min-height: 480px; font-size: 1rem; background: #fafafa; border-color: #e4e4e7; }

/* Action buttons */
.chat-actions { display: flex; justify-content: flex-end; margin-top: 32px; }

/* Chat followups */
.chat-followup-list { display: flex; flex-direction: column; gap: 16px; margin-top: 0; align-items: flex-start; }
.chat-followup-item { padding: 20px 28px; border: 1px solid #e4e4e7; border-radius: 20px; background: #ffffff; color: #27272a; font-size: 1rem; line-height: 1.7; font-weight: 500; max-width: 90%; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
.chat-followup-item strong { color: #09090b; margin-right: 8px; font-size: .85rem; font-weight: 700; background: #f4f4f5; padding: 4px 10px; border-radius: 8px; display: inline-block; margin-bottom: 8px; }

/* Upload box */
.chat-upload-inline { margin-top: 32px; padding: 32px 36px; border: 1px solid #e4e4e7; border-radius: 24px; background: #fafafa; }
.chat-upload-inline__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
.chat-upload-inline__head strong { display: block; color: #09090b; font-size: 1.1rem; font-weight: 700; }
.chat-upload-inline__head p { margin: 8px 0 0; color: #71717a; font-size: .95rem; line-height: 1.6; }
.chat-upload-inline__badge { display: inline-flex; align-items: center; height: 30px; padding: 0 14px; border-radius: 8px; background: #e4e4e7; color: #3f3f46; font-size: .75rem; font-weight: 700; letter-spacing: .02em; }

.upload-box { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; width: 100%; padding: 32px; border: 1px dashed #d4d4d8; border-radius: 20px; background: #ffffff; color: #52525b; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all 0.2s; box-sizing: border-box; }
.upload-box:hover { border-color: #09090b; color: #09090b; background: #fafafa; }
.upload-box.is-dragover { border-color: #09090b; color: #09090b; background: #f4f4f5; box-shadow: 0 0 0 1px #09090b inset; }
.upload-box input { display: none; }
.upload-box--small { padding: 0 24px; width: auto; min-width: 220px; height: 56px; flex-direction: row; border-radius: 16px; border-style: solid; border-color: #e4e4e7; color: #18181b; margin: 0; }

/* Example Panel */
.example-panel { margin-top: 32px; padding: 32px; border: 1px solid #e5e7eb; border-radius: 24px; background: #ffffff; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.05); }
.example-panel__head { margin-bottom: 24px; display: flex; flex-direction: column; gap: 6px; }
.example-panel__head strong { color: #09090b; font-size: 1.1rem; font-weight: 700; }
.example-panel__head span { color: #71717a; font-size: .95rem; }
.example-panel__item { padding: 28px; border: 1px solid #f4f4f5; border-radius: 20px; background: #fafafa; display: flex; flex-direction: column; }
.example-panel__nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e4e4e7; }
.example-panel__item-title { margin: 0; color: #09090b; font-size: 1.1rem; font-weight: 700; text-align: center; flex: 1; }
.example-panel__item-title span { font-size: .9rem; color: #a1a1aa; font-weight: 500; margin-left: 8px; }
.example-panel__btn-prev, .example-panel__btn-next { background: #ffffff; border: 1px solid #e4e4e7; color: #3f3f46; border-radius: 10px; padding: 8px 16px; font-size: .85rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.example-panel__btn-prev:hover, .example-panel__btn-next:hover { background: #f4f4f5; color: #09090b; border-color: #d4d4d8; }
.example-panel__item-body { color: #3f3f46; font-size: 1rem; line-height: 1.8; white-space: pre-wrap; flex: 1; }
.example-panel__item button[data-example-value] { margin-top: 24px; height: 52px; border-radius: 14px; border: 1px solid #09090b; color: #09090b; background: #ffffff; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all 0.2s; }
.example-panel__item button[data-example-value]:hover { background: #09090b; color: #ffffff; }
.example-panel__loading { color: #71717a; font-size: 1rem; font-weight: 500; padding: 24px 0; text-align: center; }

/* Image Library */
.image-library { margin-top: 40px; padding-top: 32px; border-top: 1px solid #e4e4e7; }
.image-library__head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.image-library__head strong { color: #09090b; font-size: 1.1rem; font-weight: 700; }
.image-library__head span { color: #71717a; font-size: .9rem; background: #f4f4f5; padding: 6px 14px; border-radius: 10px; font-weight: 600; }
.image-library__list { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
.image-library__item { display: flex; align-items: center; gap: 20px; padding: 16px; border: 1px solid #e4e4e7; border-radius: 20px; background: #ffffff; transition: transform 0.2s, box-shadow 0.2s; }
.image-library__item:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -8px rgba(0,0,0,0.08); }
.image-library__thumb { width: 88px; height: 88px; border-radius: 14px; overflow: hidden; background: #f4f4f5; flex-shrink: 0; }
.image-library__thumb img { width: 100%; height: 100%; object-fit: cover; }
.image-library__body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.image-library__name { color: #09090b; font-size: .95rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.image-library__summary { color: #71717a; font-size: .85rem; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.image-library__badge { display: inline-flex; align-self: flex-start; padding: 4px 10px; border-radius: 8px; font-size: .75rem; font-weight: 600; }
.image-library__badge.has-meta { background: #09090b; color: #ffffff; }
.image-library__badge.no-meta { background: #f4f4f5; color: #52525b; border: 1px solid #e4e4e7; }

/* Final Actions */
.chat-final-actions { display: flex; justify-content: flex-end; align-items: center; gap: 16px; margin-top: 24px; padding-top: 40px; border-top: 1px solid #e4e4e7; }
.chat-final-actions__secondary { background: #ffffff !important; color: #18181b !important; border: 1px solid #e4e4e7 !important; height: 56px; padding: 0 32px; border-radius: 16px; font-size: 1.05rem; font-weight: 600; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; }
.chat-final-actions__secondary:hover { background: #fafafa !important; border-color: #d4d4d8 !important; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.ai-result-actions { display: flex; justify-content: flex-end; padding: 0 48px 48px; }
.ai-result-actions__complete { min-width: 140px; text-decoration: none; }

/* AI Result Grid & Panels */
.ai-result-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; padding: 0 48px 48px; margin-top: 20px; }
.ai-panel { padding: 40px; border: 1px solid #e4e4e7; border-radius: 28px; background: #fafafa; }
.ai-panel__title { margin: 0 0 32px; color: #09090b; font-size: 1.25rem; font-weight: 700; display: flex; align-items: center; gap: 12px; }
.ai-panel__title::before { content: ''; display: block; width: 6px; height: 20px; background: #09090b; border-radius: 4px; }

/* AI Fields */
.ai-field { display: flex; flex-direction: column; gap: 12px; margin-bottom: 28px; }
.ai-field label { color: #52525b; font-size: .95rem; font-weight: 600; }
.input--text { width: 100%; box-sizing: border-box; padding: 18px 24px; border: 1px solid #e4e4e7; border-radius: 16px; background: #ffffff; font-size: 1.05rem; color: #09090b; transition: all 0.2s; font-family: inherit; }
.input--text:focus { border-color: #09090b; box-shadow: 0 0 0 1px #09090b; outline: none; }
.ai-textarea { min-height: 140px; resize: vertical; line-height: 1.7; }

/* H2 & Titles */
.h2-wrap { display: flex; flex-direction: column; gap: 16px; }
.h2-row { display: grid; grid-template-columns: 60px 1fr; align-items: center; gap: 16px; }
.h2-row span { display: inline-flex; align-items: center; justify-content: center; height: 40px; border-radius: 12px; background: #e4e4e7; color: #18181b; font-size: .85rem; font-weight: 700; }

.title-candidates-wrap { margin-top: 20px; padding: 24px; background: #ffffff; border-radius: 20px; border: 1px solid #e4e4e7; }
.title-candidates__label { display: block; margin-bottom: 16px; color: #52525b; font-size: .9rem; font-weight: 600; }
.title-candidates__list { display: flex; flex-direction: column; gap: 12px; }
.title-candidate-btn { display: flex; width: 100%; align-items: center; padding: 16px 24px; border: 1px solid #e4e4e7; border-radius: 16px; background: #fafafa; color: #27272a; font-size: 1.05rem; font-weight: 500; text-align: left; cursor: pointer; transition: all 0.2s; line-height: 1.5; }
.title-candidate-btn:hover { border-color: #d4d4d8; background: #ffffff; }
.title-candidate-btn.is-selected { border-color: #09090b; background: #09090b; color: #ffffff; font-weight: 600; }

/* Manual Upload Box */
.manual-upload-box { display: flex; align-items: center; justify-content: space-between; gap: 32px; margin-top: 32px; padding: 36px 40px; border: 1px solid #e4e4e7; border-radius: 28px; background: #ffffff; margin-bottom: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); }
.manual-upload-box__intro { max-width: 600px; }
.manual-upload-box__badge { display: inline-flex; align-items: center; height: 32px; padding: 0 14px; margin-bottom: 16px; border-radius: 10px; background: #f4f4f5; color: #3f3f46; font-size: .8rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; border: 1px solid #e4e4e7; }
.manual-upload-box strong { color: #09090b; font-size: 1.2rem; font-weight: 800; display: block; margin-bottom: 8px; }
.manual-upload-box p { margin: 0; color: #71717a; font-size: 1rem; line-height: 1.7; }
.manual-upload-box__actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.manual-upload-box__submit { background: #09090b !important; color: #ffffff !important; border: none !important; height: 56px; border-radius: 16px; padding: 0 32px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important; }
.manual-upload-box__submit:hover { background: #27272a !important; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important; }

/* Image Placement */
.draft-image-placement-wrap { margin-top: 40px; padding: 48px; border: 1px solid #e4e4e7; border-radius: 32px; background: #fafafa; }
.draft-image-placement__head { margin-bottom: 40px; display: flex; justify-content: space-between; align-items: flex-start; }
.draft-image-placement__title { margin: 0 0 12px; color: #09090b; font-size: 1.35rem; font-weight: 800; }
.draft-image-placement__desc { margin: 0; color: #71717a; font-size: 1.05rem; line-height: 1.6; }
.draft-image-placement__badge { background: #09090b; color: #ffffff; border-radius: 12px; font-weight: 600; padding: 8px 16px; font-size: .8rem; letter-spacing: .02em; display: inline-flex; align-items: center; height: 36px; }

.draft-image-placement__grid { display: grid; grid-template-columns: minmax(0, 1.8fr) minmax(360px, 1.2fr); gap: 32px; }
.draft-image-placement__slots { display: grid; gap: 20px; }

.draft-image-slot { padding: 32px; border: 1px solid #e4e4e7; border-radius: 24px; background: #ffffff; transition: box-shadow 0.3s; overflow:hidden; }
.draft-image-slot:hover { box-shadow: 0 12px 32px -8px rgba(0,0,0,0.06); }
.draft-image-slot__head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; }
.draft-image-slot__title { margin: 0; color: #09090b; font-size: 1.15rem; font-weight: 700; }
.draft-image-slot__meta { color: #71717a; font-size: .85rem; font-weight: 600; background: #f4f4f5; padding: 6px 12px; border-radius: 10px; white-space: nowrap; }
.draft-image-slot__dropzone { min-height: 140px; border: 2px dashed #d4d4d8; border-radius: 20px; background: #fafafa; padding: 20px; transition: all 0.2s; display: flex; flex-direction: column; justify-content: center; }
.draft-image-slot__dropzone.is-over { border-color: #09090b; background: #f4f4f5; }

.draft-image-placement__bank { padding: 32px; border: 1px solid #e4e4e7; border-radius: 24px; background: #ffffff; display: flex; flex-direction: column; height: 100%; box-sizing: border-box; }
.draft-image-placement__bank-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.draft-image-placement__bank-head strong { color: #09090b; font-size: 1.15rem; font-weight: 700; }
.draft-image-placement__bank-head span { color: #3f3f46; background: #f4f4f5; padding: 6px 14px; border-radius: 10px; font-size: .9rem; font-weight: 600; }
.draft-image-placement__bank-drop { flex: 1; border: 2px dashed transparent; border-radius: 20px; padding: 12px; transition: all 0.2s; min-height: 360px; display: flex; flex-direction: column; overflow-y: auto; }
.draft-image-placement__bank-drop.is-over { border-color: #09090b; background: #fafafa; }

/* Image Cards in Drag & Drop */
.draft-image-card { display: flex; align-items: center; gap: 20px; padding: 16px; border: 1px solid #e4e4e7; border-radius: 20px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.02); cursor: grab; transition: transform 0.2s, box-shadow 0.2s; margin-bottom: 12px; min-width: 0; overflow: hidden; }
.draft-image-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px -8px rgba(0,0,0,0.08); }
.draft-image-card:active { cursor: grabbing; }
.draft-image-card__thumb { width: 80px; height: 80px; border-radius: 14px; overflow: hidden; flex-shrink: 0; background: #f4f4f5; }
.draft-image-card__thumb img { width: 100%; height: 100%; object-fit: cover; }
.draft-image-card__body { flex: 1 1 auto; min-width: 0; max-width: 100%; display: flex; flex-direction: column; gap: 6px; overflow: hidden; }
.draft-image-card__name { color: #09090b; font-size: 1rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.4; }
.draft-image-card__summary { color: #71717a; font-size: .9rem; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.draft-image-card__keywords { display: block; margin-top: 4px; color: #52525b; font-size: .8rem; line-height: 1.45; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.draft-image-card__action { flex: 0 0 auto; flex-shrink: 0; max-width: 96px; height: 40px; padding: 0 16px; border-radius: 12px; border: 1px solid #e4e4e7; background: #ffffff; color: #18181b; font-size: .9rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.draft-image-card__action:hover { background: #f4f4f5; border-color: #d4d4d8; }

/* Loading Overlay */
.ai-loading-overlay { position: fixed; inset: 0; background: rgba(9,9,11,0.34); backdrop-filter: blur(6px); z-index: 10020; display: flex; align-items: center; justify-content: center; padding: 24px; }
.ai-loading-overlay__box { width: min(460px, 100%); padding: 30px 28px; border-radius: 24px; background: #ffffff; color: #09090b; box-shadow: 0 24px 60px -24px rgba(0,0,0,0.28); border: 1px solid #e5e7eb; }
.ai-loading-overlay__eyebrow { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 999px; background: #f4f4f5; color: #52525b; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
.ai-loading-overlay__eyebrow::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #2563eb; animation: aiLoadingPulse 1.4s ease-in-out infinite; }
.ai-loading-overlay__top { display: flex; align-items: center; gap: 16px; margin-top: 18px; }
.ai-loading-overlay__visual { width: 44px; height: 44px; flex-shrink: 0; border-radius: 50%; border: 3px solid #e5e7eb; border-top-color: #2563eb; animation: spin 0.9s linear infinite; }
.ai-loading-overlay__title { margin: 0 0 6px; font-size: 1.22rem; font-weight: 800; color: #09090b; letter-spacing: -0.02em; }
.ai-loading-overlay__desc { margin: 0; color: #71717a; font-size: 0.95rem; line-height: 1.65; }
.ai-loading-overlay__stages { display: grid; gap: 10px; margin-top: 18px; }
.ai-loading-overlay__stage { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 14px; background: #fafafa; border: 1px solid #f1f5f9; min-width: 0; }
.ai-loading-overlay__stage.is-active { background: #eff6ff; border-color: #bfdbfe; }
.ai-loading-overlay__stage-num { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #e5e7eb; color: #52525b; font-size: 0.72rem; font-weight: 800; flex-shrink: 0; }
.ai-loading-overlay__stage.is-active .ai-loading-overlay__stage-num { background: #2563eb; color: #ffffff; }
.ai-loading-overlay__stage-copy { min-width: 0; }
.ai-loading-overlay__stage-title { display: block; color: #18181b; font-size: 0.9rem; font-weight: 700; line-height: 1.35; }
.ai-loading-overlay__stage-desc { display: block; margin-top: 3px; color: #71717a; font-size: 0.8rem; line-height: 1.5; }
.ai-loading-overlay__ticker { display: flex; align-items: center; gap: 8px; margin-top: 16px; color: #52525b; font-size: 0.88rem; }
.ai-loading-overlay__ticker::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #2563eb; animation: aiLoadingPulse 1.2s ease-in-out infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes aiLoadingPulse { 0%,100% { transform: scale(1); opacity: 0.85; } 50% { transform: scale(1.25); opacity: 1; } }

/* Exceptions */
.ai-result-wrap .chat-card { box-shadow: none; border-radius: 0; transform: none; border: none; border-top: 1px solid #e5e7eb; margin-bottom: 0; background: transparent; padding: 0; }
.ai-result-wrap .chat-card__head { padding: 48px 48px 0; }
.ai-result-wrap .chat-card__body { padding: 32px 48px 48px; }

/* Responsive */
@media (max-width: 1024px) {
    .chat-hero { flex-direction: column; align-items: flex-start; }
    .chat-hero__meta { flex-direction: row; flex-wrap: wrap; min-width: 0; align-items: flex-start; }
    .ai-result-grid, .draft-image-placement__grid { grid-template-columns: 1fr; }
    .manual-upload-box, .chat-upload-inline__head, .draft-image-placement__head { flex-direction: column; align-items: flex-start; }
    .chat-progress-bar__steps { overflow-x: auto; justify-content: flex-start; padding-bottom: 4px; }
    .ai-loading-overlay__top { align-items: flex-start; }
}
@media (max-width: 768px) {
    .chat-hero, .chat-card__head, .chat-card__body, .ai-result-wrap .chat-card__head, .ai-result-wrap .chat-card__body, .ai-result-grid { padding: 24px; }
    .ai-result-grid { padding-bottom: 32px; }
    .chat-final-actions { flex-direction: column; padding-top: 24px; }
    .chat-final-actions .btn { width: 100%; }
    .ai-result-actions { padding: 0 24px 24px; }
    .ai-result-actions .btn { width: 100%; }
    .ai-panel, .draft-image-placement-wrap, .draft-image-slot, .draft-image-placement__bank { padding: 24px; }
    .manual-upload-box { padding: 24px; gap: 20px; }
    .image-library__item { flex-direction: column; align-items: stretch; text-align: center; }
    .image-library__thumb { width: 100%; height: 200px; }
    .image-library__badge { align-self: center; }
    .draft-image-card { flex-direction: column; text-align: center; }
    .draft-image-card__thumb { width: 100%; height: 200px; }
    .chat-progress-bar { padding: 18px 16px; }
    .chat-progress-step__line { min-width: 28px; max-width: 44px; }
    .chat-progress-step__label { font-size: 0.72rem; }
    .ai-loading-overlay__box { padding: 24px 20px; }
    .ai-loading-overlay__top { align-items: center; }
}
</style>

<script>
(function () {
    var caseId = <?= (int)$caseId ?>;
    var caseInputType = <?= json_encode($type, JSON_UNESCAPED_UNICODE) ?>;
    var caseTypeLabel = <?= json_encode($typeCfg['label'], JSON_UNESCAPED_UNICODE) ?>;
    var typeQuestionLabels = <?= json_encode([$typeCfg['q1'], $typeCfg['q2'], $typeCfg['q3']], JSON_UNESCAPED_UNICODE) ?>;
    var caseImageAssets = <?= $caseImageLibraryJson ?>;
    var imageAssetsById = {};
    var initialImageLayout = <?= $savedImageLayoutJson ?>;
    var savedTitleCandidates = <?= $titleCandidatesJson ?>;
    var promptHash = <?= json_encode($promptHash, JSON_UNESCAPED_UNICODE) ?>;
    var question2Questions = <?= json_encode($question2Questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var question3Questions = <?= json_encode($question3Questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var lastAutoKeywords = '';
    var cachedExamples = [];
    var currentExampleIndex = 0;
    var fileBasedExamples = null;
    var fileBasedQuestions = null;
    var pendingUploadFiles = {
        caseImagesInput: [],
        manualImagesInput: []
    };
    var isAiImageAutoUploading = false;
    var queuedAiImageAutoUpload = false;
    var aiImageAutoUploadPromise = Promise.resolve();
    var imageLayoutSaveTimer = null;
    var isImageLayoutSaving = false;

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function rebuildImageAssetMap() {
        imageAssetsById = {};
        (caseImageAssets || []).forEach(function (asset) {
            if (asset && asset.id) {
                imageAssetsById[String(asset.id)] = asset;
            }
        });
    }

    function renderSavedImageList() {
        var wrap = document.getElementById('savedImageList');
        var countEl = document.getElementById('savedImageCount');
        if (!wrap || !countEl) return;

        countEl.textContent = (caseImageAssets || []).length + '장';
        if (!caseImageAssets.length) {
            wrap.innerHTML = '<div class="draft-image-placement__empty">아직 저장된 이미지가 없습니다.</div>';
            return;
        }

        wrap.innerHTML = caseImageAssets.map(function (asset) {
            return '' +
                '<div class="image-library__item">' +
                    '<div class="image-library__thumb"><img src="' + escapeHtml(asset.url) + '" alt="' + escapeHtml(asset.name) + '"></div>' +
                    '<div class="image-library__body">' +
                        '<strong class="image-library__name" title="' + escapeHtml(asset.name) + '">' + escapeHtml(asset.name) + '</strong>' +
                        '<span class="image-library__summary">' + escapeHtml(asset.summary || asset.description || '이미지 설명 없음') + '</span>' +
                        '<span class="image-library__badge ' + (asset.has_meta ? 'has-meta' : 'no-meta') + '">' + (asset.has_meta ? 'AI 분석 완료' : '수동 추가') + '</span>' +
                    '</div>' +
                '</div>';
        }).join('');
    }

    function renderSelectedFiles(inputId, targetId, label) {
        var target = document.getElementById(targetId);
        if (!target) return;
        var files = Array.isArray(pendingUploadFiles[inputId]) ? pendingUploadFiles[inputId] : [];
        if (!files.length) {
            target.innerHTML = '';
            return;
        }
        target.innerHTML = files.map(function (file) {
            return '<div class="selected-files__item">' + escapeHtml(label + ': ' + file.name) + '</div>';
        }).join('');
    }

    function fileSignature(file) {
        return [
            file && file.name ? file.name : '',
            file && typeof file.size === 'number' ? file.size : 0,
            file && typeof file.lastModified === 'number' ? file.lastModified : 0
        ].join('__');
    }

    function syncInputFiles(inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        if (typeof DataTransfer === 'undefined') return;
        var dt = new DataTransfer();
        (pendingUploadFiles[inputId] || []).forEach(function (file) {
            dt.items.add(file);
        });
        input.files = dt.files;
    }

    function updateSelectedFileViews() {
        renderSelectedFiles('caseImagesInput', 'selectedAiImageList', 'AI 분석 이미지');
        renderSelectedFiles('manualImagesInput', 'selectedManualImageList', '수동 추가 이미지');
    }

    function appendFilesToInput(inputId, fileList) {
        var incoming = Array.prototype.slice.call(fileList || []).filter(function (file) {
            return file && /^image\//i.test(file.type || '');
        });
        if (!incoming.length) return 0;

        var merged = (pendingUploadFiles[inputId] || []).slice();
        var seen = {};
        merged.forEach(function (file) {
            seen[fileSignature(file)] = true;
        });

        var added = 0;
        incoming.forEach(function (file) {
            var signature = fileSignature(file);
            if (seen[signature]) return;
            seen[signature] = true;
            merged.push(file);
            added += 1;
        });

        pendingUploadFiles[inputId] = merged;
        syncInputFiles(inputId);
        updateSelectedFileViews();
        return added;
    }

    function renderFollowupList(targetId, questions) {
        var target = document.getElementById(targetId);
        if (!target) return;
        questions = Array.isArray(questions) ? questions : [];
        target.innerHTML = questions.map(function (question, index) {
            return '<div class="chat-followup-item"><strong>추가 질문 ' + (index + 1) + '.</strong> ' + escapeHtml(question) + '</div>';
        }).join('');
    }

    function buildRawContent() {
        var q1 = (document.getElementById('question1Answer').value || '').trim();
        var q2 = (document.getElementById('question2Answer').value || '').trim();
        var blocks = [];

        if (q1) {
            blocks.push(
                '[질문1]\n' +
                '1. ' + typeQuestionLabels[0] + '\n' +
                '2. ' + typeQuestionLabels[1] + '\n' +
                '3. ' + typeQuestionLabels[2] + '\n\n' +
                '답변:\n' + q1
            );
        }

        if (question2Questions.length || q2) {
            var lines2 = ['[질문2]'];
            question2Questions.forEach(function (question, index) {
                lines2.push((index + 1) + '. ' + question);
            });
            if (q2) {
                lines2.push('', '답변:', q2);
            }
            blocks.push(lines2.join('\n'));
        }

        var raw = blocks.join('\n\n').trim();
        document.getElementById('rawContentHidden').value = raw;
        return raw;
    }

    function deriveCaseTitle() {
        var q1 = (document.getElementById('question1Answer').value || '').trim();
        var plain = q1.replace(/\s+/g, ' ').trim();
        var title = plain ? plain.slice(0, 36) : (caseTypeLabel + ' 사례');
        document.getElementById('caseTitleHidden').value = title;
        return title;
    }

    function uniqueTextList(values) {
        var seen = {};
        return (Array.isArray(values) ? values : []).map(function (value) {
            return String(value || '').replace(/\s+/g, ' ').trim();
        }).filter(function (value) {
            if (!value) return false;
            var key = value.toLowerCase();
            if (seen[key]) return false;
            seen[key] = true;
            return true;
        });
    }

    function buildSuggestedKeywords() {
        var aiTitle = (document.getElementById('aiTitle') && document.getElementById('aiTitle').value || '').trim();
        var subjectLabel = (document.getElementById('aiSubjectLabel') && document.getElementById('aiSubjectLabel').value || '').trim();
        var caseCategory = (document.getElementById('aiCaseCategory') && document.getElementById('aiCaseCategory').value || '').trim();
        var derivedTitle = deriveCaseTitle();

        return uniqueTextList([
            aiTitle,
            derivedTitle,
            subjectLabel,
            caseCategory
        ]).slice(0, 4).join(', ');
    }

    function syncTargetKeywords(force) {
        var targetInput = document.getElementById('targetKeywords');
        if (!targetInput) return;

        var current = (targetInput.value || '').trim();
        var suggested = buildSuggestedKeywords();
        if (!suggested) return;

        if (force || current === '' || current === lastAutoKeywords) {
            targetInput.value = suggested;
            lastAutoKeywords = suggested;
        }
    }

    function showLoading(title, desc) {
        hideLoading();
        var meta = getLoadingMeta(title, desc);
        var overlay = document.createElement('div');
        overlay.className = 'ai-loading-overlay';
        overlay.id = 'chatLoadingOverlay';
        overlay.innerHTML = '' +
            '<div class="ai-loading-overlay__box">' +
                '<span class="ai-loading-overlay__eyebrow">' + escapeHtml(meta.eyebrow) + '</span>' +
                '<div class="ai-loading-overlay__top">' +
                    '<div class="ai-loading-overlay__visual"></div>' +
                    '<div>' +
                        '<h4 class="ai-loading-overlay__title">' + escapeHtml(meta.title) + '</h4>' +
                        '<p class="ai-loading-overlay__desc">' + escapeHtml(meta.desc) + '</p>' +
                    '</div>' +
                '</div>' +
                '<div class="ai-loading-overlay__stages">' +
                    meta.stages.map(function (stage, idx) {
                        return '' +
                            '<div class="ai-loading-overlay__stage' + (idx === meta.activeIndex ? ' is-active' : '') + '">' +
                                '<span class="ai-loading-overlay__stage-num">' + (idx + 1) + '</span>' +
                                '<span class="ai-loading-overlay__stage-copy">' +
                                    '<span class="ai-loading-overlay__stage-title">' + escapeHtml(stage.title) + '</span>' +
                                    '<span class="ai-loading-overlay__stage-desc">' + escapeHtml(stage.desc) + '</span>' +
                                '</span>' +
                            '</div>';
                    }).join('') +
                '</div>' +
                '<div class="ai-loading-overlay__ticker">' + escapeHtml(meta.ticker) + '</div>' +
            '</div>';
        document.body.appendChild(overlay);
    }

    function hideLoading() {
        var overlay = document.getElementById('chatLoadingOverlay');
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }

    function callChatAi(payload) {
        return fetch('case_typed_chat_ai_.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify(payload)
        }).then(parseJsonResponse);
    }

    function parseJsonResponse(res) {
        return res.text().then(function (text) {
            var parsed;
            try {
                parsed = JSON.parse(text);
            } catch (error) {
                throw new Error('서버 응답을 읽는 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.');
            }
            return parsed;
        });
    }

    function clearFileInput(inputId, targetId) {
        var input = document.getElementById(inputId);
        var target = document.getElementById(targetId);
        pendingUploadFiles[inputId] = [];
        if (input) input.value = '';
        if (target) target.innerHTML = '';
        updateProgressBar();
    }

    function updateProgressBar() {
        var steps = [
            { key: 'question1', check: function () { return (document.getElementById('question1Answer').value || '').trim() !== ''; } },
            { key: 'question2', check: function () { return (document.getElementById('question2Answer').value || '').trim() !== ''; } },
            { key: 'image', check: function () {
                var hasExisting = (caseImageAssets || []).length > 0;
                var hasPendingAi = (pendingUploadFiles.caseImagesInput || []).length > 0;
                var hasPendingManual = (pendingUploadFiles.manualImagesInput || []).length > 0;
                return hasExisting || hasPendingAi || hasPendingManual;
            }},
            { key: 'analyze', check: function () {
                return (document.getElementById('aiTitle').value || '').trim() !== '' || (document.getElementById('aiSummary').value || '').trim() !== '';
            }},
            { key: 'save', check: function () { return caseId > 0; } }
        ];

        var lastDone = -1;
        for (var i = 0; i < steps.length; i++) {
            if (steps[i].check()) lastDone = i;
            else break;
        }

        document.querySelectorAll('.chat-progress-step').forEach(function (el, idx) {
            el.classList.remove('is-done', 'is-active');
            if (idx <= lastDone) {
                el.classList.add('is-done');
            } else if (idx === lastDone + 1) {
                el.classList.add('is-active');
            }
        });

        document.querySelectorAll('.chat-progress-step__line').forEach(function (el, idx) {
            el.classList.remove('is-done');
            if (idx <= lastDone - 1) {
                el.classList.add('is-done');
            }
        });
    }

    function getLoadingMeta(title, desc) {
        var text = String(title || '');
        var profile = {
            eyebrow: 'AI Analysis Engine',
            ticker: '문맥 추출, 키워드 정리, 이미지 연관성 계산을 순차적으로 수행하고 있습니다.',
            stages: [
                { title: '입력 데이터 정리', desc: '상담 답변과 이미지 정보를 구조화합니다.' },
                { title: '의도 및 맥락 분석', desc: '핵심 흐름과 사용자 의도를 해석합니다.' },
                { title: '출력 초안 생성', desc: '블로그 구조와 후속 결과를 조합합니다.' }
            ],
            activeIndex: 1
        };

        if (text.indexOf('예제') !== -1) {
            profile.eyebrow = 'Example Generation';
            profile.ticker = '브랜드 프롬프트를 반영해 현실적인 예시를 생성하고 있습니다.';
            profile.stages = [
                { title: '브랜드 톤 분석', desc: '업종과 서비스 특성을 읽어옵니다.' },
                { title: '상황 시나리오 생성', desc: '질문 유형에 맞는 사례를 조합합니다.' },
                { title: '예시 문장 다듬기', desc: '바로 복사 가능한 형태로 정리합니다.' }
            ];
            profile.activeIndex = 2;
        } else if (text.indexOf('질문 2') !== -1 || text.indexOf('질문 3') !== -1 || text.indexOf('추가 질문') !== -1) {
            profile.eyebrow = 'Conversation Analysis';
            profile.ticker = '답변의 빈 정보를 찾아 다음 질문을 설계하고 있습니다.';
            profile.stages = [
                { title: '답변 핵심 추출', desc: '현재까지 입력된 상담 내용을 읽어옵니다.' },
                { title: '부족 정보 탐색', desc: '추가 확인이 필요한 지점을 찾습니다.' },
                { title: '질문 생성', desc: '구체적인 후속 질문을 정리합니다.' }
            ];
            profile.activeIndex = 2;
        } else if (text.indexOf('이미지') !== -1) {
            profile.eyebrow = 'Image Intake Pipeline';
            profile.ticker = '업로드 파일을 정리하고 보관함 상태를 최신으로 반영하고 있습니다.';
            profile.stages = [
                { title: '파일 정리', desc: '선택한 이미지를 업로드 큐에 등록합니다.' },
                { title: '메타 연결', desc: '기존 사례 정보와 이미지를 연결합니다.' },
                { title: '보관함 갱신', desc: '배치 가능한 상태로 목록을 업데이트합니다.' }
            ];
            profile.activeIndex = 1;
        } else if (text.indexOf('저장') !== -1) {
            profile.eyebrow = 'Save Transaction';
            profile.ticker = '상담 내용과 이미지 상태를 안전하게 저장하고 있습니다.';
            profile.stages = [
                { title: '입력값 검증', desc: '필수 데이터와 현재 상태를 확인합니다.' },
                { title: '사례 저장', desc: '본문/구조화 데이터와 함께 기록합니다.' },
                { title: '후속 반영', desc: '저장 후 화면 상태를 동기화합니다.' }
            ];
            profile.activeIndex = 1;
        }

        if (text.indexOf('상담 내용을 저장') !== -1 || text.indexOf('AI 분석') !== -1) {
            profile.eyebrow = 'Case Intelligence Pipeline';
            profile.ticker = '텍스트와 이미지 맥락을 결합해 구조와 본문 초안을 계산하고 있습니다.';
            profile.stages = [
                { title: '데이터 저장 및 정리', desc: '질문 답변과 업로드 이미지를 먼저 정리합니다.' },
                { title: '구조 분석', desc: '사례 유형, 제목, 요약, H2 흐름을 설계합니다.' },
                { title: '본문 초안 생성', desc: '이미지 배치까지 포함한 글 초안을 완성합니다.' }
            ];
            profile.activeIndex = 2;
        }

        profile.title = title || 'AI 처리 중';
        profile.desc = desc || '잠시만 기다려주세요.';
        return profile;
    }

    function updateQuestionStateUI() {
        document.getElementById('question2QuestionsJson').value = JSON.stringify(question2Questions || []);
        renderFollowupList('question2List', question2Questions);

        var q2Card = document.getElementById('question2Card');
        var finalActions = document.getElementById('chatFinalActions');
        var aiResultVisible = document.getElementById('aiResultWrap').style.display !== 'none';

        q2Card.style.display = (question2Questions.length || document.getElementById('question2Answer').value.trim()) ? '' : 'none';
        if (finalActions) {
            finalActions.style.display = (!aiResultVisible && (question2Questions.length > 0 || document.getElementById('question2Answer').value.trim() !== '')) ? '' : 'none';
        }
        updateProgressBar();
    }

    function renderTitleCandidates(candidates) {
        var wrap = document.getElementById('titleCandidatesWrap');
        var list = document.getElementById('titleCandidatesList');
        var hidden = document.getElementById('titleCandidatesJson');
        if (!wrap || !list) return;
        savedTitleCandidates = Array.isArray(candidates) ? candidates : [];
        hidden.value = JSON.stringify(savedTitleCandidates);
        if (!savedTitleCandidates.length) {
            wrap.style.display = 'none';
            list.innerHTML = '';
            return;
        }
        wrap.style.display = '';
        var current = (document.getElementById('aiTitle').value || '').trim();
        list.innerHTML = savedTitleCandidates.map(function (title, index) {
            var cls = title === current || (!current && index === 0) ? 'title-candidate-btn is-selected' : 'title-candidate-btn';
            return '<button type="button" class="' + cls + '" data-candidate-idx="' + index + '">' + escapeHtml(title) + '</button>';
        }).join('');
        if (!current && savedTitleCandidates[0]) {
            document.getElementById('aiTitle').value = savedTitleCandidates[0];
        }
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

    function fillH2Sections(sections) {
        var inputs = document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]');
        inputs.forEach(function (input, index) {
            input.value = (Array.isArray(sections) && sections[index]) ? sections[index] : '';
        });
        renderImagePlacementUI();
    }

    var imagePlacementState = { slots: {} };

    function getSectionDefinitions() {
        var defs = [{ key: 'intro', title: '도입부', helper: '본문 시작 부분에 들어갈 이미지' }];
        document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]').forEach(function (input, index) {
            var title = (input.value || '').trim();
            if (title) defs.push({ key: 'h2-' + (index + 1), title: title, helper: '이 H2 구간에 최대 2장' });
        });
        return defs;
    }

    function normalizeSlotIds(ids) {
        if (!Array.isArray(ids)) return [];
        var out = [];
        ids.forEach(function (value) {
            var key = String(parseInt(value, 10) || 0);
            if (key && key !== '0' && imageAssetsById[key] && out.indexOf(key) === -1) out.push(key);
        });
        return out.slice(0, 2);
    }

    function normalizeImagePlacementState(rawState, defs) {
        var normalized = { slots: {} };
        var taken = {};
        var inputSlots = rawState && typeof rawState === 'object' && rawState.slots && typeof rawState.slots === 'object' ? rawState.slots : {};
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
        defs.forEach(function (def) { base.slots[def.key] = []; });
        var taken = {};
        (Array.isArray(recommendations) ? recommendations : []).forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            var slotKey = String(item.section_key || '').trim();
            if (!base.slots.hasOwnProperty(slotKey)) return;
            normalizeSlotIds(item.recommended_image_ids || []).forEach(function (id) {
                if (taken[id] || base.slots[slotKey].length >= 2) return;
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
        document.getElementById('aiImageLayoutJson').value = JSON.stringify(imagePlacementState);
    }

    function createImageCardHtml(asset, removeLabel) {
        var keywords = Array.isArray(asset.keywords) ? asset.keywords.slice(0, 4).join(', ') : '';
        return '' +
            '<article class="draft-image-card" draggable="true" data-file-id="' + escapeHtml(asset.id) + '">' +
                '<div class="draft-image-card__thumb"><img src="' + escapeHtml(asset.url) + '" alt="' + escapeHtml(asset.name) + '"></div>' +
                '<div class="draft-image-card__body">' +
                    '<strong class="draft-image-card__name">' + escapeHtml(asset.name) + '</strong>' +
                    '<div class="draft-image-card__summary">' + escapeHtml(asset.summary || asset.description || '이미지 메타 요약 없음') + '</div>' +
                    (keywords ? '<span class="draft-image-card__keywords">' + escapeHtml(keywords) + '</span>' : '') +
                '</div>' +
                (removeLabel ? '<button type="button" class="draft-image-card__action" data-remove-file-id="' + escapeHtml(asset.id) + '">' + escapeHtml(removeLabel) + '</button>' : '') +
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
            slotsWrap.innerHTML = '<div class="draft-image-placement__empty">저장된 이미지가 없습니다. 이미지를 저장한 뒤 다시 시도해주세요.</div>';
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
            if (!cardsHtml) cardsHtml = '<div class="draft-image-slot__empty">여기에 이미지를 드래그해 넣으세요.<br>최대 2장까지 배치됩니다.</div>';
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
        bankWrap.innerHTML = unusedIds.length
            ? '<div class="draft-image-placement__bank-cards">' + unusedIds.map(function (id) {
                var asset = imageAssetsById[id];
                return asset ? createImageCardHtml(asset, '') : '';
            }).join('') + '</div>'
            : '<div class="draft-image-placement__empty">남은 이미지가 없습니다.</div>';

        updateImageLayoutField();
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
            if (!imagePlacementState.slots.hasOwnProperty(targetSlotKey)) return;
            if ((imagePlacementState.slots[targetSlotKey] || []).length >= 2) {
                alert('한 섹션에는 이미지를 최대 2장까지 넣을 수 있습니다.');
                return;
            }
            imagePlacementState.slots[targetSlotKey].push(String(fileId));
        }
        renderImagePlacementUI();
        scheduleImageLayoutSave();
    }

    function applyImageRecommendations(recommendations) {
        var defs = getSectionDefinitions();
        imagePlacementState = buildStateFromRecommendations(defs, recommendations || []);
        renderImagePlacementUI();
    }

    function setImageAssets(newAssets) {
        caseImageAssets = Array.isArray(newAssets) ? newAssets : [];
        rebuildImageAssetMap();
        renderSavedImageList();
        renderImagePlacementUI();
        updateProgressBar();
    }

    function saveCaseOnly(options) {
        options = options || {};
        buildRawContent();
        deriveCaseTitle();

        var form = document.getElementById('chatCaseForm');
        var formData = new FormData(form);
        formData.append('response_format', 'json');
        (options.excludeFields || []).forEach(function (fieldName) {
            formData.delete(fieldName);
        });

        return fetch('case_typed_chat_submit.php', {
            method: 'POST',
            body: formData
            }).then(parseJsonResponse).then(function (json) {
            if (!json.success) {
                throw new Error(json.error || '저장에 실패했습니다.');
            }
            caseId = parseInt(json.case_id, 10) || 0;
            document.getElementById('caseId').value = String(caseId);
            if (Array.isArray(json.image_assets)) {
                setImageAssets(json.image_assets);
            }
            var clearTargets = Array.isArray(options.clearInputs) ? options.clearInputs : ['caseImagesInput', 'manualImagesInput'];
            clearTargets.forEach(function (inputId) {
                if (inputId === 'caseImagesInput') {
                    clearFileInput('caseImagesInput', 'selectedAiImageList');
                } else if (inputId === 'manualImagesInput') {
                    clearFileInput('manualImagesInput', 'selectedManualImageList');
                }
            });
            if (json.redirect_url) {
                history.replaceState({}, '', json.redirect_url);
            }
            if (!options.silentMessage) {
                alert(options.message || '저장되었습니다.');
            }
            return json;
        });
    }

    function scheduleImageLayoutSave() {
        if (!caseId || isAiImageAutoUploading || isImageLayoutSaving) return;
        if (imageLayoutSaveTimer) {
            clearTimeout(imageLayoutSaveTimer);
        }
        imageLayoutSaveTimer = setTimeout(function () {
            imageLayoutSaveTimer = null;
            isImageLayoutSaving = true;
            saveCaseOnly({
                silentMessage: true,
                clearInputs: []
            }).catch(function () {
                // Drag/drop autosave should fail silently.
            }).finally(function () {
                isImageLayoutSaving = false;
            });
        }, 350);
    }

    function triggerAiImageAutoUpload() {
        var pendingAiFiles = pendingUploadFiles.caseImagesInput || [];
        var q1 = (document.getElementById('question1Answer').value || '').trim();
        if (!pendingAiFiles.length || !q1) {
            return isAiImageAutoUploading ? aiImageAutoUploadPromise : Promise.resolve(null);
        }
        if (isAiImageAutoUploading) {
            queuedAiImageAutoUpload = true;
            return aiImageAutoUploadPromise;
        }

        isAiImageAutoUploading = true;
        showLoading('이미지를 분석하고 있습니다', '업로드한 이미지를 먼저 저장하고 AI 메타 분석을 진행합니다.');
        aiImageAutoUploadPromise = saveCaseOnly({
            silentMessage: true,
            clearInputs: ['caseImagesInput'],
            excludeFields: ['manual_images[]']
        }).then(function (json) {
            hideLoading();
            return json;
        }).catch(function (error) {
            hideLoading();
            throw error;
        }).finally(function () {
            isAiImageAutoUploading = false;
            if (queuedAiImageAutoUpload) {
                queuedAiImageAutoUpload = false;
                if ((pendingUploadFiles.caseImagesInput || []).length && (document.getElementById('question1Answer').value || '').trim()) {
                    setTimeout(function () {
                        triggerAiImageAutoUpload();
                    }, 0);
                }
            }
        });

        return aiImageAutoUploadPromise;
    }

    function validateBeforeGenerate() {
        var q1 = (document.getElementById('question1Answer').value || '').trim();
        var q2 = (document.getElementById('question2Answer').value || '').trim();
        if (!q1) return '질문 1 답변을 먼저 입력해주세요.';
        if (!q2) return '질문 2 답변을 먼저 입력해주세요.';
        return '';
    }

    function showAiResultWrap() {
        document.getElementById('aiResultWrap').style.display = '';
        updateQuestionStateUI();
        updateProgressBar();
    }

    function renderExample(index) {
        var listEl = document.getElementById('examplePanelList');
        if (!listEl) return;
        if (!cachedExamples || !cachedExamples.length) {
             listEl.innerHTML = '<div class="example-panel__loading">예시를 불러오지 못했습니다.</div>';
             return;
        }
        if (index < 0) index = cachedExamples.length - 1;
        if (index >= cachedExamples.length) index = 0;
        currentExampleIndex = index;

        var ex = cachedExamples[index];
        var labels = (fileBasedQuestions && fileBasedExamples && cachedExamples === fileBasedExamples)
            ? fileBasedQuestions
            : typeQuestionLabels;
        var combined;
        if (ex.q1) {
            combined = '1. ' + (labels[0] || '질문 1') + '\n' + ex.q1 + '\n\n2. ' + (labels[1] || '질문 2') + '\n' + ex.q2 + '\n\n3. ' + (labels[2] || '질문 3') + '\n' + ex.q3;
        } else if (ex.content) {
            combined = ex.content;
        } else {
            combined = '(예시 내용 없음)';
        }

        listEl.innerHTML = '' +
            '<div class="example-panel__item">' +
                '<div class="example-panel__nav">' +
                    '<button type="button" class="example-panel__btn-prev">이전</button>' +
                    '<h5 class="example-panel__item-title">' + escapeHtml(ex.title || ('예시 ' + (index + 1))) + ' <span>(' + (index + 1) + '/' + cachedExamples.length + ')</span></h5>' +
                    '<button type="button" class="example-panel__btn-next">다음</button>' +
                '</div>' +
                '<div class="example-panel__item-body">' + escapeHtml(combined) + '</div>' +
                '<button type="button" class="btn chat-card__ghost" data-example-value="' + escapeHtml(combined) + '">현재 예시 내용 복사하여 적용하기</button>' +
            '</div>';
    }

    function loadExamples() {
        var listEl = document.getElementById('examplePanelList');
        if (!listEl) return;

        if (fileBasedExamples && fileBasedExamples.length > 0) {
            cachedExamples = fileBasedExamples;
            currentExampleIndex = 0;
            var descEl = document.getElementById('examplePanelDesc');
            if (descEl) descEl.textContent = '첨부 문서 기반으로 생성된 예시입니다.';
            renderExample(currentExampleIndex);
            return;
        }

        if (!promptHash) {
            listEl.innerHTML = '<div class="example-panel__loading">프롬프트를 먼저 설정하거나, 참고 자료를 첨부해주세요.</div>';
            return;
        }

        showLoading('맞춤 예제를 생성하고 있습니다', '내 브랜드와 사례 유형에 맞는 모범 답변을 만들고 있습니다. (약 5~10초 소요)');
        fetch('case_type_examples_ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ case_type: caseInputType })
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            hideLoading();
            if (!(json.success && Array.isArray(json.data) && json.data.length)) {
                listEl.innerHTML = '<div class="example-panel__loading">예시를 불러오지 못했습니다.</div>';
                return;
            }
            cachedExamples = json.data;
            currentExampleIndex = 0;
            renderExample(currentExampleIndex);
        })
        .catch(function () {
            hideLoading();
            listEl.innerHTML = '<div class="example-panel__loading">예시를 불러오지 못했습니다.</div>';
        });
    }

    function runGenerateFlow() {
        var err = validateBeforeGenerate();
        if (err) {
            alert(err);
            return;
        }

        triggerAiImageAutoUpload()
            .then(function () {
                showLoading('상담 내용을 저장하고 있습니다', '이미지 저장과 AI 분석을 순차적으로 진행합니다.');
                return saveCaseOnly({ silentMessage: true });
            })
            .then(function () {
                buildRawContent();
                return callChatAi({
                    mode: 'analyze',
                    case_id: caseId,
                    case_input_type: caseInputType,
                    case_title: deriveCaseTitle(),
                    raw_content: document.getElementById('rawContentHidden').value,
                    target_keywords: document.getElementById('targetKeywords').value || ''
                });
            })
            .then(function (json) {
                if (!json.success || !json.data) {
                    throw new Error(json.error || 'AI 분석에 실패했습니다.');
                }
                showAiResultWrap();
                fillStructuredFields(json.data.structured || {});
                renderTitleCandidates(json.data.title_candidates || []);
                if (json.data.summary) document.getElementById('aiSummary').value = json.data.summary;
                fillH2Sections(json.data.h2_sections || []);
                document.getElementById('aiStatusInput').value = 'done';
                syncTargetKeywords(true);

                return callChatAi({
                    mode: 'draft',
                    case_id: caseId,
                    case_input_type: caseInputType,
                    case_title: deriveCaseTitle(),
                    raw_content: document.getElementById('rawContentHidden').value,
                    target_keywords: document.getElementById('targetKeywords').value || '',
                    ai_title: document.getElementById('aiTitle').value || '',
                    ai_summary: document.getElementById('aiSummary').value || '',
                    ai_h2_sections: Array.prototype.slice.call(document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]')).map(function (input) { return input.value || ''; }),
                    structured: {
                        industry_category: document.getElementById('aiIndustryCategory').value || '',
                        case_category: document.getElementById('aiCaseCategory').value || '',
                        subject_label: document.getElementById('aiSubjectLabel').value || '',
                        problem_summary: document.getElementById('aiProblemSummary').value || '',
                        process_summary: document.getElementById('aiProcessSummary').value || '',
                        result_summary: document.getElementById('aiResultSummary').value || ''
                    }
                });
            })
            .then(function (json) {
                if (!json.success || !json.data) {
                    throw new Error(json.error || '본문 초안 생성에 실패했습니다.');
                }
                document.getElementById('aiBodyDraft').value = json.data.body_draft || '';
                applyImageRecommendations(json.data.image_placements || []);
                document.getElementById('aiStatusInput').value = 'done';
                return saveCaseOnly({ silentMessage: true });
            })
            .then(function () {
                hideLoading();
                alert('글 구조와 본문 초안 생성이 완료되었습니다.');
                document.getElementById('aiResultWrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function (error) {
                hideLoading();
                alert(error && error.message ? error.message : '처리 중 오류가 발생했습니다.');
            });
    }

    document.getElementById('btnQuestion1Complete').addEventListener('click', function () {
        var q1 = (document.getElementById('question1Answer').value || '').trim();
        if (!q1) {
            alert('질문 1 답변을 먼저 입력해주세요.');
            return;
        }
        showLoading('질문 2를 준비하고 있습니다', '상담 내용을 읽고 추가 질문을 만들고 있습니다.');
        var followupPayload = {
            mode: 'followup2',
            case_input_type: caseInputType,
            question1_answer: q1
        };
        var summaryEl = document.getElementById('refFileSummary');
        if (summaryEl && summaryEl.textContent.trim() !== '') {
            followupPayload.file_summary = summaryEl.textContent.trim();
        }
        callChatAi(followupPayload).then(function (json) {
            hideLoading();
            if (!json.success || !json.data || !Array.isArray(json.data.questions)) {
                throw new Error(json.error || '질문 2 생성에 실패했습니다.');
            }
            question2Questions = json.data.questions.slice(0, 1);
            document.getElementById('question3Skipped').value = '1';
            question3Questions = [];
            document.getElementById('question2Card').style.display = '';
            updateQuestionStateUI();
            document.getElementById('question2Card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }).catch(function (error) {
            hideLoading();
            alert(error && error.message ? error.message : '질문 2 생성에 실패했습니다.');
        });
    });

    document.getElementById('btnSaveOnly').addEventListener('click', function () {
        buildRawContent();
        if (!(document.getElementById('question1Answer').value || '').trim()) {
            alert('질문 1 답변을 입력한 뒤 저장해주세요.');
            return;
        }
        triggerAiImageAutoUpload()
            .then(function () {
                showLoading('저장 중입니다', '현재 상담 내용과 이미지를 저장하고 있습니다.');
                return saveCaseOnly({ silentMessage: true });
            })
            .then(function () {
                hideLoading();
                alert('현재 내용이 저장되었습니다.');
            })
            .catch(function (error) {
                hideLoading();
                alert(error && error.message ? error.message : '저장에 실패했습니다.');
            });
    });

    document.getElementById('btnSaveGenerate').addEventListener('click', runGenerateFlow);

    document.getElementById('btnAddManualImages').addEventListener('click', function () {
        var files = document.getElementById('manualImagesInput').files || [];
        if (!files.length) {
            alert('추가할 이미지를 먼저 선택해주세요.');
            return;
        }
        if (!(document.getElementById('question1Answer').value || '').trim()) {
            alert('질문 1 답변을 먼저 입력한 뒤 이미지를 보관함에 추가해주세요.');
            return;
        }

        triggerAiImageAutoUpload()
            .then(function () {
                showLoading('이미지 보관함에 추가 중입니다', '선택한 이미지를 저장하고 보관함에 반영하고 있습니다.');
                return saveCaseOnly({
                    silentMessage: true,
                    clearInputs: ['manualImagesInput'],
                    excludeFields: ['case_images[]']
                });
            })
            .then(function () {
                hideLoading();
                alert('이미지가 보관함에 추가되었습니다. 이제 드래그 앤 드랍으로 원하는 본문 위치에 배치할 수 있습니다.');
            })
            .catch(function (error) {
                hideLoading();
                alert(error && error.message ? error.message : '이미지 추가에 실패했습니다.');
            });
    });

    // ── 참고 자료 파일 분석 ──────────────────────────────
    var refFileInput = document.getElementById('refFileInput');
    var btnAnalyzeFile = document.getElementById('btnAnalyzeFile');

    if (refFileInput) {
        refFileInput.addEventListener('change', function () {
            if (btnAnalyzeFile) {
                btnAnalyzeFile.style.display = (refFileInput.files && refFileInput.files.length > 0) ? '' : 'none';
            }
            var resultEl = document.getElementById('refFileResult');
            if (resultEl) resultEl.style.display = 'none';
        });
    }

    if (btnAnalyzeFile) {
        btnAnalyzeFile.addEventListener('click', function () {
            if (!refFileInput || !refFileInput.files || !refFileInput.files.length) {
                alert('분석할 파일을 먼저 선택해주세요.');
                return;
            }
            var file = refFileInput.files[0];
            if (file.size > 5 * 1024 * 1024) {
                alert('파일 크기는 5MB 이하만 지원합니다.');
                return;
            }

            var fd = new FormData();
            fd.append('ref_file', file);
            fd.append('case_type', caseInputType);

            var btnText = btnAnalyzeFile.querySelector('.btn-text');
            var btnLoad = btnAnalyzeFile.querySelector('.btn-loading');
            if (btnText) btnText.style.display = 'none';
            if (btnLoad) btnLoad.style.display = '';
            btnAnalyzeFile.disabled = true;

            showLoading('문서를 분석하고 있습니다', '첨부한 문서의 내용을 읽고 사례 정보를 추출하고 있습니다.');

            fetch('case_file_analyze.php', { method: 'POST', body: fd })
            .then(function (res) {
                return res.text().then(function (text) {
                    try { return JSON.parse(text); }
                    catch (e) { throw new Error('서버 응답 파싱 실패:\n' + text.substring(0, 300)); }
                });
            })
            .then(function (json) {
                hideLoading();
                if (btnText) btnText.style.display = '';
                if (btnLoad) btnLoad.style.display = 'none';
                btnAnalyzeFile.disabled = false;

                if (json.error) { alert('문서 분석 오류: ' + json.error); return; }
                if (!json.success || !json.data) { alert('문서 분석 결과를 받지 못했습니다.'); return; }

                var d = json.data;
                var resultEl = document.getElementById('refFileResult');
                var nameEl = document.getElementById('refFileName');
                var summaryEl = document.getElementById('refFileSummary');
                if (nameEl) nameEl.textContent = file.name;
                if (summaryEl) summaryEl.textContent = d.summary || '요약 없음';
                if (resultEl) resultEl.style.display = '';

                var questions = Array.isArray(d.questions) ? d.questions : [];
                var answers = Array.isArray(d.answers) ? d.answers : [];

                if (questions.length > 0) {
                    var questionListEl = document.querySelector('.chat-question-list');
                    if (questionListEl) {
                        questionListEl.innerHTML = questions.map(function (q) {
                            return '<li>' + escapeHtml(q) + '</li>';
                        }).join('');
                    }
                }

                var textarea = document.getElementById('question1Answer');
                if (textarea && answers.length > 0) {
                    var applyQ = true;
                    if (textarea.value.trim() !== '') {
                        applyQ = confirm('이미 작성된 답변이 있습니다.\nAI가 분석한 내용으로 덮어쓸까요?');
                    }
                    if (applyQ) {
                        var combined = '';
                        for (var qi = 0; qi < answers.length; qi++) {
                            var qLabel = questions[qi] || ((qi + 1) + '번 질문');
                            combined += (qi + 1) + '. ' + qLabel + '\n' + answers[qi];
                            if (qi < answers.length - 1) combined += '\n\n';
                        }
                        textarea.value = combined.trim();
                        deriveCaseTitle();
                        buildRawContent();
                    }
                }

                if (d.suggested_title) {
                    var titleEl = document.getElementById('caseTitleHidden');
                    if (titleEl && titleEl.value.trim() === '') {
                        titleEl.value = d.suggested_title;
                    }
                }

                if (Array.isArray(d.questions) && d.questions.length > 0) {
                    fileBasedQuestions = d.questions;
                }

                if (Array.isArray(d.examples) && d.examples.length > 0) {
                    fileBasedExamples = d.examples;
                    var panel = document.getElementById('examplePanel');
                    if (panel) {
                        panel.removeAttribute('data-loaded');
                    }
                }

                updateQuestionStateUI();
                updateProgressBar();
            })
            .catch(function (err) {
                hideLoading();
                if (btnText) btnText.style.display = '';
                if (btnLoad) btnLoad.style.display = 'none';
                btnAnalyzeFile.disabled = false;
                alert(err && err.message ? err.message : '네트워크 오류가 발생했습니다. 다시 시도해주세요.');
                console.error('파일 분석 오류:', err);
            });
        });
    }

    document.getElementById('toggleExamplesBtn') && document.getElementById('toggleExamplesBtn').addEventListener('click', function () {
        var panel = document.getElementById('examplePanel');
        if (!panel) return;
        var willShow = panel.style.display === 'none';
        panel.style.display = willShow ? '' : 'none';
        if (willShow && !panel.getAttribute('data-loaded')) {
            panel.setAttribute('data-loaded', '1');
            loadExamples();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('.example-panel__btn-prev')) {
            renderExample(currentExampleIndex - 1);
            return;
        }
        if (event.target.closest('.example-panel__btn-next')) {
            renderExample(currentExampleIndex + 1);
            return;
        }

        var exampleBtn = event.target.closest('[data-example-value]');
        if (exampleBtn) {
            var value = exampleBtn.getAttribute('data-example-value') || '';
            var textarea = document.getElementById('question1Answer');
            if (!textarea) return;
            if (textarea.value.trim() !== '' && !confirm('현재 입력 내용을 예시로 바꿀까요?')) return;
            textarea.value = value.replace(/&#039;/g, "'");
            deriveCaseTitle();
            buildRawContent();
            textarea.focus();
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
            textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        var titleBtn = event.target.closest('.title-candidate-btn');
        if (titleBtn) {
            var idx = parseInt(titleBtn.getAttribute('data-candidate-idx'), 10);
            if (isNaN(idx) || !savedTitleCandidates[idx]) return;
            document.querySelectorAll('.title-candidate-btn').forEach(function (btn) { btn.classList.remove('is-selected'); });
            titleBtn.classList.add('is-selected');
            document.getElementById('aiTitle').value = savedTitleCandidates[idx];
            syncTargetKeywords(false);
            return;
        }

        var removeBtn = event.target.closest('[data-remove-file-id]');
        if (removeBtn) {
            var fileId = removeBtn.getAttribute('data-remove-file-id');
            var slot = removeBtn.closest('[data-slot-key]');
            moveImageToSlot(fileId, slot ? 'bank' : 'bank');
        }
    });

    ['question1Answer', 'question2Answer'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
            deriveCaseTitle();
            buildRawContent();
            if (id === 'question1Answer') syncTargetKeywords(false);
            updateQuestionStateUI();
            if (id === 'question1Answer' && (pendingUploadFiles.caseImagesInput || []).length) {
                triggerAiImageAutoUpload().catch(function (error) {
                    alert(error && error.message ? error.message : '이미지 분석 중 오류가 발생했습니다.');
                });
            }
        });
    });

    ['caseImagesInput', 'manualImagesInput'].forEach(function (id) {
        var input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('change', function () {
            appendFilesToInput(id, input.files || []);
            if (id === 'caseImagesInput') {
                triggerAiImageAutoUpload().catch(function (error) {
                    alert(error && error.message ? error.message : '이미지 분석 중 오류가 발생했습니다.');
                });
            }
        });
    });

    ['question1Answer'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
            if (id === 'question1Answer') deriveCaseTitle();
        });
    });

    document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]').forEach(function (input) {
        input.addEventListener('input', function () {
            renderImagePlacementUI();
        });
    });

    ['aiTitle', 'aiSubjectLabel', 'aiCaseCategory'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
            syncTargetKeywords(false);
            updateProgressBar();
        });
    });

    document.addEventListener('dragstart', function (e) {
        var card = e.target && e.target.closest ? e.target.closest('.draft-image-card') : null;
        if (!card || !e.dataTransfer) return;
        var fileId = card.getAttribute('data-file-id');
        if (!fileId) return;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(fileId));
    });

    document.addEventListener('dragover', function (e) {
        var uploadZone = e.target && e.target.closest ? e.target.closest('[data-upload-target]') : null;
        if (uploadZone && e.dataTransfer && Array.prototype.some.call(e.dataTransfer.types || [], function (type) { return type === 'Files'; })) {
            e.preventDefault();
            uploadZone.classList.add('is-dragover');
            return;
        }
        var zone = e.target && e.target.closest ? e.target.closest('[data-slot-dropzone], #draftImagePlacementBank') : null;
        if (!zone) return;
        e.preventDefault();
        zone.classList.add('is-over');
    });

    document.addEventListener('dragleave', function (e) {
        var uploadZone = e.target && e.target.closest ? e.target.closest('[data-upload-target]') : null;
        if (uploadZone) {
            uploadZone.classList.remove('is-dragover');
        }
        var zone = e.target && e.target.closest ? e.target.closest('[data-slot-dropzone], #draftImagePlacementBank') : null;
        if (!zone) return;
        zone.classList.remove('is-over');
    });

    document.addEventListener('drop', function (e) {
        var uploadZone = e.target && e.target.closest ? e.target.closest('[data-upload-target]') : null;
        if (uploadZone && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            e.preventDefault();
            uploadZone.classList.remove('is-dragover');
            var targetInputId = uploadZone.getAttribute('data-upload-target');
            var addedCount = appendFilesToInput(targetInputId, e.dataTransfer.files);
            if (!addedCount) {
                alert('이미지 파일만 업로드할 수 있습니다.');
            } else if (targetInputId === 'caseImagesInput') {
                triggerAiImageAutoUpload().catch(function (error) {
                    alert(error && error.message ? error.message : '이미지 분석 중 오류가 발생했습니다.');
                });
            }
            return;
        }
        var zone = e.target && e.target.closest ? e.target.closest('[data-slot-dropzone], #draftImagePlacementBank') : null;
        if (!zone || !e.dataTransfer) return;
        e.preventDefault();
        zone.classList.remove('is-over');
        var fileId = e.dataTransfer.getData('text/plain');
        if (!fileId) return;
        var slotKey = zone.id === 'draftImagePlacementBank' ? 'bank' : zone.getAttribute('data-slot-dropzone');
        moveImageToSlot(fileId, slotKey);
    });

    rebuildImageAssetMap();
    renderSavedImageList();
    imagePlacementState = normalizeImagePlacementState(initialImageLayout || {}, getSectionDefinitions());
    updateQuestionStateUI();
    renderTitleCandidates(savedTitleCandidates);
    renderImagePlacementUI();
    buildRawContent();
    deriveCaseTitle();
    syncTargetKeywords(false);
    updateProgressBar();
})();
</script>
<?php include '../footer.inc.php'; ?>
