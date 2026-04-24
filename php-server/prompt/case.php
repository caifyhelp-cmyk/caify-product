<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();

$memberPk = (int)($member['id'] ?? 0);

$caseRow  = null;
$caseId   = 0;
$caseFiles = [];

// 수정 모드: ?id= 파라미터가 있으면 기존 데이터 로드
$editId = (int)($_GET['id'] ?? 0);

if ($editId > 0 && $memberPk > 0) {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM caify_case WHERE id = :id AND member_pk = :member_pk LIMIT 1');
    $stmt->execute([':id' => $editId, ':member_pk' => $memberPk]);
    $caseRow = $stmt->fetch();

    if (is_array($caseRow) && !empty($caseRow['id'])) {
        $caseId = (int)$caseRow['id'];
        $f = $pdo->prepare('SELECT * FROM caify_case_file WHERE case_id = :case_id AND member_pk = :member_pk ORDER BY id DESC');
        $f->execute([':case_id' => $caseId, ':member_pk' => $memberPk]);
        $caseFiles = $f->fetchAll();
    } else {
        $caseRow = null;
    }
}

// 값 준비
$case_type        = is_array($caseRow) ? (string)($caseRow['case_type']        ?? '') : '';
$customer_name    = is_array($caseRow) ? (string)($caseRow['customer_name']    ?? '') : '';
$customer_info    = is_array($caseRow) ? (string)($caseRow['customer_info']    ?? '') : '';
$service_name     = is_array($caseRow) ? (string)($caseRow['service_name']     ?? '') : '';
$service_period   = is_array($caseRow) ? (string)($caseRow['service_period']   ?? '') : '';
$before_situation = is_array($caseRow) ? (string)($caseRow['before_situation'] ?? '') : '';
$case_process     = is_array($caseRow) ? (string)($caseRow['case_process']     ?? '') : '';
$after_result     = is_array($caseRow) ? (string)($caseRow['after_result']     ?? '') : '';
$case_content     = is_array($caseRow) ? (string)($caseRow['case_content']     ?? '') : '';
$ai_title         = is_array($caseRow) ? (string)($caseRow['ai_title']         ?? '') : '';
$ai_summary       = is_array($caseRow) ? (string)($caseRow['ai_summary']       ?? '') : '';
$ai_status        = is_array($caseRow) ? (string)($caseRow['ai_status']        ?? 'pending') : 'pending';

$ai_h2_sections = [];
if (is_array($caseRow) && !empty($caseRow['ai_h2_sections'])) {
    $decoded = json_decode((string)$caseRow['ai_h2_sections'], true);
    if (is_array($decoded)) $ai_h2_sections = $decoded;
}

$hasAiResult = ($ai_status === 'done' && $ai_title !== '');

$currentPage = 'bg_page';
?>

<?php include '../header.inc.php'; ?>

<main class="main">
    <div class="container">
        <?php include '../inc/snb.inc.php'; ?>
        <div class="content__wrap">
            <div class="content__inner">
                <div class="content__header">
                    <h2 class="content__header__title">고객 사례 등록</h2>
                </div>
                <div class="content content--case-form">
                    <section class="case-form-hero">
                        <div class="case-form-hero__text">
                            <span class="case-form-hero__eyebrow">Case Builder</span>
                            <h3 class="case-form-hero__title">실제 사례를 정리하고 AI가 블로그 구조까지 제안하도록 작성해보세요</h3>
                            <p class="case-form-hero__desc">
                                사례 유형, 진행 과정, 결과를 구조적으로 입력하면 제목과 요약, H2 소제목까지 한 번에 생성할 수 있습니다.
                            </p>
                        </div>
                        <div class="case-form-hero__meta">
                            <span class="case-form-hero__meta-item"><?= $caseId > 0 ? '수정 모드' : '새 사례 작성' ?></span>
                            <span class="case-form-hero__meta-item">AI Draft Ready</span>
                        </div>
                    </section>

                    <form id="caseForm" method="post" action="case_submit.php" enctype="multipart/form-data">
                        <?php if ($caseId > 0): ?>
                            <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">
                        <?php endif; ?>

                        <!-- ① 사례 기본 정보 -->
                        <section class="case-form-section">
                        <div class="case-form-section__head">
                            <h3 class="content__title">사례 기본 정보</h3>
                            <p class="case-form-section__desc">검색 키워드와 사례 맥락을 이해할 수 있도록 기본 정보를 먼저 정리합니다.</p>
                        </div>
                        <table class="table--prompt case-form-table">
                            <tr>
                                <th>사례 유형 <span class="required--blue">*</span></th>
                                <td>
                                    <input type="text" class="input--text" name="case_type"
                                        placeholder="어떤 종류의 사례인지 입력해주세요."
                                        required
                                        value="<?= htmlspecialchars($case_type, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide">
                                        예) 슬개골탈구 수술 / 법인설립 컨설팅 / 주방 리모델링 / 피부 레이저 시술 / 세무 기장 등
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>고객 / 환자 / 의뢰인명</th>
                                <td>
                                    <input type="text" class="input--text" name="customer_name"
                                        placeholder="고객명 또는 닉네임을 입력해주세요."
                                        value="<?= htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide">
                                        예) 포메라니안 '뭉치' / A법인 (제조업) / 30대 직장인 여성 / 익명 처리 가능
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>고객 특성 메모</th>
                                <td>
                                    <input type="text" class="input--text" name="customer_info"
                                        placeholder="블로그에 활용할 고객 특성을 간략히 메모해주세요."
                                        value="<?= htmlspecialchars($customer_info, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide">
                                        예) 7세 중성화 수컷 / 설립 2년 차 스타트업 / 40대 주부 / 강남구 소재 카페 운영자 등
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>서비스 / 치료 / 시술명 <span class="required--blue">*</span></th>
                                <td>
                                    <input type="text" class="input--text" name="service_name"
                                        placeholder="실제로 제공한 서비스·치료·시술명을 입력해주세요."
                                        required
                                        value="<?= htmlspecialchars($service_name, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide">
                                        예) 슬개골 2등급 내측 탈구 교정수술 / 법인전환 및 절세 컨설팅 / 주방 싱크대 + 타일 교체
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>진행 시기 / 기간</th>
                                <td>
                                    <input type="text" class="input--text" name="service_period"
                                        placeholder="사례 진행 시기나 기간을 입력해주세요."
                                        value="<?= htmlspecialchars($service_period, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide">예) 2025년 3월 / 2024년 하반기 / 총 3회 치료 (2주 간격)</p>
                                </td>
                            </tr>
                        </table>
                        </section>

                        <!-- ② 사례 내용 -->
                        <section class="case-form-section">
                        <div class="case-form-section__head">
                            <h3 class="content__title">사례 내용
                                <span class="text--small">상세히 작성할수록 더 좋은 AI 결과가 나옵니다</span>
                            </h3>
                            <p class="case-form-section__desc">전 상황, 진행 과정, 결과를 구체적으로 적을수록 더 설득력 있는 블로그 구조가 생성됩니다.</p>
                        </div>
                        <table class="table--prompt case-form-table">
                            <tr>
                                <th>내원 / 의뢰 전 상황 <span class="required--blue">*</span></th>
                                <td>
                                    <textarea name="before_situation" class="input--text case-textarea" rows="5"
                                        placeholder="서비스를 이용하기 전 고객이 겪던 문제나 상태를 구체적으로 작성해주세요.&#10;&#10;(동물병원 예) 보호자가 강아지가 한쪽 다리를 절뚝거리고 앉을 때 통증을 호소한다며 내원. 이미 6개월째 증상이 있었고 타 병원에서는 약물 처방만 받은 상태.&#10;&#10;(컨설팅 예) 개인사업자로 운영 중이었으나 연 매출 5억 돌파 후 세금 부담이 커져 법인전환을 고민 중. 전환 시기와 방법을 몰라 의사결정을 미루던 상황."
                                        required><?= htmlspecialchars($before_situation, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th>진행 과정 / 처방 / 방법</th>
                                <td>
                                    <textarea name="case_process" class="input--text case-textarea" rows="5"
                                        placeholder="어떤 방식으로 접근하고 진행했는지 작성해주세요.&#10;&#10;(동물병원 예) 엑스레이 및 촉진 검사를 통해 내측 슬개골 탈구 2등급으로 진단. 수술 전 심장 초음파·혈액검사 시행 후 교정수술을 진행. 수술 후 2주간 절대 안정, 4주 후 재활 프로그램 시작.&#10;&#10;(컨설팅 예) 3년간 손익 데이터를 분석해 최적 전환 시점을 산출. 법인설립 후 배당 구조 설계 및 대표 급여 최적화 플랜 제안."><?= htmlspecialchars($case_process, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th>결과 / 회복 / 변화 <span class="required--blue">*</span></th>
                                <td>
                                    <textarea name="after_result" class="input--text case-textarea" rows="5"
                                        placeholder="사례 진행 후 어떤 결과가 나왔는지 수치나 구체적 사실 위주로 작성해주세요.&#10;&#10;(동물병원 예) 수술 4주 후 정상 보행 가능. 6주 후 계단 오르내리기 문제없음. 보호자가 '이전보다 훨씬 활발해졌다'고 만족.&#10;&#10;(컨설팅 예) 법인전환 첫 해 소득세 약 1,200만 원 절감. 대표 심리적 부담 감소, 추후 투자 유치 기반 확보."
                                        required><?= htmlspecialchars($after_result, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th>추가 메모 / 특이사항</th>
                                <td>
                                    <textarea name="case_content" class="input--text case-textarea" rows="4"
                                        placeholder="위 내용 외 블로그에 넣고 싶은 추가 내용을 자유롭게 작성해주세요.&#10;&#10;예) 고객의 특별한 코멘트, 주의사항, 자주 묻는 질문, 유사 사례와의 비교, 전문가 소견 등"><?= htmlspecialchars($case_content, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                        </table>
                        </section>

                        <!-- ③ AI 분석 버튼 -->
                        <div class="ai-analyze-wrap">
                            <button type="button" id="btnAnalyze" class="btn btn--primary btn--ai">
                                <span class="btn-text">✦ AI로 블로그 구조 생성하기</span>
                                <span class="btn-loading" style="display:none;">분석 중...</span>
                            </button>
                            <p class="text--guide mgT10">사례 내용을 입력한 후 클릭하면 AI가 블로그용 제목·요약·소제목을 자동으로 생성합니다.</p>
                        </div>

                        <!-- ④ AI 분석 결과 -->
                        <div id="aiResultWrap" class="ai-result-wrap" style="<?= $hasAiResult ? '' : 'display:none;' ?>">
                            <div class="case-form-section__head">
                                <h3 class="content__title">AI 생성 결과
                                    <span class="text--small">내용을 직접 수정할 수 있습니다</span>
                                </h3>
                                <p class="case-form-section__desc">초안은 바로 저장하거나, 실제 운영 톤에 맞게 문구를 다듬은 뒤 저장할 수 있습니다.</p>
                            </div>
                            <table class="table--prompt case-form-table">
                                <tr>
                                    <th>블로그 제목</th>
                                    <td>
                                        <input type="text" class="input--text" id="aiTitle" name="ai_title"
                                            placeholder="AI가 생성한 제목이 여기에 표시됩니다."
                                            value="<?= htmlspecialchars($ai_title, ENT_QUOTES, 'UTF-8') ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th>요약 (서머리)</th>
                                    <td>
                                        <textarea id="aiSummary" name="ai_summary" class="input--text" rows="4"
                                            placeholder="AI가 생성한 요약이 여기에 표시됩니다."><?= htmlspecialchars($ai_summary, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th>H2 소제목</th>
                                    <td>
                                        <div id="h2SectionsWrap" class="h2-sections-wrap">
                                            <?php for ($i = 0; $i < 6; $i++): ?>
                                                <div class="h2-section-item">
                                                    <span class="h2-section-label">H2 <?= $i + 1 ?></span>
                                                    <input type="text" class="input--text" name="ai_h2[]"
                                                        placeholder="소제목 <?= $i + 1 ?>"
                                                        value="<?= htmlspecialchars($ai_h2_sections[$i] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                        <p class="text--guide mgT10">소제목은 순서를 바꾸거나 직접 수정할 수 있습니다.</p>
                                    </td>
                                </tr>
                            </table>
                            <input type="hidden" name="ai_status" id="aiStatusInput"
                                value="<?= htmlspecialchars($ai_status, ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <!-- ⑤ 이미지 업로드 -->
                        <section class="case-form-section">
                        <div class="case-form-section__head">
                            <h3 class="content__title">이미지 첨부</h3>
                            <p class="case-form-section__desc">전후 사진, 작업 과정, 결과 이미지 등을 함께 업로드하면 사례 정리에 도움이 됩니다.</p>
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
                                                <input type="file" id="caseImagesInput" name="case_images[]"
                                                    accept="image/*" multiple class="mgT10">
                                            </p>
                                        </div>
                                    </div>
                                    <div class="file__list__wrap">
                                        <?php
                                            $imageCount    = is_array($caseFiles) ? count($caseFiles) : 0;
                                            $imageShowLimit = 8;
                                        ?>
                                        <div class="file__list__actions">
                                            <span class="text--small" style="color:#666;">총 <b><?= (int)$imageCount ?></b>개</span>
                                            <?php if ($imageCount > $imageShowLimit): ?>
                                                <button type="button" class="btn btn--small"
                                                    data-toggle-list="caseImageFileList"
                                                    data-limit="<?= (int)$imageShowLimit ?>">더보기</button>
                                            <?php endif; ?>
                                        </div>
                                        <ul class="file__list filegrid" id="caseImageFileList">
                                            <?php if (is_array($caseFiles) && count($caseFiles) > 0): ?>
                                                <?php $imgIdx = 0; ?>
                                                <?php foreach ($caseFiles as $ff): ?>
                                                    <?php if (!is_array($ff)) continue; ?>
                                                    <?php $imgIdx++; $imgHidden = ($imgIdx > $imageShowLimit) ? ' is-hidden' : ''; ?>
                                                    <li class="file__list__item<?= $imgHidden ?>">
                                                        <a class="file__thumb"
                                                            href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            target="_blank">
                                                            <img src="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                alt="첨부 이미지">
                                                        </a>
                                                        <div class="file__meta">
                                                            <a class="file__name"
                                                                href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                target="_blank"
                                                                title="<?= htmlspecialchars((string)($ff['original_name'] ?? 'image'), ENT_QUOTES, 'UTF-8') ?>">
                                                                <?= htmlspecialchars((string)($ff['original_name'] ?? 'image'), ENT_QUOTES, 'UTF-8') ?>
                                                            </a>
                                                            <button
                                                                type="submit"
                                                                form="deleteCaseFileForm"
                                                                name="file_id"
                                                                value="<?= (int)($ff['id'] ?? 0) ?>"
                                                                class="button--delete"
                                                                onclick="return confirm('이 파일을 삭제할까요?');"
                                                                style="background:none;border:0;cursor:pointer;">
                                                                <img src="../images/x.svg" alt="삭제">
                                                            </button>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <li class="file__list__item">
                                                    <span>첨부된 이미지가 없습니다.</span>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        </table>
                        </section>

                        <div class="button__wrap case-form-actions">
                            <a href="case_list.php" class="btn case-form-actions__secondary">취소</a>
                            <button type="submit" class="btn btn--primary case-form-actions__primary">저장하기</button>
                        </div>
                    </form>

                    <form id="deleteCaseFileForm" method="post" action="case_file_delete.php" style="display:none;">
                        <?php if ($caseId > 0): ?>
                            <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">
                        <?php endif; ?>
                    </form>
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

.case-form-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 24px 28px;
    border: 1px solid #e7eef8;
    border-radius: 18px;
    background:
        radial-gradient(circle at top right, rgba(37, 99, 235, 0.07), transparent 28%),
        linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.05);
}

.case-form-hero__text {
    max-width: 780px;
}

.case-form-hero__eyebrow {
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

.case-form-hero__title {
    margin: 0;
    color: #0f172a;
    font-size: 1.45rem;
    line-height: 1.45;
    font-weight: 800;
}

.case-form-hero__desc {
    margin: 12px 0 0;
    color: #64748b;
    line-height: 1.7;
    font-size: 0.94rem;
}

.case-form-hero__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-self: flex-start;
}

.case-form-hero__meta-item {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 10px;
    border: 1px solid #dbe7ff;
    border-radius: 999px;
    background: #f5f9ff;
    color: #3157b8;
    font-size: 0.76rem;
    font-weight: 700;
}

.case-form-section {
    padding: 22px 24px;
    border: 1px solid #e8edf5;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.04);
}

.case-form-section__head {
    margin-bottom: 16px;
}

.case-form-section__head .content__title {
    margin-bottom: 6px;
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
    background: #fbfcfe;
}

.case-form-table td {
    background: #fff;
}

.ai-analyze-wrap {
    margin: 2px 0 2px;
    padding: 22px 24px;
    background: linear-gradient(180deg, #fafcff 0%, #f4f8ff 100%);
    border: 1px solid #dce7fa;
    border-radius: 18px;
    text-align: center;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.06);
}
.btn--ai {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    font-size: 0.96rem;
    padding: 0 28px;
    letter-spacing: 0.02em;
    min-width: 250px;
    border-radius: 12px;
}
.ai-result-wrap {
    margin-top: 2px;
    padding: 22px 24px;
    background: linear-gradient(180deg, #fbfffe 0%, #f6fffd 100%);
    border: 1px solid #c7f2eb;
    border-radius: 18px;
    box-shadow: 0 14px 28px rgba(34, 211, 238, 0.05);
    animation: fadeIn 0.4s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
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
    font-size: 0.75rem;
    font-weight: 700;
    color: #2563EB;
    background: #eef4ff;
    border: 1px solid #d6e4ff;
    border-radius: 999px;
    padding: 5px 9px;
    text-align: center;
    flex-shrink: 0;
}
.h2-section-item .input--text { flex: 1; }
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
}

.file__upload__area[data-dropzone="case-images"] {
    border: 1px dashed #c7d6eb;
    border-radius: 16px;
    background: linear-gradient(180deg, #fcfdff 0%, #f7faff 100%);
    padding: 18px;
}

.file__upload__area[data-dropzone="case-images"].is-dragover {
    border-color: #7aa2ff;
    background: #f1f6ff;
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

.ai-loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
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
    border: 5px solid rgba(255,255,255,0.25);
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
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 1024px) {
    .case-form-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .case-form-hero__meta {
        align-self: stretch;
    }
}
</style>

<script>
(function () {

    /* ── 드롭존 ── */
    function escapeHtml(s) {
        return String(s)
            .replace(/&/g,"&amp;").replace(/</g,"&lt;")
            .replace(/>/g,"&gt;").replace(/"/g,"&quot;")
            .replace(/'/g,"&#039;");
    }

    function setupDropzone(zone, input, list) {
        if (!zone || !input) return;
        function renderSelected() {
            if (!list) return;
            list.querySelectorAll(".file__list__item--new").forEach(function(el){el.remove();});
            var files = Array.prototype.slice.call(input.files||[]);
            if (!files.length) return;
            var frag = document.createDocumentFragment();
            files.forEach(function(f){
                var li = document.createElement("li");
                li.className = "file__list__item file__list__item--new";
                li.innerHTML = '<div class="file__meta"><span class="file__name" title="'+escapeHtml(f.name)+'">[NEW] '+escapeHtml(f.name)+'</span></div>';
                frag.appendChild(li);
            });
            list.prepend(frag);
        }
        input.addEventListener("change", renderSelected);
        zone.addEventListener("click", function(e){
            if (e.target && e.target.closest && e.target.closest('input[type="file"]')) return;
            input.click();
        });
        ["dragenter","dragover"].forEach(function(ev){
            zone.addEventListener(ev,function(e){e.preventDefault();zone.classList.add("is-dragover");});
        });
        ["dragleave","drop"].forEach(function(ev){
            zone.addEventListener(ev,function(e){e.preventDefault();zone.classList.remove("is-dragover");});
        });
        zone.addEventListener("drop",function(e){
            var dropped=(e.dataTransfer&&e.dataTransfer.files)?Array.prototype.slice.call(e.dataTransfer.files):[];
            if(!dropped.length) return;
            var dt=new DataTransfer();
            Array.prototype.slice.call(input.files||[]).forEach(function(f){dt.items.add(f);});
            dropped.forEach(function(f){dt.items.add(f);});
            input.files=dt.files;
            renderSelected();
        });
        renderSelected();
    }

    setupDropzone(
        document.querySelector('[data-dropzone="case-images"]'),
        document.getElementById("caseImagesInput"),
        document.getElementById("caseImageFileList")
    );

    /* ── 더보기/접기 ── */
    document.querySelectorAll('[data-toggle-list]').forEach(function(btn){
        btn.addEventListener('click',function(){
            var listId=btn.getAttribute('data-toggle-list');
            var limit=parseInt(btn.getAttribute('data-limit')||'0',10);
            var ul=document.getElementById(listId);
            if(!ul||!limit) return;
            var items=Array.prototype.slice.call(ul.querySelectorAll('.file__list__item:not(.file__list__item--new)'));
            var isExpanded=btn.getAttribute('data-expanded')==='1';
            if(!isExpanded){
                items.forEach(function(li){li.classList.remove('is-hidden');});
                btn.textContent='접기'; btn.setAttribute('data-expanded','1');
            } else {
                items.forEach(function(li,idx){if(idx>=limit)li.classList.add('is-hidden');});
                btn.textContent='더보기'; btn.setAttribute('data-expanded','0');
                ul.scrollIntoView({behavior:'smooth',block:'start'});
            }
        });
    });

    /* ── AI 분석 ── */
    var btnAnalyze   = document.getElementById('btnAnalyze');
    var aiResultWrap = document.getElementById('aiResultWrap');
    var aiStatusInput= document.getElementById('aiStatusInput');

    function showLoading(show) {
        var btnText    = btnAnalyze.querySelector('.btn-text');
        var btnLoading = btnAnalyze.querySelector('.btn-loading');
        var existing   = document.getElementById('aiLoadingOverlay');
        if (show) {
            btnAnalyze.disabled = true;
            if (btnText)    btnText.style.display    = 'none';
            if (btnLoading) btnLoading.style.display = '';
            if (!existing) {
                var overlay = document.createElement('div');
                overlay.id = 'aiLoadingOverlay';
                overlay.className = 'ai-loading-overlay';
                overlay.innerHTML =
                    '<div class="ai-loading-spinner"></div>' +
                    '<div class="ai-loading-text">AI가 사례를 분석하고 있습니다...</div>';
                document.body.appendChild(overlay);
            }
        } else {
            btnAnalyze.disabled = false;
            if (btnText)    btnText.style.display    = '';
            if (btnLoading) btnLoading.style.display = 'none';
            if (existing) existing.remove();
        }
    }

    function fillH2Sections(sections) {
        var inputs = document.querySelectorAll('#h2SectionsWrap input[name="ai_h2[]"]');
        if (!Array.isArray(sections)) return;
        inputs.forEach(function(inp, idx){ inp.value = sections[idx] || ''; });
    }

    if (btnAnalyze) {
        btnAnalyze.addEventListener('click', function(){
            var form          = document.getElementById('caseForm');
            var caseTypeEl    = form.querySelector('[name="case_type"]');
            var serviceNameEl = form.querySelector('[name="service_name"]');
            var beforeEl      = form.querySelector('[name="before_situation"]');
            var afterEl       = form.querySelector('[name="after_result"]');

            if (!caseTypeEl || caseTypeEl.value.trim() === '') {
                alert('사례 유형을 입력해주세요.');
                caseTypeEl.focus();
                return;
            }
            if (!serviceNameEl || serviceNameEl.value.trim() === '') {
                alert('서비스/치료/시술명을 입력해주세요.');
                serviceNameEl.focus();
                return;
            }
            if ((!beforeEl || beforeEl.value.trim() === '') && (!afterEl || afterEl.value.trim() === '')) {
                alert('내원/의뢰 전 상황 또는 결과/변화를 입력해주세요.');
                beforeEl && beforeEl.focus();
                return;
            }

            var payload = {
                case_type:        caseTypeEl.value.trim(),
                customer_name:    (form.querySelector('[name="customer_name"]') || {value:''}).value.trim(),
                customer_info:    (form.querySelector('[name="customer_info"]') || {value:''}).value.trim(),
                service_name:     serviceNameEl.value.trim(),
                service_period:   (form.querySelector('[name="service_period"]') || {value:''}).value.trim(),
                before_situation: beforeEl ? beforeEl.value.trim() : '',
                case_process:     (form.querySelector('[name="case_process"]') || {value:''}).value.trim(),
                after_result:     afterEl ? afterEl.value.trim() : '',
                case_content:     (form.querySelector('[name="case_content"]') || {value:''}).value.trim()
            };

            showLoading(true);

            fetch('case_ai.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json; charset=utf-8'},
                body: JSON.stringify(payload)
            })
            .then(function(res){ return res.json(); })
            .then(function(json){
                showLoading(false);
                if (json.error) { alert('AI 분석 오류: ' + json.error); return; }
                if (json.success && json.data) {
                    var d = json.data;
                    document.getElementById('aiTitle').value   = d.title   || '';
                    document.getElementById('aiSummary').value = d.summary || '';
                    fillH2Sections(d.h2_sections || []);
                    if (aiStatusInput) aiStatusInput.value = 'done';
                    if (aiResultWrap) {
                        aiResultWrap.style.display = '';
                        setTimeout(function(){
                            aiResultWrap.scrollIntoView({behavior:'smooth', block:'start'});
                        }, 100);
                    }
                }
            })
            .catch(function(err){
                showLoading(false);
                alert('네트워크 오류가 발생했습니다. 다시 시도해주세요.');
                console.error(err);
            });
        });
    }

    /* ── 폼 필수 항목 검증 ── */
    var form = document.getElementById('caseForm');
    if (form) {
        form.addEventListener('submit', function(e){
            var errors = [];
            var caseTypeEl = form.querySelector('[name="case_type"]');
            if (!caseTypeEl || caseTypeEl.value.trim() === '') errors.push('사례 유형');
            var serviceEl = form.querySelector('[name="service_name"]');
            if (!serviceEl || serviceEl.value.trim() === '') errors.push('서비스/치료/시술명');
            var beforeEl = form.querySelector('[name="before_situation"]');
            if (!beforeEl || beforeEl.value.trim() === '') errors.push('내원/의뢰 전 상황');
            var afterEl = form.querySelector('[name="after_result"]');
            if (!afterEl || afterEl.value.trim() === '') errors.push('결과/회복/변화');

            if (errors.length > 0) {
                e.preventDefault();
                alert('다음 필수 항목을 입력해주세요:\n\n' + errors.map(function(l){return '• '+l;}).join('\n'));
                var firstEl = form.querySelector('[name="case_type"]');
                if (firstEl) { firstEl.scrollIntoView({behavior:'smooth',block:'center'}); firstEl.focus(); }
            }
        });
    }
})();
</script>

<?php include '../footer.inc.php'; ?>
