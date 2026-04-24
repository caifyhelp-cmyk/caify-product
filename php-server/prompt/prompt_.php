<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$member = current_member();

$memberPk = (int)($member['id'] ?? 0);

// 내 member_pk로 1건 조회(멤버당 1개)
$promptRow = null;
$promptId = 0; 
$files = [];

if ($memberPk > 0) {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM caify_prompt WHERE member_pk = :member_pk LIMIT 1');
    $stmt->execute([':member_pk' => $memberPk]);
    $promptRow = $stmt->fetch();

    if (is_array($promptRow) && !empty($promptRow['id'])) {
        $promptId = (int)$promptRow['id'];

        $f = $pdo->prepare('SELECT * FROM caify_prompt_file WHERE prompt_id = :prompt_id AND member_pk = :member_pk ORDER BY id DESC');
        $f->execute([':prompt_id' => $promptId, ':member_pk' => $memberPk]);
        $files = $f->fetchAll();
    }
}

// 값 준비(없으면 빈 값)
$brand_name = is_array($promptRow) ? (string)($promptRow['brand_name'] ?? '') : '';
$product_name = is_array($promptRow) ? (string)($promptRow['product_name'] ?? '') : '';
$industry = is_array($promptRow) ? (string)($promptRow['industry'] ?? '') : '';
$inquiry_channels = is_array($promptRow) ? (string)($promptRow['inquiry_channels'] ?? '') : '';
$address_zip = is_array($promptRow) ? (string)($promptRow['address_zip'] ?? '') : '';
$address1 = is_array($promptRow) ? (string)($promptRow['address1'] ?? '') : '';
$address2 = is_array($promptRow) ? (string)($promptRow['address2'] ?? '') : '';
$goal = is_array($promptRow) ? (string)($promptRow['goal'] ?? '') : '';
$keep_style = is_array($promptRow) ? (string)($promptRow['keep_style'] ?? '') : '';
$style_url = is_array($promptRow) ? (string)($promptRow['style_url'] ?? '') : '';
$inquiry_phone = is_array($promptRow) ? (string)($promptRow['inquiry_phone'] ?? '') : '';

$extra_strength = is_array($promptRow) ? (string)($promptRow['extra_strength'] ?? '') : '';
$action_style = is_array($promptRow) ? (string)($promptRow['action_style'] ?? '') : '';
$forbidden_phrases = is_array($promptRow) ? (string)($promptRow['forbidden_phrases'] ?? '') : '';
$postLengthModeRaw = is_array($promptRow) ? (string)($promptRow['postLengthModeRaw'] ?? '') : '';

$service_types = [];
$ages = [];
$product_strengths = [];
$tones = [];
$expressions = [];
$content_styles = [];

if (is_array($promptRow)) {
    $service_types = is_string($promptRow['service_types'] ?? null) ? json_decode((string)$promptRow['service_types'], true) : [];
    $ages = is_string($promptRow['ages'] ?? null) ? json_decode((string)$promptRow['ages'], true) : [];
    $product_strengths = is_string($promptRow['product_strengths'] ?? null) ? json_decode((string)$promptRow['product_strengths'], true) : [];
    $tones = is_string($promptRow['tones'] ?? null) ? json_decode((string)$promptRow['tones'], true) : [];
    $expressions = is_string($promptRow['expressions'] ?? null) ? json_decode((string)$promptRow['expressions'], true) : [];
    $content_styles = is_string($promptRow['content_styles'] ?? null) ? json_decode((string)$promptRow['content_styles'], true) : [];
}

if (!is_array($service_types)) $service_types = [];
if (!is_array($ages)) $ages = [];
if (!is_array($product_strengths)) $product_strengths = [];
if (!is_array($tones)) $tones = [];
if (!is_array($expressions)) $expressions = [];
if (!is_array($content_styles)) $content_styles = [];

// expressions는 UI에서 단일 선택(radio)이므로, 저장된 JSON 배열에서 첫 값만 뽑아 사용
$expression_one = (count($expressions) > 0) ? (string)((int)($expressions[0] ?? 0)) : '';

// 현재 페이지 체크해서 css 로드 
$currentPage = 'bg_page';
$showPublishNotice = ($promptId <= 0);
?>

<?php include '../header.inc.php'; ?>  
 
    <main class="main">
        <div class="container">
            <?php include '../inc/snb.inc.php'; ?>
            <div class="content__wrap">
                <div class="content__inner">
                    <div class="content__header">
                        <h2 class="content__header__title">프롬프트</h2>
                    </div>
                    <div class="content">
                       <!--  <?php if (!empty($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
                            <p class="mgT10 text--small" style="color:#2e7d32; font-weight:700;">파일이 삭제되었습니다.</p>
                        <?php endif; ?>
                        <?php if ($promptId > 0): ?>
                            <p class="mgT10 text--small" style="color:#2e7d32; font-weight:700;">
                                저장된 데이터가 있어 불러왔습니다. (Prompt ID: <?= (int)$promptId ?>)
                            </p>
                        <?php else: ?>
                            <p class="mgT10 text--small" style="color:#666;">
                                저장된 데이터가 없습니다. 새로 작성 후 저장하면 이후에는 수정(업데이트)됩니다.
                            </p>
                        <?php endif; ?> -->
                        <form id="promptForm" method="post" action="prompt_submit.php" enctype="multipart/form-data">
                        <div class="prompt-wizard">
                            <!-- <div class="prompt-wizard__toolbar">
                                <a href="prompt_list.php" class="btn">취소</a>
                            </div> -->
                            <div class="pw-step-bar" role="navigation" aria-label="입력 단계">
                                <div class="pw-step pw-step--active" id="pwTab1" data-pw-tab="1"><div class="pw-step__num" id="pwSn1">1</div><span class="pw-step__label">기본 업체정보</span></div>
                                <div class="pw-step-line" id="pwLine1"></div>
                                <div class="pw-step pw-step--todo" id="pwTab2" data-pw-tab="2"><div class="pw-step__num" id="pwSn2">2</div><span class="pw-step__label">마케팅 방향</span></div>
                                <div class="pw-step-line" id="pwLine2"></div>
                                <div class="pw-step pw-step--todo" id="pwTab3" data-pw-tab="3"><div class="pw-step__num" id="pwSn3">3</div><span class="pw-step__label">콘텐츠 설정</span></div>
                            </div>

                            <!-- STEP 1 -->
                            <div class="pw-section pw-section--active" id="sec1">
                                <div class="pw-section-header">
                                    <div class="pw-section-header__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg></div>
                                    <div>
                                        <div class="pw-section-title">기본 업체정보</div>
                                        <p class="pw-section-sub">AI가 내 가게를 이해하기 위한 기본 정보예요.<br><strong class="pw-accent">정확하게 입력할수록 블로그 글 품질이 높아져요!</strong></p>
                                    </div>
                                </div>
                                <div class="pw-demo-banner">
                                    <div class="pw-demo-banner__ico" aria-hidden="true">💡</div>
                                    <div class="pw-demo-banner__text">
                                        <strong>어떻게 입력하는지 모르겠나요?</strong>
                                        예시 버튼을 누르면 샘플 데이터가 자동으로 채워져요. 수정해서 사용하세요!
                                    </div>
                                    <div class="pw-demo-banner__btns">
                                        <button type="button" class="pw-btn-demo" id="pwFillDemo1">✨ 예시로 채우기</button>
                                        <button type="button" class="pw-btn-demo-reset" id="pwReset1">초기화</button>
                                    </div>
                                </div>
                                <div class="pw-progress-row">
                                    <div class="pw-progress-bar-wrap"><div class="pw-progress-bar-fill" id="pfill1" style="width:0%"></div></div>
                                    <div class="pw-progress-label"><strong id="plabel1">0</strong> / 7 완료</div>
                                </div>
                                <div class="pw-speech-bubble">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm1 14.5h-2v-5h2v5zm0-7h-2V7h2v2z"/></svg>
                                    가게 이름부터 시작해요! 순서대로 채워주시면 돼요.<br>모르는 항목은 건너뛰어도 괜찮아요.
                                </div>

                                <div class="pw-field" id="f1_1" data-section="1">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">브랜드명 (가게 이름)</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--opt">선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">상호명, 병원명, 브랜드명을 입력해주세요.<br><em>예시)</em> 강남동물의료센터, 미소치과, 행복부동산</div></details>
                                    </div>
                                    <p class="pw-field-hint">블로그 글 제목과 본문에 자동으로 사용되는 이름이에요.</p>
                                    <input type="text" class="input--text pw-real-input" name="brand_name" placeholder="예) 한국인증센터, 강남미소치과, 행복부동산" value="<?= htmlspecialchars($brand_name, ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="pw-field" id="f1_2" data-section="1">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">판매하는 상품 또는 서비스</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">여러 개면 <em>쉼표(,)</em>로 구분해주세요.<br><em>예시)</em> 스케일링, 발치, 중성화 수술</div></details>
                                    </div>
                                    <p class="pw-field-hint">블로그에서 홍보하고 싶은 모든 상품명(서비스명)을 입력해주세요. 복수 입력 시 ,(콤마)로 구분해주세요.</p>
                                    <input type="text" class="input--text pw-real-input" name="product_name" placeholder="예) ESG경영, ISO인증, 경영컨설팅, 정부지원사업" value="<?= htmlspecialchars($product_name, ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="pw-field" id="f1_3" data-section="1">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">업종</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">정확한 업종을 알아야 AI가 업계 맞춤형 표현을 사용할 수 있어요.<br><em>예시)</em> 동물병원, 피부과, 경영컨설팅업, 제조업</div></details>
                                    </div>
                                    <p class="pw-field-hint">정확한 업종을 입력할수록 AI가 업계 전문 용어를 자연스럽게 사용해요.</p>
                                    <input type="text" class="input--text pw-real-input" name="industry" placeholder="예) 경영컨설팅업, 의료업, 제조업, 동물병원" value="<?= htmlspecialchars($industry, ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="pw-field" id="f1_4" data-section="1">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">대표 문의·결제 페이지 주소</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        <span class="pw-badge pw-badge--opt pw-badge--blue">복수 입력 가능</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">카카오톡 채널, 홈페이지 등 실제 문의·결제 페이지 주소를 써주세요. 없을 시 이메일, 연락처 등으로 대체 가능해요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">대표적으로 문의·결제에 사용하시는 페이지 주소를 입력해주세요. 복수 입력 시 ,(콤마)로 구분해주세요.</p>
                                    <input type="text" class="input--text pw-real-input" name="inquiry_channels" placeholder="예) https://홈페이지주소.com 또는 카카오톡 채널명" value="<?= htmlspecialchars($inquiry_channels, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="pw-video-inline" aria-hidden="true">
                                        <div class="pw-video-play"><svg viewBox="0 0 16 16" width="14" height="14"><path fill="#fff" d="M4 2l10 6-10 6V2z"/></svg></div>
                                        <div class="pw-video-text"><div class="pw-video-text__t">이 항목이 헷갈리시나요?</div><div class="pw-video-text__s">20초 영상으로 확인해보세요</div></div>
                                        <span class="pw-video-badge">▶ 20초</span>
                                    </div>
                                </div>

                                <div class="pw-field" id="f1_5" data-section="1">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">연락처</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">전화번호, 카카오채널 ID, 이메일 등. 블로그 글에 함께 표시될 수 있어요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">대표번호 또는 별도의 유선 번호, 대표 이메일을 입력해주세요. 복수 입력 시 ,(콤마)로 구분해주세요.</p>
                                    <input type="text" class="input--text pw-real-input" name="inquiry_phone" placeholder="예) 02-713-9005 또는 카카오채널@아이디" value="<?= htmlspecialchars($inquiry_phone, ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="pw-field pw-field--chips" id="f1_6" data-section="1" data-pw-type="chips">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">서비스 형태</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--opt">선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">해당하는 항목을 모두 선택하세요. 지역 SEO 전략에 영향을 줘요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">선택에 따라 지역 키워드 포함 여부 등 SEO 전략이 달라져요.</p>
                                    <div class="pw-chips">
                                        <label class="pw-chip"><input type="checkbox" name="service_type[]" value="1" <?= in_array(1, $service_types, true) ? 'checked' : '' ?>><span>오프라인 매장</span></label>
                                        <label class="pw-chip"><input type="checkbox" name="service_type[]" value="2" <?= in_array(2, $service_types, true) ? 'checked' : '' ?>><span>온라인 서비스</span></label>
                                        <label class="pw-chip"><input type="checkbox" name="service_type[]" value="3" <?= in_array(3, $service_types, true) ? 'checked' : '' ?>><span>전국 서비스</span></label>
                                        <label class="pw-chip"><input type="checkbox" name="service_type[]" value="4" <?= in_array(4, $service_types, true) ? 'checked' : '' ?>><span>프랜차이즈</span></label>
                                        <label class="pw-chip"><input type="checkbox" name="service_type[]" value="5" <?= in_array(5, $service_types, true) ? 'checked' : '' ?>><span>기타</span></label>
                                    </div>
                                </div>

                                <div class="pw-field" id="f1_7" data-section="1">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">사업장주소</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">지역 기반 검색 최적화에 사용돼요. 예) &quot;서울 마포구 ISO인증&quot; 같은 지역 키워드로 노출될 수 있어요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">지역 키워드 SEO에 활용되어 근처 고객 유입에 도움이 돼요.</p>
                                    <div class="pw-addr-row">
                                        <input type="text" class="input--text pw-real-input" id="postcode" name="address_zip" placeholder="우편번호 (예: 04155)" readonly value="<?= htmlspecialchars($address_zip, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="button" class="pw-addr-btn" onclick="execDaumPostcode();">우편번호 검색</button>
                                    </div>
                                    <input type="text" class="input--text pw-real-input mgT10" id="roadAddress" name="address1" placeholder="기본 주소 (예: 서울 마포구 마혜대로 15)" value="<?= htmlspecialchars($address1, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="text" class="input--text pw-real-input mgT10" id="detailAddress" name="address2" placeholder="상세 주소 (예: 901호)" value="<?= htmlspecialchars($address2, ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="pw-btn-row">
                                    <button type="button" class="pw-btn-next" id="pwGoStep2">다음 → 마케팅 방향 설정</button>
                                </div>
                            </div>

                            <!-- STEP 2 -->
                            <div class="pw-section" id="sec2">
                                <div class="pw-section-header">
                                    <div class="pw-section-header__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></div>
                                    <div>
                                        <div class="pw-section-title">마케팅 방향</div>
                                        <p class="pw-section-sub">어떤 고객에게, 어떤 느낌으로 어필할지 설정해요.<br><strong class="pw-accent">클릭만 하면 돼요!</strong></p>
                                    </div>
                                </div>
                                <div class="pw-demo-banner">
                                    <div class="pw-demo-banner__ico" aria-hidden="true">💡</div>
                                    <div class="pw-demo-banner__text">
                                        <strong>어떻게 선택하는지 모르겠나요?</strong>
                                        예시 버튼을 누르면 샘플이 선택돼요!
                                    </div>
                                    <div class="pw-demo-banner__btns">
                                        <button type="button" class="pw-btn-demo" id="pwFillDemo2">✨ 예시로 채우기</button>
                                        <button type="button" class="pw-btn-demo-reset" id="pwReset2">초기화</button>
                                    </div>
                                </div>
                                <div class="pw-progress-row">
                                    <div class="pw-progress-bar-wrap"><div class="pw-progress-bar-fill" id="pfill2" style="width:0%"></div></div>
                                    <div class="pw-progress-label"><strong id="plabel2">0</strong> / 4 완료</div>
                                </div>
                                <div class="pw-speech-bubble">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm1 14.5h-2v-5h2v5zm0-7h-2V7h2v2z"/></svg>
                                    해당하는 항목을 눌러서 선택해주세요. 여러 개 선택해도 괜찮아요!
                                </div>

                                <div class="pw-field pw-field--chips" id="f2_1" data-section="2" data-pw-type="radiochips">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">가장 원하는 목표</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">AI가 이 목표에 맞춰 글의 구조, 강조점, 결말을 다르게 씁니다. <strong>하나만</strong> 선택해주세요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">목표에 따라 글 전체 방향이 달라져요.</p>
                                    <div class="pw-chips pw-chips--goal">
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="goal1" name="goal" value="1" <?= $goal === '1' ? 'checked' : '' ?>><span>매출을 늘리고 싶다</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="goal2" name="goal" value="2" <?= $goal === '2' ? 'checked' : '' ?>><span>예약·방문을 늘리고 싶다.</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="goal3" name="goal" value="3" <?= $goal === '3' ? 'checked' : '' ?>><span>문의·상담을 늘리고 싶다.</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="goal4" name="goal" value="4" <?= $goal === '4' ? 'checked' : '' ?>><span>브랜드를 알리고 싶다.</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="goal5" name="goal" value="5" <?= $goal === '5' ? 'checked' : '' ?>><span>신뢰를 확보하고 싶다.</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="goal6" name="goal" value="6" <?= $goal === '6' ? 'checked' : '' ?>><span>기타</span></label>
                                    </div>
                                </div>

                                <div class="pw-field pw-field--chips" id="f2_2" data-section="2" data-pw-type="chips">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">주요 고객 연령대</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        <span class="pw-badge pw-badge--opt pw-badge--blue">복수 선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">연령대에 맞는 어휘·문체에 반영됩니다.</div></details>
                                    </div>
                                    <p class="pw-field-hint">연령대에 따라 어투와 공감 포인트가 달라져요.</p>
                                    <div class="pw-chips">
                                        <label class="pw-chip"><input type="checkbox" id="age1" name="age[]" value="1" <?= in_array(1, $ages, true) ? 'checked' : '' ?>><span>10대</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="age2" name="age[]" value="2" <?= in_array(2, $ages, true) ? 'checked' : '' ?>><span>20대</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="age3" name="age[]" value="3" <?= in_array(3, $ages, true) ? 'checked' : '' ?>><span>30대</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="age4" name="age[]" value="4" <?= in_array(4, $ages, true) ? 'checked' : '' ?>><span>40대</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="age5" name="age[]" value="5" <?= in_array(5, $ages, true) ? 'checked' : '' ?>><span>50대</span></label>
                                    </div>
                                    <div id="warn_f2_2" class="pw-warn pw-warn--age" hidden>모든 연령을 선택하면 타겟이 분산되어 콘텐츠 노출 효율이 낮아질 수 있습니다.</div>
                                </div>

                                <div class="pw-field pw-field--chips" id="f2_3" data-section="2" data-pw-type="chips">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">상품/서비스 강점</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">AI가 선택한 강점을 글에 자연스럽게 반영합니다.</div></details>
                                    </div>
                                    <p class="pw-field-hint">복수 선택 가능합니다.</p>
                                    <div class="pw-chips pw-chips--wrap">
                                        <label class="pw-chip"><input type="checkbox" id="product_type1" name="product_type[]" value="1" <?= in_array(1, $product_strengths, true) ? 'checked' : '' ?>><span>가격이 합리적이다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type2" name="product_type[]" value="2" <?= in_array(2, $product_strengths, true) ? 'checked' : '' ?>><span>결과·성과가 명확하다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type3" name="product_type[]" value="3" <?= in_array(3, $product_strengths, true) ? 'checked' : '' ?>><span>전문 인력이 직접 제공한다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type4" name="product_type[]" value="4" <?= in_array(4, $product_strengths, true) ? 'checked' : '' ?>><span>처리 속도가 빠르다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type5" name="product_type[]" value="5" <?= in_array(5, $product_strengths, true) ? 'checked' : '' ?>><span>경험·사례가 많다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type6" name="product_type[]" value="6" <?= in_array(6, $product_strengths, true) ? 'checked' : '' ?>><span>접근성이 좋다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type7" name="product_type[]" value="7" <?= in_array(7, $product_strengths, true) ? 'checked' : '' ?>><span>사후 관리가 잘 된다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type8" name="product_type[]" value="8" <?= in_array(8, $product_strengths, true) ? 'checked' : '' ?>><span>공식 인증·자격을 보유하고 있다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type9" name="product_type[]" value="9" <?= in_array(9, $product_strengths, true) ? 'checked' : '' ?>><span>기술력이 높다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="product_type10" name="product_type[]" value="10" <?= in_array(10, $product_strengths, true) ? 'checked' : '' ?>><span>기타</span></label>
                                    </div>
                                </div>

                                <div class="pw-field pw-field--chips" id="f2_4" data-section="2" data-pw-type="tone" data-pw-max="2">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">말하는 방식/톤</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        <span class="pw-badge pw-badge--opt pw-badge--blue">최대 2개</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">브랜드 이미지에 맞는 말투를 고르세요. 최대 2개까지 선택할 수 있어요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">말투는 최대 2개까지 선택할 수 있어요.</p>
                                    <div class="pw-chips">
                                        <label class="pw-chip"><input type="checkbox" id="tone1" name="tone[]" value="1" <?= in_array(1, $tones, true) ? 'checked' : '' ?>><span>차분하게 설명한다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="tone2" name="tone[]" value="2" <?= in_array(2, $tones, true) ? 'checked' : '' ?>><span>친절하게 쉽게 설명한다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="tone3" name="tone[]" value="3" <?= in_array(3, $tones, true) ? 'checked' : '' ?>><span>단호하고 확신 있게 말한다.</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="tone4" name="tone[]" value="4" <?= in_array(4, $tones, true) ? 'checked' : '' ?>><span>전문가가 조언하는 느낌.</span></label>
                                    </div>
                                    <div id="warn_f2_4" class="pw-warn pw-warn--tone" hidden>말투는 최대 2개까지 선택할 수 있어요.</div>
                                </div>

                                <div class="pw-btn-row">
                                    <button type="button" class="pw-btn-prev" id="pwGoStep1">← 이전</button>
                                    <button type="button" class="pw-btn-next" id="pwGoStep3">다음 → 콘텐츠 설정</button>
                                </div>
                            </div>

                            <!-- STEP 3 -->
                            <div class="pw-section" id="sec3">
                                <div class="pw-section-header">
                                    <div class="pw-section-header__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/></svg></div>
                                    <div>
                                        <div class="pw-section-title">콘텐츠 설정</div>
                                        <p class="pw-section-sub">블로그 글의 형식과 스타일을 정해요. <strong class="pw-accent">마지막 단계예요!</strong></p>
                                    </div>
                                </div>
                                <div class="pw-demo-banner">
                                    <div class="pw-demo-banner__ico" aria-hidden="true">💡</div>
                                    <div class="pw-demo-banner__text">
                                        <strong>어떻게 설정하는지 모르겠나요?</strong>
                                        예시 버튼을 누르면 샘플 설정이 채워져요!
                                    </div>
                                    <div class="pw-demo-banner__btns">
                                        <button type="button" class="pw-btn-demo" id="pwFillDemo3">✨ 예시로 채우기</button>
                                        <button type="button" class="pw-btn-demo-reset" id="pwReset3">초기화</button>
                                    </div>
                                </div>
                                <div class="pw-progress-row">
                                    <div class="pw-progress-bar-wrap"><div class="pw-progress-bar-fill" id="pfill3" style="width:0%"></div></div>
                                    <div class="pw-progress-label"><strong id="plabel3">0</strong> / 6 완료</div>
                                </div>
                                <div class="pw-speech-bubble">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm1 14.5h-2v-5h2v5zm0-7h-2V7h2v2z"/></svg>
                                    거의 다 왔어요! 블로그 글 스타일만 설정하면 완료예요.
                                </div>

                                <div class="pw-field pw-field--chips" id="f3_1" data-section="3" data-pw-type="radiochips">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">포스팅 길이</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--req">필수</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">요약형은 짧게, 전문가형은 길고 상세하게 작성됩니다.</div></details>
                                    </div>
                                    <p class="pw-field-hint">검색 노출을 원하면 설명형·전문가형을 추천해요.</p>
                                    <div class="pw-chips">
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="postLengthModeRaw1" name="postLengthModeRaw" value="1" <?= $postLengthModeRaw === '1' ? 'checked' : '' ?>><span>요약형</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="postLengthModeRaw2" name="postLengthModeRaw" value="2" <?= $postLengthModeRaw === '2' ? 'checked' : '' ?>><span>설명형</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="postLengthModeRaw3" name="postLengthModeRaw" value="3" <?= $postLengthModeRaw === '3' ? 'checked' : '' ?>><span>전문가</span></label>
                                    </div>
                                    <div class="pw-length-hint">
                                        <span class="pw-length-hint__k">요약형</span> — 5~6개 문단, 핵심만 간결하게.<br>
                                        <span class="pw-length-hint__k">설명형</span> — 6~7개 문단, 이해를 돕는 설명이 추가됩니다.<br>
                                        <span class="pw-length-hint__k">전문가</span> — 7~8개 문단, 전문 용어를 활용해 깊게 설명합니다.
                                    </div>
                                </div>

                                <div class="pw-field" id="f3_2" data-section="3">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">홍보 포인트</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--opt">선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">기입한 내용이 생성 산출물에 반영됩니다. 자세히 적을수록 좋아요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">상품/서비스의 홍보 포인트를 기입해주세요. <strong class="pw-accent">(가능하면 10줄 이상 기입 권장)</strong></p>
                                    <textarea name="extra_strength" class="input--text pw-textarea" rows="8" placeholder="ex) 10년 이상 운영 경험, 누적 고객 5,000명 이상, 전문 자격 보유..."><?= htmlspecialchars($extra_strength, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>

                                <div class="pw-field pw-field--chips" id="f3_3" data-section="3" data-pw-type="radiochips">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">행동 유도 방식</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--opt">선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">글 마무리 CTA 스타일을 정합니다.</div></details>
                                    </div>
                                    <p class="pw-field-hint">글 마무리 분위기를 결정해요.</p>
                                    <div class="pw-chips">
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="action1" name="action_style" value="1" <?= $action_style === '1' ? 'checked' : '' ?>><span>정보만 제공하고 판단은 맡긴다.</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="action2" name="action_style" value="2" <?= $action_style === '2' ? 'checked' : '' ?>><span>관심이 생기도록 자연스럽게 유도한다.</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="action3" name="action_style" value="3" <?= $action_style === '3' ? 'checked' : '' ?>><span>지금 바로 행동하도록 안내한다.</span></label>
                                    </div>
                                </div>

                                <div class="pw-field" id="f3_4" data-section="3" data-pw-type="forbidden">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">피해야 할 표현 / 추가 금지 표현</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--opt">선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">금지 표현은 하나만 선택할 수 있어요. 추가 금지어는 콤마로 구분해 입력하세요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">표현 방식은 하나만 선택합니다. 추가 금지 표현은 콤마(,)로 구분해주세요.</p>
                                    <div class="pw-chips pw-chips--expr">
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="expression1" name="expression" value="1" <?= $expression_one === '1' ? 'checked' : '' ?>><span>과장된 표현</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="expression2" name="expression" value="2" <?= $expression_one === '2' ? 'checked' : '' ?>><span>가격·할인 언급</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="expression3" name="expression" value="3" <?= $expression_one === '3' ? 'checked' : '' ?>><span>타사 비교·비방 표현</span></label>
                                        <label class="pw-chip pw-chip--radio"><input type="radio" id="expression4" name="expression" value="4" <?= $expression_one === '4' ? 'checked' : '' ?>><span>기타</span></label>
                                    </div>
                                    <input type="text" class="input--text pw-real-input mgT10" name="forbidden_phrases" placeholder="추가 금지 표현 (콤마로 구분)" value="<?= htmlspecialchars($forbidden_phrases, ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="pw-field pw-field--chips" id="f3_5" data-section="3" data-pw-type="chips">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">콘텐츠 표현 방식</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--opt">선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">선택한 요소가 글 구조에 반영됩니다.</div></details>
                                    </div>
                                    <p class="pw-field-hint">복수 선택 가능합니다.</p>
                                    <div class="pw-chips">
                                        <label class="pw-chip"><input type="checkbox" id="content1" name="content_style[]" value="1" <?= in_array(1, $content_styles, true) ? 'checked' : '' ?>><span>짧은 문장 위주</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="content2" name="content_style[]" value="2" <?= in_array(2, $content_styles, true) ? 'checked' : '' ?>><span>핵심 요약</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="content3" name="content_style[]" value="3" <?= in_array(3, $content_styles, true) ? 'checked' : '' ?>><span>질문으로 마무리</span></label>
                                        <label class="pw-chip"><input type="checkbox" id="content4" name="content_style[]" value="4" <?= in_array(4, $content_styles, true) ? 'checked' : '' ?>><span>숫자·근거 강조</span></label>
                                    </div>
                                </div>

                                <div class="pw-field" id="f3_6" data-section="3" data-pw-type="image">
                                    <div class="pw-field-top">
                                        <div class="pw-field-label">이미지 첨부</div>
                                        <div class="pw-field-badges">
                                        <span class="pw-badge pw-badge--opt">선택</span>
                                        </div>
                                        <details class="pw-help"><summary class="pw-help__sum">도움말</summary><div class="pw-help__box">업로드한 이미지는 글 주제와 연관될 때 활용될 수 있어요.</div></details>
                                    </div>
                                    <p class="pw-field-hint">첨부하지 않으면 생성형 이미지로 대체될 수 있어요.</p>
                                    <div class="file__upload__wrap pw-file-wrap">
                                        <div class="file__upload__area pw-img-upload" data-dropzone="images">
                                            <p class="text--guide text--center pw-img-upload__txt">
                                                첨부할 파일을 여기에 끌어다 놓거나,<br>
                                                영역을 클릭해 파일을 선택해 주세요.
                                                <input type="file" id="imagesInput" name="images[]" accept="image/*" multiple class="mgT10">
                                            </p>
                                        </div>
                                    </div>
                                    <div class="file__list__wrap">
                                        <?php
                                            $imageCount = 0;
                                            if (is_array($files)) {
                                                foreach ($files as $ff) {
                                                    if (is_array($ff) && ($ff['file_type'] ?? '') === 'image') $imageCount++;
                                                }
                                            }
                                            $imageShowLimit = 8;
                                        ?>
                                        <div class="file__list__actions">
                                            <span class="text--small" style="color:#666;">총 <b><?= (int)$imageCount ?></b>개</span>
                                            <?php if ($imageCount > $imageShowLimit): ?>
                                                <button type="button" class="btn btn--small" data-toggle-list="imageFileList" data-limit="<?= (int)$imageShowLimit ?>">더보기</button>
                                            <?php endif; ?>
                                        </div>
                                        <ul class="file__list filegrid" id="imageFileList">
                                            <?php if (is_array($files) && count($files) > 0): ?>
                                                <?php $imgIdx = 0; ?>
                                                <?php foreach ($files as $ff): ?>
                                                    <?php if (is_array($ff) && ($ff['file_type'] ?? '') === 'image'): ?>
                                                        <?php $imgIdx++; $imgHidden = ($imgIdx > $imageShowLimit) ? ' is-hidden' : ''; ?>
                                                        <li class="file__list__item<?= $imgHidden ?>">
                                                            <a class="file__thumb" href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                                                                <img src="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="첨부 이미지">
                                                            </a>
                                                            <div class="file__meta">
                                                                <a class="file__name" href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" title="<?= htmlspecialchars((string)($ff['original_name'] ?? 'image'), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <?= htmlspecialchars((string)($ff['original_name'] ?? 'image'), ENT_QUOTES, 'UTF-8') ?>
                                                                </a>
                                                            <?php /* 이미지 삭제: 스크립트에서 fetch(..., ajax=1) — 페이지 갱신 없음. prompt.php 는 전통 form 제출 방식 사용. */ ?>
                                                            <button
                                                                type="button"
                                                                class="button--delete js-prompt-file-delete"
                                                                data-file-id="<?= (int)($ff['id'] ?? 0) ?>"
                                                                style="background:none;border:0;cursor:pointer;"
                                                                aria-label="삭제"
                                                            >
                                                                <img src="../images/x.svg" alt="">
                                                            </button>
                                                            </div>
                                                        </li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <li class="file__list__item file__list__item--empty"><span>첨부된 이미지가 없습니다.</span></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>

                                <div class="pw-btn-row">
                                    <button type="button" class="pw-btn-prev" id="pwGoStep2b">← 이전</button>
                                    <button type="button" class="pw-btn-next" id="pwGoStep4">완료 화면으로</button>
                                </div>
                            </div>

                            <!-- STEP 4 (완료 안내 → 제출) -->
                            <div class="pw-section" id="sec4">
                                <div class="pw-done-wrap">
                                    <div class="pw-done-icon"><svg width="38" height="38" fill="#3B6D11" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
                                    <div class="pw-done-title">입력을 확인했어요</div>
                                    <p class="pw-done-sub">총 3단계 입력이 완료됐어요.<br>아래 버튼을 누르면 저장되며, 신규 등록 시 안내 팝업이 나올 수 있어요.</p>
                                    <div class="pw-done-actions">
                                        <button type="button" class="pw-btn-prev" id="pwGoStep3b">← 수정하기</button>
                                        <button type="submit" id="promptSubmitButton" class="pw-btn-done">정보 입력 완료</button>
                                    </div>
                                </div>
                            </div>

                            <div class="pw-remote-bar" id="pwRemoteBar">
                                <div class="pw-remote-text"><strong>어렵거나 막히시나요?</strong> 담당자에게 문의해 도움을 받으실 수 있어요.</div>
                                <a href="prompt_list.php" class="pw-btn-help">목록으로</a>
                            </div>
                        </div>
                        </form>
                        <!-- 호환용 숨김 폼(다른 스크립트·접근성용). 이 페이지 이미지 삭제는 JS fetch(ajax=1) 사용 -->
                        <form id="deleteFileForm" method="post" action="prompt_file_delete.php" style="display:none;"></form>
                        <?php if ($showPublishNotice): ?>
                            <div id="publishNoticeModal" class="publish-notice-modal" aria-hidden="true">
                                <div class="publish-notice-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="publishNoticeTitle">
                                    <div class="publish-notice-modal__header">
                                        <span class="publish-notice-modal__eyebrow">Publish Notice</span>
                                        <h3 id="publishNoticeTitle" class="publish-notice-modal__title">자동 게시 생성 시작 전 확인</h3>
                                    </div>
                                    <div class="publish-notice-modal__body">
                                        <p>프롬프트 입력이 완료되었습니다.</p>
                                        <p>입력된 정보를 기반으로 약 2영업일 이후부터 게시물이 생성되며, 이후 영업일 기준 매일 최대 3개의 게시물이 생성됩니다.</p>
                                        <p>고객이 사례 정보를 입력한 경우 해당 사례가 포함된 게시물이 생성될 수 있습니다.</p>
                                        <p>생성된 게시물의 실제 게시 여부 판단 및 게시 행위에 대한 책임은 이용자에게 있습니다.</p>
                                        <p>생성된 콘텐츠는 자동 생성 콘텐츠이므로 실제 게시 전에 이용자가 내용의 정확성, 표현의 적절성, 권리 침해 여부 등을 직접 검수하고 필요 시 수정 후 게시해야 합니다.</p>
                                        <p>또한 동일하거나 유사한 홍보성 게시물을 반복적으로 게시하거나, 짧은 시간 내 여러 게시물을 연속 발행하는 방식은 블로그 운영에 불이익이 발생할 수 있으므로 권장되지 않습니다.</p>
                                    </div>
                                    <label class="publish-notice-modal__check">
                                        <input type="checkbox" id="publishNoticeConfirm">
                                        <span>[필수] 자동 게시 생성 방식 및 게시 전 검수 의무를 확인했습니다.</span>
                                    </label>
                                    <div class="publish-notice-modal__footer">
                                        <a href="prompt_list.php" class="btn publish-notice-modal__cancel">취소</a>
                                        <button type="button" id="publishNoticeAccept" class="btn btn--primary" disabled>자동 게시 생성 시작</button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <style>
        /* ── 프롬프트 마법사 (참고 HTML + 기존 input--text 등 재활용) ── */
        .prompt-wizard { max-width: 800px; margin: 0 auto; padding: 8px 0 32px; }
        .prompt-wizard__toolbar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
        .pw-step-bar { display: flex; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 8px; }
        .pw-step { display: flex; align-items: center; gap: 8px; }
        .pw-step__num {
            width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; flex-shrink: 0; transition: all .3s;
        }
        .pw-step--active .pw-step__num { background: #185FA5; color: #fff; box-shadow: 0 0 0 5px rgba(24,95,165,0.18); }
        .pw-step--done .pw-step__num { background: #0F6E56; color: #fff; }
        .pw-step--todo .pw-step__num { background: #F0F2F5; color: #9AA0A6; border: 1.5px solid #D8DADD; }
        .pw-step--active .pw-step__label { color: #185FA5; font-weight: 700; font-size: 13px; }
        .pw-step--done .pw-step__label { color: #0F6E56; font-size: 13px; }
        .pw-step--todo .pw-step__label { color: #9AA0A6; font-size: 13px; }
        .pw-step-line { flex: 1; height: 2px; background: #E1E3E5; margin: 0 8px; border-radius: 2px; min-width: 24px; transition: background .4s; }
        .pw-step-line--done { background: #0F6E56; }
        .pw-section { display: none; animation: pwFade .25s ease; }
        .pw-section--active { display: block; }
        @keyframes pwFade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        .pw-section-header {
            background: linear-gradient(135deg,#EEF5FD,#F5F9FF); border: 1px solid #C8DEFA; border-radius: 16px; padding: 20px 22px; margin-bottom: 16px;
            display: flex; align-items: flex-start; gap: 16px;
        }
        .pw-section-header__icon {
            width: 46px; height: 46px; background: #185FA5; border-radius: 13px; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; box-shadow: 0 4px 14px rgba(24,95,165,0.28);
        }
        .pw-section-header__icon svg { width: 24px; height: 24px; fill: #fff; }
        .pw-section-title { font-size: 19px; font-weight: 700; color: #1A1A1A; margin-bottom: 6px; }
        .pw-section-sub { font-size: 13px; color: #5F6368; line-height: 1.75; margin: 0; }
        .pw-accent { color: #185FA5; }

        .pw-demo-banner {
            display: flex; align-items: center; gap: 14px; background: #FFFBF0; border: 1.5px dashed #F5A623;
            border-radius: 14px; padding: 14px 18px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .pw-demo-banner__ico { font-size: 28px; flex-shrink: 0; }
        .pw-demo-banner__text { flex: 1; font-size: 13px; color: #7A4F00; line-height: 1.65; min-width: 200px; }
        .pw-demo-banner__text strong { display: block; font-size: 14px; color: #5A3800; margin-bottom: 2px; }
        .pw-demo-banner__btns { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; }
        .pw-btn-demo {
            background: linear-gradient(135deg,#F5A623,#F07C00); color: #fff; border: none; padding: 10px 20px; border-radius: 10px;
            font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; font-family: inherit;
            box-shadow: 0 3px 10px rgba(240,124,0,0.35);
        }
        .pw-btn-demo-reset {
            background: #fff; color: #9AA0A6; border: 1px solid #D8DADD; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit;
        }

        .pw-progress-row { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding: 11px 16px; background: #fff; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,0.06); }
        .pw-progress-bar-wrap { flex: 1; height: 7px; background: #E8EAED; border-radius: 7px; overflow: hidden; }
        .pw-progress-bar-fill { height: 100%; background: linear-gradient(90deg,#185FA5,#5CA3E6); border-radius: 7px; transition: width .5s ease; }
        .pw-progress-label { font-size: 12px; color: #5F6368; white-space: nowrap; }
        .pw-progress-label strong { color: #185FA5; }

        .pw-speech-bubble {
            background: linear-gradient(135deg,#185FA5,#2171BE); color: #fff; font-size: 13px; padding: 13px 17px; border-radius: 14px;
            border-bottom-left-radius: 4px; margin-bottom: 20px; line-height: 1.75; display: flex; align-items: flex-start; gap: 10px;
            box-shadow: 0 4px 18px rgba(24,95,165,0.25);
        }
        .pw-speech-bubble svg { width: 18px; height: 18px; fill: #fff; flex-shrink: 0; margin-top: 2px; }

        .pw-field { padding: 22px 20px; border-radius: 14px; border: 2px solid transparent; background: #f9f9f9; margin-bottom: 12px; position: relative; transition: border-color .25s, box-shadow .25s; }
        .pw-field.next-focus { border-color: #FF6B00; background: #FFFCF8; box-shadow: 0 0 0 5px rgba(255,107,0,0.10); }
        .pw-field.field-done { border-color: #D4EDDA; background: #FAFFFE; }
        .pw-field.field-done::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #0F6E56; border-radius: 14px 0 0 14px; }
        /* 다음 입력 안내: 헤더(뱃지·도움말)와 겹치지 않도록 필드 카드 우하단 */
        .next-tag {
            position: absolute;
            bottom: 14px;
            right: 18px;
            top: auto;
            z-index: 2;
            background: #FF6B00;
            color: #fff;
            font-size: 11.5px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            max-width: calc(100% - 36px);
            text-align: center;
            box-shadow: 0 2px 8px rgba(255, 107, 0, 0.35);
        }

        /* 1행: 라벨 | 뱃지 묶음 | 도움말(summary) / 2행: 도움말 본문 전체 너비 — 그리드로 브라우저 간 동일 */
        .pw-field-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            grid-template-rows: auto auto;
            column-gap: 8px;
            row-gap: 8px;
            align-items: center;
            margin-bottom: 8px;
        }
        .pw-field-label { font-size: 14px; font-weight: 700; color: #1A1A1A; grid-column: 1; grid-row: 1; min-width: 0; }
        .pw-field-badges { grid-column: 2; grid-row: 1; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .pw-badge { font-size: 11px; padding: 3px 10px; border-radius: 20px; font-weight: 600; white-space: nowrap; }
        .pw-badge--req { background: #FCEBEB; color: #A32D2D; }
        .pw-badge--opt { background: #F0F2F5; color: #7A8087; border: 1px solid #D8DADD; }
        .pw-badge--blue { background: #EEF5FD; color: #185FA5; border-color: #C8DEFA; }

        details.pw-help { display: contents; }
        .pw-help__sum {
            grid-column: 3;
            grid-row: 1;
            justify-self: end;
            list-style: none; display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px 3px 8px; border-radius: 20px;
            background: #E6F1FB; border: 1.5px solid #185FA5; color: #185FA5; font-size: 11px; font-weight: 700; cursor: pointer;
        }
        .pw-help__sum::-webkit-details-marker { display: none; }
        .pw-help__box {
            grid-column: 1 / -1;
            grid-row: 2;
            margin: 0;
            box-sizing: border-box;
            padding: 12px; background: #1C2B4A; color: #EEF4FF; font-size: 12.5px; border-radius: 10px; line-height: 1.75;
        }
        .pw-field-hint { font-size: 12px; color: #8A8F98; margin-bottom: 12px; line-height: 1.7; padding-left: 18px; position: relative; }
        .pw-field-hint::before { content: '💡'; position: absolute; left: 0; top: 0; font-size: 11px; }

        .prompt-wizard .input--text.pw-real-input,
        .prompt-wizard .input--text.pw-textarea {
            width: 100%; border: 1.5px solid #E1E3E5; border-radius: 10px; padding: 11px 14px; font-size: 14px;
            box-sizing: border-box; background: #fff; font-family: inherit;
        }
        .prompt-wizard textarea.pw-textarea { min-height: 160px; resize: vertical; line-height: 1.65; }

        /* filegrid: 빈 목록 안내가 한 칸만 쓰이지 않고 그리드 전체 너비(모든 열)를 쓰도록 */
        #imageFileList.filegrid .file__list__item--empty {
            grid-column: 1 / -1;
            justify-content: center;
            align-items: center;
            text-align: center;
            min-height: 34px;
        }
        #imageFileList.filegrid .file__list__item--empty span {
            color: #6b7280;
            font-size: 13px;
        }

        .pw-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
        .pw-chips--wrap .pw-chip { flex: 0 1 auto; }
        .pw-chip {
            display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 20px; font-size: 13px; cursor: pointer;
            border: 1.5px solid #E1E3E5; background: #F8F9FA; color: #5F6368; transition: all .18s; user-select: none; font-family: inherit;
        }
        .pw-chip input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .pw-chip:has(input:checked) { background: #E6F1FB; border-color: #185FA5; color: #185FA5; font-weight: 600; }
        .pw-chip:hover { border-color: #185FA5; color: #185FA5; background: #F0F7FF; }

        .pw-addr-row { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
        .pw-addr-btn { background: #185FA5; color: #fff; border: none; padding: 11px 16px; border-radius: 10px; font-size: 13px; cursor: pointer; font-weight: 600; font-family: inherit; }
        .pw-addr-btn:hover { background: #0C447C; }

        .pw-video-inline {
            display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 13px 16px; background: #F8F9FA; border: 1.5px solid #E1E3E5; border-radius: 12px;
        }
        .pw-video-play { width: 38px; height: 38px; background: #185FA5; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .pw-video-text__t { font-size: 13px; font-weight: 700; color: #1A1A1A; }
        .pw-video-text__s { font-size: 12px; color: #5F6368; margin-top: 2px; }
        .pw-video-badge { margin-left: auto; background: #E6F1FB; color: #185FA5; font-size: 11px; padding: 4px 11px; border-radius: 20px; font-weight: 600; border: 1px solid #C2DCFA; }

        .pw-length-hint { margin-top: 12px; font-size: 12px; color: #5F6368; line-height: 1.8; border-top: 1px solid #F0F2F5; padding-top: 12px; }
        .pw-length-hint__k { font-weight: 700; color: #334155; }

        .pw-btn-row { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }
        .pw-btn-prev { background: #F0F2F5; color: #5F6368; border: 1px solid #D8DADD; padding: 14px 24px; border-radius: 12px; font-size: 15px; cursor: pointer; font-family: inherit; }
        .pw-btn-next {
            flex: 1; min-width: 200px; background: linear-gradient(135deg,#185FA5,#2171BE); color: #fff; border: none; padding: 15px; border-radius: 12px;
            font-size: 16px; font-weight: 700; cursor: pointer; font-family: inherit; box-shadow: 0 4px 16px rgba(24,95,165,0.3);
        }
        .pw-img-upload { border: 2px dashed #C9CDD1; border-radius: 14px; cursor: pointer; transition: all .2s; }
        .pw-img-upload:hover { border-color: #185FA5; background: #F5F9FF; }
        .pw-warn { margin-top: 8px; padding: 8px 12px; border-radius: 8px; font-size: 12px; line-height: 1.6; }
        .pw-warn--age { background: #FFF8EC; border: 1px solid #FAC775; color: #7A4F00; }
        .pw-warn--tone { background: #FFF0F0; border: 1px solid #F5B8B8; color: #A32D2D; }

        .pw-done-wrap { text-align: center; padding: 48px 20px; background: #fff; border-radius: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); border: 1px solid #E8EAED; }
        .pw-done-icon { width: 76px; height: 76px; background: #EAF3DE; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 22px; }
        .pw-done-title { font-size: 24px; font-weight: 800; color: #1A1A1A; margin-bottom: 10px; }
        .pw-done-sub { font-size: 15px; color: #5F6368; line-height: 1.8; margin-bottom: 28px; }
        .pw-done-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
        .pw-btn-done {
            flex: 1; min-width: 220px; background: linear-gradient(135deg,#185FA5,#2171BE); color: #fff; border: none; padding: 17px 32px; border-radius: 14px;
            font-size: 17px; font-weight: 700; cursor: pointer; font-family: inherit; box-shadow: 0 4px 16px rgba(24,95,165,0.3);
        }

        .pw-remote-bar {
            background: #FFF8EC; border: 1.5px solid #FAC775; border-radius: 14px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;
            gap: 16px; margin-top: 24px; flex-wrap: wrap;
        }
        .pw-remote-text { font-size: 13px; color: #854F0B; line-height: 1.65; }
        .pw-remote-text strong { display: block; font-size: 14px; font-weight: 700; color: #633806; margin-bottom: 3px; }
        .pw-btn-help { background: #185FA5; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .pw-btn-help:hover { background: #0C447C; }

        @media (max-width: 768px) {
            .pw-step-line { display: none; }
            .pw-btn-next, .pw-btn-done { width: 100%; }
        }

        .publish-notice-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, 0.62);
            backdrop-filter: blur(6px);
            z-index: 10020;
        }

        .publish-notice-modal.is-open {
            display: flex;
        }

        .publish-notice-modal__dialog {
            width: min(720px, 100%);
            max-height: 86vh;
            overflow-y: auto;
            padding: 28px 26px 24px;
            border: 1px solid #dbe7ff;
            border-radius: 22px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.22);
        }

        .publish-notice-modal__header {
            margin-bottom: 16px;
        }

        .publish-notice-modal__eyebrow {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 999px;
            background: #eef4ff;
            color: #2563eb;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .publish-notice-modal__title {
            margin: 12px 0 0;
            color: #0f172a;
            font-size: 1.2rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .publish-notice-modal__body {
            color: #475569;
            font-size: 0.92rem;
            line-height: 1.8;
        }

        .publish-notice-modal__body p {
            margin: 0 0 12px;
        }

        .publish-notice-modal__check {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 20px;
            padding: 14px 16px;
            border: 1px solid #dbe7ff;
            border-radius: 14px;
            background: #f8fbff;
            color: #1e293b;
            font-size: 0.9rem;
            line-height: 1.7;
            cursor: pointer;
        }

        .publish-notice-modal__check input {
            margin-top: 4px;
            flex-shrink: 0;
        }

        .publish-notice-modal__footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .publish-notice-modal__cancel {
            background: #fff !important;
            color: #334155 !important;
            border: 1px solid #dbe3ef !important;
        }

        #publishNoticeAccept[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
            pointer-events: none;
        }

        body.publish-notice-open {
            overflow: hidden;
        }

        @media (max-width: 768px) {
            .publish-notice-modal {
                padding: 14px;
            }

            .publish-notice-modal__dialog {
                padding: 22px 18px 18px;
            }

            .publish-notice-modal__footer {
                flex-direction: column;
            }

            .publish-notice-modal__footer .btn {
                width: 100%;
            }
        }
    </style>
    

    <script>
        (function () {
            var SECTION_FIELDS = { 1: ['f1_1','f1_2','f1_3','f1_4','f1_5','f1_6','f1_7'], 2: ['f2_1','f2_2','f2_3','f2_4'], 3: ['f3_1','f3_2','f3_3','f3_4','f3_5','f3_6'] };
            var DEMO = {
                1: { brand_name: '한국인증센터', product_name: 'ESG경영, ISO인증, 경영컨설팅, 정부지원사업', industry: '경영컨설팅 / 인증기관', inquiry_channels: 'https://www.isopass.or.kr/pages/main', inquiry_phone: '02-713-9005', address_zip: '04155', address1: '서울 마포구 마혜대로 15', address2: '901호 한국인증센터', service_type: [1,2,3] },
                2: { goal: '3', age: [3,4,5], product_type: [3,8,4,5], tone: [2,4] },
                3: { postLengthModeRaw: '3', extra_strength: "ISO 심사원 교육과정 '우수교육기관' 선정, 신속하고 효율적인 인증획득", action_style: '2', expression: '2', forbidden_phrases: '', content_style: [1,2] }
            };

            function goStep(n) {
                var i, sec, tab, sn, line;
                for (i = 1; i <= 4; i++) {
                    sec = document.getElementById('sec' + i);
                    if (sec) {
                        sec.classList.toggle('pw-section--active', i === n);
                        sec.hidden = (i !== n);
                    }
                }
                for (i = 1; i <= 3; i++) {
                    tab = document.getElementById('pwTab' + i);
                    sn = document.getElementById('pwSn' + i);
                    if (!tab) continue;
                    tab.className = 'pw-step' + (i < n ? ' pw-step--done' : i === n ? ' pw-step--active' : ' pw-step--todo');
                    if (sn) sn.textContent = i < n ? '✓' : String(i);
                }
                for (i = 1; i <= 2; i++) {
                    line = document.getElementById('pwLine' + i);
                    if (line) line.className = 'pw-step-line' + (i < n ? ' pw-step-line--done' : '');
                }
                var rb = document.getElementById('pwRemoteBar');
                if (rb) rb.style.display = (n === 4) ? 'none' : 'flex';
                if (n <= 3) refreshHighlight(n);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            window.pwGoStep = goStep;

            function isFieldDone(fid) {
                var field = document.getElementById(fid);
                if (!field) return false;
                if (fid === 'f3_6') {
                    var inp = document.getElementById('imagesInput');
                    var hasNew = inp && inp.files && inp.files.length > 0;
                    var imgs = field.querySelectorAll('.file__list__item .file__thumb img');
                    return hasNew || imgs.length > 0;
                }
                if (fid === 'f1_7') {
                    var z = (document.getElementById('postcode') || {}).value || '';
                    var a = (document.getElementById('roadAddress') || {}).value || '';
                    return z.trim() !== '' && a.trim() !== '';
                }
                if (fid === 'f3_4') {
                    var ex = field.querySelector('input[name="expression"]:checked');
                    var fb = field.querySelector('input[name="forbidden_phrases"]');
                    return !!(ex || (fb && fb.value.trim() !== ''));
                }
                var pt = field.getAttribute('data-pw-type');
                if (pt === 'chips' || pt === 'tone' || pt === 'radiochips') {
                    return field.querySelectorAll('.pw-chip input:checked').length > 0;
                }
                var inputs = field.querySelectorAll('.pw-real-input, textarea.pw-textarea, textarea[name], input.input--text');
                if (inputs.length === 0) return true;
                for (var j = 0; j < inputs.length; j++) {
                    if (inputs[j].value && String(inputs[j].value).trim()) return true;
                }
                return false;
            }

            function refreshHighlight(sectionNum) {
                var fields = SECTION_FIELDS[sectionNum];
                if (!fields) return;
                var doneCount = 0, nextSet = false, total = fields.length;
                fields.forEach(function (fid) {
                    var el = document.getElementById(fid);
                    if (!el) return;
                    var oldTag = el.querySelector('.next-tag');
                    if (oldTag) oldTag.remove();
                    el.classList.remove('next-focus', 'field-done');
                    var done = isFieldDone(fid);
                    if (done) {
                        doneCount++;
                        el.classList.add('field-done');
                    } else if (!nextSet) {
                        el.classList.add('next-focus');
                        var tag = document.createElement('div');
                        tag.className = 'next-tag';
                        tag.textContent = '여기를 입력해주세요';
                        el.appendChild(tag);
                        nextSet = true;
                    }
                });
                var pct = total ? Math.round((doneCount / total) * 100) : 0;
                var fill = document.getElementById('pfill' + sectionNum);
                var lbl = document.getElementById('plabel' + sectionNum);
                if (fill) fill.style.width = pct + '%';
                if (lbl) lbl.textContent = String(doneCount);
            }
            window.pwRefreshHighlight = refreshHighlight;

            function capToneMax2() {
                var f = document.getElementById('f2_4');
                if (!f) return;
                var sel = Array.prototype.slice.call(f.querySelectorAll('input[name="tone[]"]:checked'));
                for (var i = 2; i < sel.length; i++) sel[i].checked = false;
            }

            function bindInputs() {
                document.querySelectorAll('.pw-field input, .pw-field textarea').forEach(function (inp) {
                    var h = function () {
                        var p = inp.closest('.pw-field');
                        if (p && p.dataset.section) refreshHighlight(parseInt(p.dataset.section, 10));
                    };
                    inp.addEventListener('input', h);
                    inp.addEventListener('change', h);
                });
            }

            function fillDemo(n) {
                var form = document.getElementById('promptForm');
                if (!form) return;
                if (n === 1) {
                    var d = DEMO[1];
                    form.querySelector('[name="brand_name"]').value = d.brand_name;
                    form.querySelector('[name="product_name"]').value = d.product_name;
                    form.querySelector('[name="industry"]').value = d.industry;
                    form.querySelector('[name="inquiry_channels"]').value = d.inquiry_channels;
                    form.querySelector('[name="inquiry_phone"]').value = d.inquiry_phone;
                    form.querySelector('[name="address_zip"]').value = d.address_zip;
                    form.querySelector('[name="address1"]').value = d.address1;
                    form.querySelector('[name="address2"]').value = d.address2;
                    form.querySelectorAll('input[name="service_type[]"]').forEach(function (c) { c.checked = d.service_type.indexOf(parseInt(c.value, 10)) >= 0; });
                    refreshHighlight(1);
                }
                if (n === 2) {
                    var d2 = DEMO[2];
                    var gr = form.querySelector('input[name="goal"][value="' + d2.goal + '"]');
                    if (gr) gr.checked = true;
                    form.querySelectorAll('input[name="age[]"]').forEach(function (c) { c.checked = d2.age.indexOf(parseInt(c.value, 10)) >= 0; });
                    form.querySelectorAll('input[name="product_type[]"]').forEach(function (c) { c.checked = d2.product_type.indexOf(parseInt(c.value, 10)) >= 0; });
                    form.querySelectorAll('input[name="tone[]"]').forEach(function (c) { c.checked = d2.tone.indexOf(parseInt(c.value, 10)) >= 0; });
                    capToneMax2();
                    refreshHighlight(2);
                }
                if (n === 3) {
                    var d3 = DEMO[3];
                    var pl = form.querySelector('input[name="postLengthModeRaw"][value="' + d3.postLengthModeRaw + '"]');
                    if (pl) pl.checked = true;
                    form.querySelector('[name="extra_strength"]').value = d3.extra_strength;
                    var ac = form.querySelector('input[name="action_style"][value="' + d3.action_style + '"]');
                    if (ac) ac.checked = true;
                    var ex = form.querySelector('input[name="expression"][value="' + d3.expression + '"]');
                    if (ex) ex.checked = true;
                    form.querySelector('[name="forbidden_phrases"]').value = d3.forbidden_phrases;
                    form.querySelectorAll('input[name="content_style[]"]').forEach(function (c) { c.checked = d3.content_style.indexOf(parseInt(c.value, 10)) >= 0; });
                    refreshHighlight(3);
                }
            }

            function resetSection(n) {
                var ids = SECTION_FIELDS[n];
                if (!ids) return;
                ids.forEach(function (fid) {
                    var field = document.getElementById(fid);
                    if (!field) return;
                    field.querySelectorAll('input, textarea').forEach(function (i) {
                        var t = (i.getAttribute('type') || '').toLowerCase();
                        if (t === 'checkbox' || t === 'radio') i.checked = false;
                        else i.value = '';
                    });
                });
                if (n === 1) {
                    var p = document.getElementById('postcode'), r = document.getElementById('roadAddress'), d = document.getElementById('detailAddress');
                    if (p) p.value = '';
                    if (r) r.value = '';
                    if (d) d.value = '';
                }
                refreshHighlight(n);
            }

            document.addEventListener('DOMContentLoaded', function () {
                var g = function (id, fn) { var el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
                g('pwGoStep2', function () { goStep(2); });
                g('pwGoStep1', function () { goStep(1); });
                g('pwGoStep3', function () { goStep(3); });
                g('pwGoStep2b', function () { goStep(2); });
                g('pwGoStep4', function () { goStep(4); });
                g('pwGoStep3b', function () { goStep(3); });
                g('pwFillDemo1', function () { fillDemo(1); });
                g('pwReset1', function () { resetSection(1); });
                g('pwFillDemo2', function () { fillDemo(2); });
                g('pwReset2', function () { resetSection(2); });
                g('pwFillDemo3', function () { fillDemo(3); });
                g('pwReset3', function () { resetSection(3); });

                var f22 = document.getElementById('f2_2');
                if (f22) {
                    f22.querySelectorAll('input[name="age[]"]').forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            var chips = f22.querySelectorAll('.pw-chip');
                            var sel = f22.querySelectorAll('input[name="age[]"]:checked');
                            var w = document.getElementById('warn_f2_2');
                            if (w) w.hidden = !(sel.length >= chips.length && chips.length > 0);
                            refreshHighlight(2);
                        });
                    });
                }
                var f24 = document.getElementById('f2_4');
                if (f24) {
                    f24.querySelectorAll('input[name="tone[]"]').forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            var sel = f24.querySelectorAll('input[name="tone[]"]:checked');
                            var w = document.getElementById('warn_f2_4');
                            if (sel.length > 2) {
                                cb.checked = false;
                                if (w) { w.hidden = false; setTimeout(function () { w.hidden = true; }, 2200); }
                            }
                            capToneMax2();
                            refreshHighlight(2);
                        });
                    });
                }
                capToneMax2();
                bindInputs();
                goStep(1);
            });
        })();

        (function () {
            function escapeHtml(s) {
                return String(s)
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            function setupDropzone(zone, input, list) {
                if (!zone || !input) return;

                function renderSelected() {
                    if (!list) return;
                    list.querySelectorAll(".file__list__item--new").forEach(function (el) { el.remove(); });

                    var files = Array.prototype.slice.call(input.files || []);

                    /* 저장 전 로컬 파일이 있으면 PHP 빈 안내(.file__list__item--empty)는 숨김 */
                    if (files.length > 0) {
                        list.querySelectorAll(".file__list__item--empty").forEach(function (el) { el.remove(); });
                    }

                    if (files.length === 0) {
                        /* 새로 고른 파일만 비운 경우: 서버 썸네일도 없으면 빈 안내 다시 표시 */
                        if (list.id === "imageFileList") {
                            var hasServer = list.querySelector(".file__thumb");
                            if (!hasServer && !list.querySelector(".file__list__item--empty")) {
                                var emptyLi = document.createElement("li");
                                emptyLi.className = "file__list__item file__list__item--empty";
                                emptyLi.innerHTML = "<span>첨부된 이미지가 없습니다.</span>";
                                list.appendChild(emptyLi);
                            }
                        }
                        return;
                    }

                    var frag = document.createDocumentFragment();
                    files.forEach(function (f) {
                        var li = document.createElement("li");
                        li.className = "file__list__item file__list__item--new";
                        li.innerHTML =
                            '<div class="file__meta">' +
                            '  <span class="file__name" title="' + escapeHtml(f.name) + '">[NEW] ' + escapeHtml(f.name) + '</span>' +
                            '</div>';
                        frag.appendChild(li);
                    });
                    list.prepend(frag);
                }

                input.addEventListener("change", renderSelected);

                zone.addEventListener("click", function (e) {
                    if (e.target && e.target.closest && e.target.closest('input[type="file"]')) return;
                    input.click();
                });

                ["dragenter", "dragover"].forEach(function (ev) {
                    zone.addEventListener(ev, function (e) {
                        e.preventDefault();
                        if (e.dataTransfer) e.dataTransfer.dropEffect = "copy";
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
                    if (dropped.length === 0) return;

                    var dt = new DataTransfer();
                    Array.prototype.slice.call(input.files || []).forEach(function (f) { dt.items.add(f); });
                    dropped.forEach(function (f) { dt.items.add(f); });
                    input.files = dt.files;

                    renderSelected();
                });

                renderSelected();
            }

            setupDropzone(
                document.querySelector('[data-dropzone="images"]'),
                document.getElementById("imagesInput"),
                document.getElementById("imageFileList")
            );

            setupDropzone(
                document.querySelector('[data-dropzone="videos"]'),
                document.getElementById("videosInput"),
                document.getElementById("videoFileList")
            );

            // 업로드된 파일이 많을 때: 기본은 일부만 보여주고 "더보기/접기"
            document.querySelectorAll('[data-toggle-list]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var listId = btn.getAttribute('data-toggle-list');
                    var limit = parseInt(btn.getAttribute('data-limit') || '0', 10);
                    var ul = document.getElementById(listId);
                    if (!ul || !limit) return;

                    // 새로 선택한 파일([NEW])은 항상 보이게 유지
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

            document.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest && e.target.closest('.js-prompt-file-delete');
                if (!btn) return;
                e.preventDefault();
                if (!window.confirm('이 파일을 삭제할까요?')) return;
                var id = btn.getAttribute('data-file-id');
                if (!id) return;
                var fd = new window.FormData();
                fd.append('file_id', id);
                fd.append('ajax', '1');
                var delUrl = 'prompt_file_delete.php';
                window.fetch(delUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (res) {
                        var ct = res.headers.get('Content-Type') || '';
                        if (ct.indexOf('application/json') === -1) {
                            throw new Error('bad response');
                        }
                        return res.json();
                    })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            window.alert((data && data.message) ? data.message : '삭제에 실패했습니다.');
                            return;
                        }
                        var li = btn.closest('.file__list__item');
                        if (li) li.parentNode.removeChild(li);
                        var ul = document.getElementById('imageFileList');
                        var actions = document.querySelector('.file__list__actions');
                        if (ul) {
                            var cnt = ul.querySelectorAll('.file__thumb img').length;
                            var newRows = ul.querySelectorAll('.file__list__item--new').length;
                            if (cnt === 0 && newRows === 0) {
                                ul.innerHTML = '<li class="file__list__item file__list__item--empty"><span>첨부된 이미지가 없습니다.</span></li>';
                            }
                        }
                        if (actions) {
                            var b = actions.querySelector('b');
                            if (b) {
                                var n = parseInt(b.textContent, 10);
                                if (!isNaN(n)) b.textContent = String(Math.max(0, n - 1));
                            }
                            var toggleBtn = actions.querySelector('[data-toggle-list="imageFileList"]');
                            var limit = toggleBtn ? parseInt(toggleBtn.getAttribute('data-limit') || '0', 10) : 0;
                            var total = 0;
                            if (ul) {
                                total = ul.querySelectorAll('.file__list__item .file__thumb img').length;
                            }
                            if (toggleBtn && limit > 0 && total <= limit) {
                                toggleBtn.style.display = 'none';
                                if (ul) {
                                    ul.querySelectorAll('.file__list__item.is-hidden').forEach(function (el) {
                                        el.classList.remove('is-hidden');
                                    });
                                }
                            }
                        }
                        if (typeof window.pwRefreshHighlight === 'function') {
                            window.pwRefreshHighlight(3);
                        }
                    })
                    .catch(function () {
                        window.alert('삭제 요청 중 오류가 발생했습니다.');
                    });
            });
        })();

        // 폼 필수 항목 검증
        (function () {
            var form = document.getElementById('promptForm');
            if (!form) return;
            var modal = document.getElementById('publishNoticeModal');
            var checkbox = document.getElementById('publishNoticeConfirm');
            var acceptBtn = document.getElementById('publishNoticeAccept');
            var needsPublishNotice = <?= $showPublishNotice ? 'true' : 'false' ?>;
            var publishNoticeAccepted = false;

            function openPublishNotice() {
                if (!modal) return;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('publish-notice-open');
            }

            function closePublishNotice() {
                if (!modal) return;
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('publish-notice-open');
            }

            if (checkbox && acceptBtn) {
                checkbox.checked = false;
                acceptBtn.disabled = true;

                checkbox.addEventListener('change', function () {
                    acceptBtn.disabled = !checkbox.checked;
                });

                acceptBtn.addEventListener('click', function () {
                    if (!checkbox.checked) return;
                    publishNoticeAccepted = true;
                    closePublishNotice();
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                });
            }

            var rules = [
                { type: 'text',     name: 'product_name',      label: '상품명 (서비스명)' },
                { type: 'text',     name: 'industry',           label: '업종' },
                { type: 'text',     name: 'inquiry_channels',   label: '문의/예약/결제 채널' },
                { type: 'text',     name: 'inquiry_phone',      label: '연락처' },
                { type: 'text',     name: 'address_zip',        label: '사업장주소 (우편번호)' },
                { type: 'text',     name: 'address1',           label: '사업장주소' },
                { type: 'radio',    name: 'goal',               label: '최우선 목표' },
                { type: 'radio',    name: 'postLengthModeRaw',  label: '포스팅 길이' },
                { type: 'checkbox', name: 'age[]',              label: '주요 타겟 연령대' },
                { type: 'checkbox', name: 'product_type[]',     label: '상품/서비스 강점' },
                { type: 'checkbox', name: 'tone[]',             label: '말하는 방식/톤' },
            ];

            form.addEventListener('submit', function (e) {
                var errors = [];

                rules.forEach(function (rule) {
                    if (rule.type === 'text') {
                        var el = form.querySelector('[name="' + rule.name + '"]');
                        if (!el || el.value.trim() === '') {
                            errors.push(rule.label);
                        }
                    } else if (rule.type === 'radio') {
                        var checked = form.querySelector('[name="' + rule.name + '"]:checked');
                        if (!checked) {
                            errors.push(rule.label);
                        }
                    } else if (rule.type === 'checkbox') {
                        var anyChecked = form.querySelector('[name="' + rule.name + '"]:checked');
                        if (!anyChecked) {
                            errors.push(rule.label);
                        }
                    }
                });

                if (errors.length > 0) {
                    e.preventDefault();
                    alert('다음 필수 항목을 입력해주세요:\n\n' + errors.map(function (l) { return '• ' + l; }).join('\n'));

                    var firstRule = rules.find(function (r) { return r.label === errors[0]; });
                    var stepByName = {
                        product_name: 1, industry: 1, inquiry_channels: 1, inquiry_phone: 1, address_zip: 1, address1: 1,
                        goal: 2, 'age[]': 2, 'product_type[]': 2, 'tone[]': 2,
                        postLengthModeRaw: 3
                    };
                    if (firstRule && typeof window.pwGoStep === 'function') {
                        var sn = stepByName[firstRule.name];
                        if (sn) window.pwGoStep(sn);
                    }
                    if (firstRule) {
                        var firstEl = form.querySelector('[name="' + firstRule.name + '"]');
                        if (firstEl) {
                            firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            if (firstEl.focus) try { firstEl.focus(); } catch (x) {}
                        }
                    }
                    return;
                }

                if (needsPublishNotice && !publishNoticeAccepted) {
                    e.preventDefault();
                    openPublishNotice();
                }
            });
        })();

    </script>
	
	<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
	<script>
		
            function execDaumPostcode() {
                new daum.Postcode({
                    oncomplete: function(data) {
                        // 팝업에서 검색결과 항목을 클릭했을때 실행할 코드를 작성하는 부분.

                        // 도로명 주소의 노출 규칙에 따라 주소를 표시한다.
                        // 내려오는 변수가 값이 없는 경우엔 공백('')값을 가지므로, 이를 참고하여 분기 한다.
                        var roadAddr = data.roadAddress; // 도로명 주소 변수
                        var extraRoadAddr = ''; // 추가 정보 변수

                        // 법정동명이 있을 경우 추가한다. (법정리는 제외)
                        // 법정동의 경우 마지막 문자가 "동/로/가"로 끝난다.
                        if(data.bname !== '' && /[동|로|가]$/g.test(data.bname)){
                            extraRoadAddr += data.bname;
                        }
                        // 건물명이 있고, 공동주택일 경우 추가한다.
                        if(data.buildingName !== '' && data.apartment === 'Y'){
                            extraRoadAddr += (extraRoadAddr !== '' ? ', ' + data.buildingName : data.buildingName);
                        }
                        // 표시할 참고항목이 있을 경우, 괄호까지 추가한 최종 문자열을 만든다.
                        if(extraRoadAddr !== ''){
                            extraRoadAddr = ' (' + extraRoadAddr + ')';
                        }

                        // 우편번호와 주소 정보를 해당 필드에 넣는다.
                        document.getElementById('postcode').value = data.zonecode;
                        document.getElementById("roadAddress").value = roadAddr;
                        //document.getElementById("jibunAddress").value = data.jibunAddress;
                 
                    }
                }).open();
            }
        
	</script>
    <?php include '../footer.inc.php'; ?>