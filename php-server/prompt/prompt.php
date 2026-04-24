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
						
						<h3 class="content__title">업체정보<span class="text--small"></span></h3>
                        <table class="table--prompt">
                            <tr>
                                <th>브랜드명 (서비스명)</th>
                                <td><input type="text" class="input--text" name="brand_name" placeholder="브랜드명 (서비스명)을 입력해주세요. ex) 강남동물의료센터" value="<?= htmlspecialchars($brand_name, ENT_QUOTES, 'UTF-8') ?>"></td>
                            </tr>
                            <tr>
                                <th>상품명 (서비스명)<span class="required--blue">*</span></th>
                                <td><input type="text" class="input--text" name="product_name" placeholder="상품명 (서비스명)을 입력해주세요. 복수선택 가능, 복수선택시 ,(콤마)로구분" required value="<?= htmlspecialchars($product_name, ENT_QUOTES, 'UTF-8') ?>"></td>
                            </tr>
                            <tr>
                                <th>업종<span class="required--blue">*</span></th>
                                <td><input type="text" class="input--text" name="industry" placeholder="업종을 입력해주세요." required value="<?= htmlspecialchars($industry, ENT_QUOTES, 'UTF-8') ?>"></td>
                            </tr>
                            <tr>
                                <th>문의/예약/결제 채널 <span class="required--blue">*</span> <span
                                        class="text--small mgL10">복수선택 가능</span></th>
                                <td>
                                    <input type="text" class="input--text" name="inquiry_channels" placeholder="연락처, 이메일, 홈페이지 등 실제로 문의/예약/결제를 진행하는 채널 입력해주세요" required value="<?= htmlspecialchars($inquiry_channels, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide">복수선택시 ,(콤마)로 구분해주세요.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>연락처<span class="required--blue">*</span> <span
                                        class="text--small mgL10">복수선택 가능</span></th>
                                <td>
                                    <input type="text" class="input--text" name="inquiry_phone" placeholder="대표번호 또는 별도의 유선 번호, 대표 이메일을 입력해주세요. 복수선택시 ,(콤마)로 구분" required value="<?= htmlspecialchars($inquiry_phone, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide">복수선택시 ,(콤마)로 구분해주세요.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>서비스 형태 <span class="text--small mgL10">복수선택 가능</span></th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="service_type1">
                                            <input type="checkbox" id="service_type1" name="service_type[]" value="1" <?= in_array(1, $service_types, true) ? 'checked' : '' ?>>
                                            <span>오프라인 매장</span>
                                        </label>
                                        <label for="service_type2">
                                            <input type="checkbox" id="service_type2" name="service_type[]" value="2" <?= in_array(2, $service_types, true) ? 'checked' : '' ?>>
                                            <span>온라인 서비스</span>
                                        </label>
                                        <label for="service_type3">
                                            <input type="checkbox" id="service_type3" name="service_type[]" value="3" <?= in_array(3, $service_types, true) ? 'checked' : '' ?>>
                                            <span>전국 서비스</span>
                                        </label>
                                        <label for="service_type4">
                                            <input type="checkbox" id="service_type4" name="service_type[]" value="4" <?= in_array(4, $service_types, true) ? 'checked' : '' ?>>
                                            <span>프랜차이즈</span>
                                        </label>
                                        <label for="service_type5">
                                            <input type="checkbox" id="service_type5" name="service_type[]" value="5" <?= in_array(5, $service_types, true) ? 'checked' : '' ?>>
                                            <span>기타</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th>사업장주소 <span class="required--blue">*</span> </th>
                                <td>
                                    <div class="address--box">
                                        <div class="address--box__item flex gap-16">
                                            <input type="text" class="input--text" id="postcode" name="address_zip" placeholder="우편번호를 입력해주세요."  readonly required value="<?= htmlspecialchars($address_zip, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="button" class="button--search" onclick="execDaumPostcode();">검색</button>
                                        </div>
                                        <input type="text" class="input--text mgT10" id="roadAddress" name="address1" placeholder="주소" required value="<?= htmlspecialchars($address1, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="text" class="input--text mgT10" id="detailAddress" name="address2" placeholder="상세주소를 입력해주세요." value="<?= htmlspecialchars($address2, ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </td>
                            </tr>
						</table>
						<h3 class="content__title">생정정보<span class="text--small"></span></h3>
                        <table class="table--prompt">
                            <tr>
                                <th>최우선 목표 <span class="required--blue">*</span> </th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="goal1">
                                            <input type="radio" id="goal1" name="goal" value="1" required <?= $goal === '1' ? 'checked' : '' ?>>
                                            <span>매출을 늘리고 싶다</span>
                                        </label>
                                        <label for="goal2">
                                            <input type="radio" id="goal2" name="goal" value="2" <?= $goal === '2' ? 'checked' : '' ?>>
                                            <span>예약·방문을 늘리고 싶다.</span>
                                        </label>
                                        <label for="goal3">
                                            <input type="radio" id="goal3" name="goal" value="3" <?= $goal === '3' ? 'checked' : '' ?>>
                                            <span>문의·상담을 늘리고 싶다.</span>
                                        </label>
                                        <label for="goal4">
                                            <input type="radio" id="goal4" name="goal" value="4" <?= $goal === '4' ? 'checked' : '' ?>>
                                            <span>브랜드를 알리고 싶다.</span>
                                        </label>
                                        <label for="goal5">
                                            <input type="radio" id="goal5" name="goal" value="5" <?= $goal === '5' ? 'checked' : '' ?>>
                                            <span>신뢰를 확보하고 싶다.</span>
                                        </label>
                                        <label for="goal6">
                                            <input type="radio" id="goal6" name="goal" value="6" <?= $goal === '6' ? 'checked' : '' ?>>
                                            <span>기타</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th>주요 타겟 연령대<span class="required--blue">*</span><span class="text--small mgL10">복수선택
                                        가능</span> </th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="age1">
                                            <input type="checkbox" id="age1" name="age[]" value="1" <?= in_array(1, $ages, true) ? 'checked' : '' ?>>
                                            <span>10대</span>
                                        </label>
                                        <label for="age2">
                                            <input type="checkbox" id="age2" name="age[]" value="2" <?= in_array(2, $ages, true) ? 'checked' : '' ?>>
                                            <span>20대</span>
                                        </label>
                                        <label for="age3">
                                            <input type="checkbox" id="age3" name="age[]" value="3" <?= in_array(3, $ages, true) ? 'checked' : '' ?>>
                                            <span>30대</span>
                                        </label>
                                        <label for="age4">
                                            <input type="checkbox" id="age4" name="age[]" value="4" <?= in_array(4, $ages, true) ? 'checked' : '' ?>>
                                            <span>40대</span>
                                        </label>
                                        <label for="age5">
                                            <input type="checkbox" id="age5" name="age[]" value="5" <?= in_array(5, $ages, true) ? 'checked' : '' ?>>
                                            <span>50대</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th>상품/서비스 강점<span class="required--blue">*</span><span class="text--small mgL10">복수선택
                                        가능</span> </th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="product_type1">
                                            <input type="checkbox" id="product_type1" name="product_type[]" value="1" <?= in_array(1, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>가격이 합리적이다.</span>
                                        </label>
                                        <label for="product_type2">
                                            <input type="checkbox" id="product_type2" name="product_type[]" value="2" <?= in_array(2, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>결과·성과가 명확하다.</span>
                                        </label>
                                        <label for="product_type3">
                                            <input type="checkbox" id="product_type3" name="product_type[]" value="3" <?= in_array(3, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>전문 인력이 직접 제공한다.</span>
                                        </label>
                                        <label for="product_type4">
                                            <input type="checkbox" id="product_type4" name="product_type[]" value="4" <?= in_array(4, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>처리 속도가 빠르다.</span>
                                        </label>
                                        <label for="product_type5">
                                            <input type="checkbox" id="product_type5" name="product_type[]" value="5" <?= in_array(5, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>경험·사례가 많다.</span>
                                        </label>
                                        <label for="product_type6">
                                            <input type="checkbox" id="product_type6" name="product_type[]" value="6" <?= in_array(6, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>접근성이 좋다.</span>
                                        </label>
                                        <label for="product_type7">
                                            <input type="checkbox" id="product_type7" name="product_type[]" value="7" <?= in_array(7, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>사후 관리가 잘 된다.</span>
                                        </label>
                                        <label for="product_type8">
                                            <input type="checkbox" id="product_type8" name="product_type[]" value="8" <?= in_array(8, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>공식 인증·자격을 보유하고 있다.</span>
                                        </label>
                                        <label for="product_type9">
                                            <input type="checkbox" id="product_type9" name="product_type[]" value="9" <?= in_array(9, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>기술력이 높다.</span>
                                        </label>
                                        <label for="product_type10">
                                            <input type="checkbox" id="product_type10" name="product_type[]" value="10" <?= in_array(10, $product_strengths, true) ? 'checked' : '' ?>>
                                            <span>기타</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th>말하는 방식/톤<span class="required--blue">*</span> <span class="text--small mgL10">복수선택
                                        가능</span></th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="tone1">
                                            <input type="checkbox" id="tone1" name="tone[]" value="1" <?= in_array(1, $tones, true) ? 'checked' : '' ?>>
                                            <span>차분하게 설명한다.</span>
                                        </label>
                                        <label for="tone2">
                                            <input type="checkbox" id="tone2" name="tone[]" value="2" <?= in_array(2, $tones, true) ? 'checked' : '' ?>>
                                            <span>친절하게 쉽게 설명한다.</span>
                                        </label>
                                        <label for="tone3">
                                            <input type="checkbox" id="tone3" name="tone[]" value="3" <?= in_array(3, $tones, true) ? 'checked' : '' ?>>
                                            <span>단호하고 확신 있게 말한다.</span>
                                        </label>
                                        <label for="tone4">
                                            <input type="checkbox" id="tone4" name="tone[]" value="4" <?= in_array(4, $tones, true) ? 'checked' : '' ?>>
                                            <span>전문가가 조언하는 느낌.</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                            <!-- <tr>
                                <th>기존 포스팅 스타일 유지 <span class="required--blue">*</span> </th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="style1">
                                            <input type="radio" id="style1" name="keep_style" value="1" required <?= $keep_style === '1' ? 'checked' : '' ?>>
                                            <span>유지한다.</span>
                                        </label>
                                        <label for="style2">
                                            <input type="radio" id="style2" name="keep_style" value="2" <?= $keep_style === '2' ? 'checked' : '' ?>>
                                            <span>유지하지 않는다.</span>
                                        </label>
                                        <input type="text" class="input--text mgT10" name="style_url" placeholder="URL을 입력해주세요." value="<?= htmlspecialchars($style_url, ENT_QUOTES, 'UTF-8') ?>">
                                    </fieldset>
                                </td>
                            </tr> -->
                            <tr>
                                <th>포스팅 길이 <span class="required--blue">*</span> </th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="postLengthModeRaw1">
                                            <input type="radio" id="postLengthModeRaw1" name="postLengthModeRaw" value="1" required <?= $postLengthModeRaw === '1' ? 'checked' : '' ?>>
                                            <span>요약형</span>
                                        </label>
                                        <label for="postLengthModeRaw2">
                                            <input type="radio" id="postLengthModeRaw2" name="postLengthModeRaw" value="2" <?= $postLengthModeRaw === '2' ? 'checked' : '' ?>>
                                            <span>설명형</span>
                                        </label>
                                        <label for="postLengthModeRaw3">
                                            <input type="radio" id="postLengthModeRaw3" name="postLengthModeRaw" value="3" <?= $postLengthModeRaw === '3' ? 'checked' : '' ?>>
                                            <span>전문가</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
							<tr>
                                <th>홍보 포인트</th>
                                <td>
								<textarea name="extra_strength" placeholder="상품/서비스의 홍보 포인트를 기입해 주세요.기입한 내용은 생성되는 산출물 내용에 반영되기 때문에 최대한 자세하게 많이 적어주실수록 보다 양질의 산출물이 생성됩니다.

ex) 피부질환 전문, 외이질환 전문, 심장질환 전문, 슬개골탈구 전문, 10년 이상 경력의 수의사 5명 진료, 100평 이상의 넓은 공간, 중성화수술 100회 이상 진행 등"  id="" class="input--text"cols="30" rows="10" style="border:0;appearance: none;
    border: none;
    outline: none;
    font-family: inherit;
    font-size: inherit;"><?= htmlspecialchars($extra_strength, ENT_QUOTES, 'UTF-8') ?></textarea>
								</td>
                            </tr>
                            <tr>
                                <th>행동 유도 방식</th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="action1">
                                            <input type="radio" id="action1" name="action_style" value="1" <?= $action_style === '1' ? 'checked' : '' ?>>
                                            <span>정보만 제공하고 판단은 맡긴다.</span>
                                        </label>
                                        <label for="action2">
                                            <input type="radio" id="action2" name="action_style" value="2" <?= $action_style === '2' ? 'checked' : '' ?>>
                                            <span>관심이 생기도록 자연스럽게 유도한다.</span>
                                        </label>
                                        <label for="action3">
                                            <input type="radio" id="action3" name="action_style" value="3" <?= $action_style === '3' ? 'checked' : '' ?>>
                                            <span>지금 바로 행동하도록 안내한다.</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr> 
                            <tr>
                                <th>피해야 할 표현 방식<span class="text--small mgL10">복수선택 가능</span></th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="expression1">
                                            <input type="radio" id="expression1" name="expression" value="1" <?= $expression_one === '1' ? 'checked' : '' ?>>
                                            <span>과장된 표현</span> 
                                        </label>
                                        <label for="expression2">
                                            <input type="radio" id="expression2" name="expression" value="2" <?= $expression_one === '2' ? 'checked' : '' ?>>
                                            <span>가격·할인 언급</span>
                                        </label>
                                        <label for="expression3">
                                            <input type="radio" id="expression3" name="expression" value="3" <?= $expression_one === '3' ? 'checked' : '' ?>>
                                            <span>타사 비교·비방 표현</span>
                                        </label>
                                        <label for="expression4">
                                            <input type="radio" id="expression4" name="expression" value="4" <?= $expression_one === '4' ? 'checked' : '' ?>>
                                            <span>기타</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th>추가 금지 표현</th>
                                <td>
                                    <input type="text" class="input--text" name="forbidden_phrases" placeholder="추가 금지 표현을 입력해주세요." value="<?= htmlspecialchars($forbidden_phrases, ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="text--guide mgT10">콤마(,)로 구분해주세요.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>콘텐츠 표현 방식 <span class="text--small mgL10">복수선택 가능</span></th>
                                <td>
                                    <fieldset class="fieldset--flex">
                                        <label for="content1">
                                            <input type="checkbox" id="content1" name="content_style[]" value="1" <?= in_array(1, $content_styles, true) ? 'checked' : '' ?>>
                                            <span>짧은 문장 위주</span>
                                        </label>
                                        <label for="content2">
                                            <input type="checkbox" id="content2" name="content_style[]" value="2" <?= in_array(2, $content_styles, true) ? 'checked' : '' ?>>
                                            <span>핵심 요약</span>
                                        </label>
                                        <label for="content3">
                                            <input type="checkbox" id="content3" name="content_style[]" value="3" <?= in_array(3, $content_styles, true) ? 'checked' : '' ?>>
                                            <span>질문으로 마무리</span>
                                        </label>
                                        <label for="content4">
                                            <input type="checkbox" id="content4" name="content_style[]" value="4" <?= in_array(4, $content_styles, true) ? 'checked' : '' ?>>
                                            <span>숫자·근거 강조</span>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
							
                            <tr>
                                <th>이미지 첨부</th>
                                <td>
                                    <div class="file__upload__wrap">
                                        <div class="file__upload__area" data-dropzone="images">
                                            <p class="text--guide text--center">
                                                첨부할 파일을 여기에 끌어다 놓거나,<br>
                                                파일 선택 버튼을 눌러 파일을 직접 선택해 주세요.

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
                                                            <?php /* 이미지 삭제: deleteFileForm 전송 → prompt_file_delete.php 가 리다이렉트(페이지 갱신). ajax 미사용. */ ?>
                                                            <button
                                                                type="submit"
                                                                form="deleteFileForm"
                                                                name="file_id"
                                                                value="<?= (int)($ff['id'] ?? 0) ?>"
                                                                class="button--delete"
                                                                onclick="return confirm('이 파일을 삭제할까요?');"
                                                                style="background:none;border:0;cursor:pointer;"
                                                            >
                                                                <img src="../images/x.svg" alt="삭제">
                                                            </button>
                                                            </div>
                                                        </li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <li class="file__list__item"><span>첨부된 이미지가 없습니다.</span></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <!-- <tr>
                                <th>동영상 첨부<span class="required--blue">*</span> </th>
                                <td>
                                    <div class="file__upload__wrap">
                                        <div class="file__upload__area" data-dropzone="videos">
                                            <p class="text--guide text--center">
                                                첨부할 파일을 여기에 끌어다 놓거나,<br>
                                                파일 선택 버튼을 눌러 파일을 직접 선택해 주세요.
                            
                                                <input type="file" id="videosInput" name="videos[]" accept="video/*" multiple class="mgT10">
                                            </p>
                                        </div>
                                    </div>
                                    <div class="file__list__wrap">
                                        <?php
                                            $videoCount = 0;
                                            if (is_array($files)) {
                                                foreach ($files as $ff) {
                                                    if (is_array($ff) && ($ff['file_type'] ?? '') === 'video') $videoCount++;
                                                }
                                            }
                                            $videoShowLimit = 6;
                                        ?>
                                        <div class="file__list__actions">
                                            <span class="text--small" style="color:#666;">총 <b><?= (int)$videoCount ?></b>개</span>
                                            <?php if ($videoCount > $videoShowLimit): ?>
                                                <button type="button" class="btn btn--small" data-toggle-list="videoFileList" data-limit="<?= (int)$videoShowLimit ?>">더보기</button>
                                            <?php endif; ?>
                                        </div>
                                        <ul class="file__list filegrid" id="videoFileList">
                                            <?php if (is_array($files) && count($files) > 0): ?>
                                                <?php $vidIdx = 0; ?>
                                                <?php foreach ($files as $ff): ?>
                                                    <?php if (is_array($ff) && ($ff['file_type'] ?? '') === 'video'): ?>
                                                        <?php $vidIdx++; $vidHidden = ($vidIdx > $videoShowLimit) ? ' is-hidden' : ''; ?>
                                                        <li class="file__list__item<?= $vidHidden ?>">
                                                            <a class="file__thumb" href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                                                                <span style="font-weight:800; color:#111;">VIDEO</span>
                                                            </a>
                                                            <div class="file__meta">
                                                                <a class="file__name" href="../<?= htmlspecialchars((string)($ff['stored_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" title="<?= htmlspecialchars((string)($ff['original_name'] ?? 'video'), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <?= htmlspecialchars((string)($ff['original_name'] ?? 'video'), ENT_QUOTES, 'UTF-8') ?>
                                                                </a>
                                                            <button
                                                                type="submit"
                                                                form="deleteFileForm"
                                                                name="file_id"
                                                                value="<?= (int)($ff['id'] ?? 0) ?>"
                                                                class="button--delete"
                                                                onclick="return confirm('이 파일을 삭제할까요?');"
                                                                style="background:none;border:0;cursor:pointer;"
                                                            >
                                                                <img src="../images/x.svg" alt="삭제">
                                                            </button>
                                                            </div>
                                                        </li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <li class="file__list__item"><span>첨부된 동영상이 없습니다.</span></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr> -->
                        </table>
                        <!-- <h3 class="content__title">추가정보 입력 <span class="text--small">(선택사항)</span></h3>
                        <table class="table--prompt">
                            
                            
                        </table> -->
                        <div class="button__wrap">
                            <a href="prompt_list.php" class="btn">취소</a>
                            <button type="submit" id="promptSubmitButton" class="btn btn--primary">정보 입력 완료</button>
                        </div>
                        </form>
                        <!-- 삭제 전용 폼(전통 방식): ajax 파라미터 없음 → 삭제 후 서버가 prompt.php 로 이동 -->
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
                    var olds = list.querySelectorAll(".file__list__item--new");
                    olds.forEach(function (el) { el.remove(); });

                    var files = Array.prototype.slice.call(input.files || []);
                    if (files.length === 0) return;

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

                    // 첫 번째 오류 항목으로 스크롤
                    var firstRule = rules.find(function (r) { return errors.indexOf(r.label) !== -1; });
                    if (firstRule) {
                        var firstEl = form.querySelector('[name="' + firstRule.name + '"]');
                        if (firstEl) {
                            firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            if (firstEl.focus) firstEl.focus();
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