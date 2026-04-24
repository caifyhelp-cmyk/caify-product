<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/n8n_client.php';

require_login();
$member = current_member();

$memberPk = (int)($member['id'] ?? 0);
$memberId = (string)($member['member_id'] ?? '');

if ($memberPk <= 0) {
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: prompt.html');
    exit;
}

function swal_and_redirect(string $title, string $text, string $icon, string $redirectUrl): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $i = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $r = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>알림</title></head><body>';
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script>';
    echo 'var title="' . $t . '";';
    echo 'var text="' . $m . '";';
    echo 'var icon="' . $i . '";';
    echo 'var redirect="' . $r . '";';
    echo 'function go(){ window.location.href = redirect; }';
    echo 'if (typeof Swal !== "undefined") {';
    echo '  Swal.fire({title:title,text:text,icon:icon,confirmButtonText:"확인"}).then(go);';
    echo '} else {';
    echo '  alert(title + "\\n\\n" + text); go();';
    echo '}';
    echo '</script></body></html>';
    exit;
}

function post_str(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function post_int(string $key): ?int
{
    $v = trim((string)($_POST[$key] ?? ''));
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^\d+$/', $v)) {
        return null;
    }
    return (int)$v;
}

function post_array_int(string $key): array
{
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if ($v !== '' && preg_match('/^\d+$/', $v)) {
            $out[] = (int)$v;
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

function json_or_null(array $arr): ?string
{
    if (count($arr) === 0) {
        return null;
    }
    return json_encode($arr, JSON_UNESCAPED_UNICODE);
}

function normalize_files(string $key): array
{
    if (empty($_FILES[$key]) || !is_array($_FILES[$key])) {
        return [];
    }

    $f = $_FILES[$key];
    // multiple
    if (isset($f['name']) && is_array($f['name'])) {
        $out = [];
        $count = count($f['name']);
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'name' => (string)($f['name'][$i] ?? ''),
                'type' => (string)($f['type'][$i] ?? ''),
                'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($f['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    // single
    return [[
        'name' => (string)($f['name'] ?? ''),
        'type' => (string)($f['type'] ?? ''),
        'tmp_name' => (string)($f['tmp_name'] ?? ''),
        'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($f['size'] ?? 0),
    ]];
}

$brand_name = post_str('brand_name');
$product_name = post_str('product_name');
$industry = post_str('industry');
$inquiry_channels = post_str('inquiry_channels');
$service_types = post_array_int('service_type');
$address_zip = post_str('address_zip');
$address1 = post_str('address1');
$address2 = post_str('address2');
$goal = post_int('goal');
$ages = post_array_int('age');
$product_types = post_array_int('product_type');
$postLengthModeRaw = post_int('postLengthModeRaw');
$tones = post_array_int('tone');
$keep_style = post_int('keep_style');
$style_url = post_str('style_url');
$content_styles = post_array_int('content_style');
$inquiry_phone = post_str('inquiry_phone');
$extra_strength = post_str('extra_strength');
$action_style = post_int('action_style');
$expression = post_int('expression'); // UI는 radio(단일 선택) -> DB는 expressions(JSON array)로 저장
$forbidden_phrases = post_str('forbidden_phrases');

// 최소 검증(필수 표시된 항목 위주)
$errors = [];
if ($product_name === '') $errors[] = '상품명은 필수입니다.';
if ($industry === '') $errors[] = '업종은 필수입니다.';
if ($inquiry_channels === '') $errors[] = '문의/예약/결제 채널은 필수입니다.';
if ($address_zip === '' || $address1 === '') $errors[] = '사업장 주소(우편번호/주소)는 필수입니다.';
if ($goal === null) $errors[] = '최우선 목표를 선택해주세요.';


if (count($errors) > 0) {
    swal_and_redirect('입력 오류', implode(' ', $errors), 'warning', 'prompt.php');
}

// 업로드 경로 준비: /upload/<member_id>/
$rootUploadDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'upload';
if ($rootUploadDir === false) {
    $rootUploadDir = __DIR__ . '/../upload';
}

$memberFolder = safe_member_folder($memberId, $memberPk);
$userUploadAbs = rtrim($rootUploadDir, '\\/') . DIRECTORY_SEPARATOR . $memberFolder;

if (!is_dir($userUploadAbs)) {
    if (!mkdir($userUploadAbs, 0775, true) && !is_dir($userUploadAbs)) {
        swal_and_redirect('오류', '업로드 폴더 생성에 실패했습니다.', 'error', 'prompt.php');
    }
}

$pdo = db();
$movedFiles = [];
$newFileUrls = [];
$newImageFilesForAnalysis = []; // 트랜잭션 밖에서 이미지 분석(best-effort)용

try {
    $pdo->beginTransaction();

    // 멤버당 1개: 기존 데이터 있으면 UPDATE, 없으면 INSERT
    $find = $pdo->prepare(
        'SELECT id, brand_name, product_name, industry, inquiry_channels, service_types,
                address_zip, address1, address2, goal, ages, product_strengths, tones, keep_style, style_url,
                content_styles, extra_strength, action_style, expressions, forbidden_phrases, postLengthModeRaw, inquiry_phone
           FROM caify_prompt
           WHERE member_pk = :member_pk
           LIMIT 1'
    );
    $find->execute([':member_pk' => $memberPk]);
    $existing = $find->fetch();

    $isInsert = !(is_array($existing) && !empty($existing['id']));

    // 변경된 필드만 추적(UPDATE일 때만)
    $changedFields = [];
    if (!$isInsert && is_array($existing)) {
        $newServiceTypes = json_or_null($service_types);
        $newAges = json_or_null($ages);
        $newStrengths = json_or_null($product_types);
        $newTones = json_or_null($tones);
        $newContentStyles = json_or_null($content_styles);
        $newExpressions = ($expression !== null) ? json_or_null([(int)$expression]) : null;

        $pairs = [
            'brand_name' => [$existing['brand_name'] ?? null, ($brand_name !== '' ? $brand_name : null)],
            'product_name' => [$existing['product_name'] ?? null, $product_name],
            'industry' => [$existing['industry'] ?? null, $industry],
            'inquiry_channels' => [$existing['inquiry_channels'] ?? null, $inquiry_channels],
            'inquiry_phone' => [$existing['inquiry_phone'] ?? null, $inquiry_phone],
            'service_types' => [$existing['service_types'] ?? null, $newServiceTypes],
            'address_zip' => [$existing['address_zip'] ?? null, ($address_zip !== '' ? $address_zip : null)],
            'address1' => [$existing['address1'] ?? null, ($address1 !== '' ? $address1 : null)],
            'address2' => [$existing['address2'] ?? null, ($address2 !== '' ? $address2 : null)],
            'goal' => [$existing['goal'] ?? null, $goal],
            'ages' => [$existing['ages'] ?? null, $newAges],
            'product_strengths' => [$existing['product_strengths'] ?? null, $newStrengths],
            'tones' => [$existing['tones'] ?? null, $newTones],
            'keep_style' => [$existing['keep_style'] ?? null, $keep_style],
            'style_url' => [$existing['style_url'] ?? null, ($style_url !== '' ? $style_url : null)],
            'content_styles' => [$existing['content_styles'] ?? null, $newContentStyles],
            'extra_strength' => [$existing['extra_strength'] ?? null, ($extra_strength !== '' ? $extra_strength : null)],
            'action_style' => [$existing['action_style'] ?? null, $action_style],
            'expressions' => [$existing['expressions'] ?? null, $newExpressions],
            'forbidden_phrases' => [$existing['forbidden_phrases'] ?? null, ($forbidden_phrases !== '' ? $forbidden_phrases : null)],
            'postLengthModeRaw' => [$existing['postLengthModeRaw'] ?? null, $postLengthModeRaw],
        ];

        foreach ($pairs as $k => $v) {
            $from = $v[0];
            $to = $v[1];
            if ((string)$from !== (string)$to) {
                $changedFields[$k] = $to;
            }
        }
    }

    if (!$isInsert) {
        $promptId = (int)$existing['id'];

        $upd = $pdo->prepare(
            'UPDATE caify_prompt SET
                brand_name = :brand_name,
                product_name = :product_name,
                industry = :industry,
                inquiry_channels = :inquiry_channels,
                inquiry_phone = :inquiry_phone,
                service_types = :service_types,
                address_zip = :address_zip,
                address1 = :address1,
                address2 = :address2,
                goal = :goal,
                ages = :ages,
                product_strengths = :product_strengths,
                tones = :tones,
                keep_style = :keep_style,
                style_url = :style_url,
                content_styles = :content_styles,
                extra_strength = :extra_strength,
                action_style = :action_style,
                expressions = :expressions,
                forbidden_phrases = :forbidden_phrases,
                postLengthModeRaw = :postLengthModeRaw
             WHERE id = :id AND member_pk = :member_pk'
        );

        $upd->execute([
            ':id' => $promptId,
            ':member_pk' => $memberPk,
            ':brand_name' => $brand_name !== '' ? $brand_name : null,
            ':product_name' => $product_name,
            ':industry' => $industry,
            ':inquiry_channels' => $inquiry_channels,
            ':inquiry_phone' => $inquiry_phone,
            ':service_types' => json_or_null($service_types),
            ':address_zip' => $address_zip !== '' ? $address_zip : null,
            ':address1' => $address1 !== '' ? $address1 : null,
            ':address2' => $address2 !== '' ? $address2 : null,
            ':goal' => $goal,
            ':ages' => json_or_null($ages),
            ':product_strengths' => json_or_null($product_types),
            ':tones' => json_or_null($tones),
            ':keep_style' => $keep_style,
            ':style_url' => $style_url !== '' ? $style_url : null,
            ':content_styles' => json_or_null($content_styles),
            ':extra_strength' => $extra_strength !== '' ? $extra_strength : null,
            ':action_style' => $action_style,
            ':expressions' => ($expression !== null) ? json_or_null([(int)$expression]) : null,
            ':forbidden_phrases' => $forbidden_phrases !== '' ? $forbidden_phrases : null,
            ':postLengthModeRaw' => $postLengthModeRaw,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO caify_prompt
                (member_pk, brand_name, product_name, industry, inquiry_channels, service_types,
                 address_zip, address1, address2, goal, ages, product_strengths, tones, keep_style, style_url,
                 content_styles, extra_strength, action_style, expressions, forbidden_phrases, postLengthModeRaw,inquiry_phone)
             VALUES
                (:member_pk, :brand_name, :product_name, :industry, :inquiry_channels, :service_types,
                 :address_zip, :address1, :address2, :goal, :ages, :product_strengths, :tones, :keep_style, :style_url,
                 :content_styles, :extra_strength, :action_style, :expressions, :forbidden_phrases, :postLengthModeRaw, :inquiry_phone)'
        );

        $ins->execute([
            ':member_pk' => $memberPk,
            ':brand_name' => $brand_name !== '' ? $brand_name : null,
            ':product_name' => $product_name,
            ':industry' => $industry,
            ':inquiry_channels' => $inquiry_channels,
            ':service_types' => json_or_null($service_types),
            ':address_zip' => $address_zip !== '' ? $address_zip : null,
            ':address1' => $address1 !== '' ? $address1 : null,
            ':address2' => $address2 !== '' ? $address2 : null,
            ':goal' => $goal,
            ':ages' => json_or_null($ages),
            ':product_strengths' => json_or_null($product_types),
            ':tones' => json_or_null($tones),
            ':keep_style' => $keep_style,
            ':style_url' => $style_url !== '' ? $style_url : null,
            ':content_styles' => json_or_null($content_styles),
            ':extra_strength' => $extra_strength !== '' ? $extra_strength : null,
            ':action_style' => $action_style,
            ':expressions' => ($expression !== null) ? json_or_null([(int)$expression]) : null,
            ':forbidden_phrases' => $forbidden_phrases !== '' ? $forbidden_phrases : null,
            ':postLengthModeRaw' => $postLengthModeRaw,
            ':inquiry_phone' => $inquiry_phone,
        ]);

        $promptId = (int)$pdo->lastInsertId();
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $baseUrl = n8n_base_url();

    $saveFile = function (array $file, string $type) use ($pdo, $promptId, $memberPk, $memberFolder, $userUploadAbs, $finfo, &$movedFiles, &$newFileUrls, &$newImageFilesForAnalysis, $baseUrl): void {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return;
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return;
        }

        $orig = (string)($file['name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $mime = (string)$finfo->file($tmp);

        $allowed = $type === 'image'
            ? ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
            : ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'];

        if (!in_array($mime, $allowed, true)) {
            return;
        }

        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = $type === 'image' ? 'bin' : 'bin';
        }

        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destAbs = rtrim($userUploadAbs, '\\/') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmp, $destAbs)) {
            return;
        }

        $storedRel = 'upload/' . $memberFolder . '/' . $filename;
        $movedFiles[] = $destAbs;
        if ($baseUrl !== '') {
            $newFileUrls[] = $baseUrl . '/' . $storedRel;
        } else {
            $newFileUrls[] = $storedRel;
        }

        $ins = $pdo->prepare(
            'INSERT INTO caify_prompt_file
                (prompt_id, member_pk, file_type, original_name, stored_path, mime_type, file_size)
             VALUES
                (:prompt_id, :member_pk, :file_type, :original_name, :stored_path, :mime_type, :file_size)'
        );
        $ins->execute([
            ':prompt_id' => $promptId,
            ':member_pk' => $memberPk,
            ':file_type' => $type,
            ':original_name' => $orig !== '' ? $orig : null,
            ':stored_path' => $storedRel,
            ':mime_type' => $mime !== '' ? $mime : null,
            ':file_size' => $size > 0 ? $size : null,
        ]);

        // 이미지일 경우: 커밋 후 Python으로 분석해서 별도 테이블에 저장하기 위해 큐에 넣기
        if ($type === 'image') {
            $fileId = (int)$pdo->lastInsertId();
            $newImageFilesForAnalysis[] = [
                'file_id' => $fileId,
                'prompt_id' => $promptId,
                'member_pk' => $memberPk,
                'abs_path' => $destAbs,
                'stored_path' => $storedRel,
                'original_name' => $orig,
            ];
        }
    };

    foreach (normalize_files('images') as $file) {
        $saveFile($file, 'image');
    }

    foreach (normalize_files('videos') as $file) {
        $saveFile($file, 'video');
    }

    $pdo->commit();

    // -----------------------
    // 이미지 메타데이터 분석(Python + GPT Vision) & DB 저장 (best-effort)
    // -----------------------
    try {
        if (is_array($newImageFilesForAnalysis) && count($newImageFilesForAnalysis) > 0) {
            $projectRoot = realpath(__DIR__ . '/..');
            $py = ($projectRoot !== false) ? (rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'image.py') : (__DIR__ . '/../api/image.py');

            foreach ($newImageFilesForAnalysis as $q) {
                if (!is_array($q)) continue;
                $absPath = (string)($q['abs_path'] ?? '');
                $fileId = (int)($q['file_id'] ?? 0);
                $pId = (int)($q['prompt_id'] ?? 0);
                $mPk = (int)($q['member_pk'] ?? 0);

                if ($absPath === '' || $fileId <= 0 || $pId <= 0 || $mPk <= 0) continue;

                // Python 스크립트 호출하여 GPT와 통신 (요청하신 bash + venv 스타일)
                $arg1 = base64_encode($absPath);
                $output = '';
                $cmd = '';

                if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') {
                    // 로컬(Windows) 개발 편의: bash 없을 수 있음
                    $cmd = 'python ' . escapeshellarg($py) . ' ' . escapeshellarg($arg1) . ' 2>&1';
                    $output = (string)shell_exec($cmd);
                } else {
                    // 서버(Linux) 기준: venv의 python을 직접 사용
                    $venvPython = defined('PYTHON_VENV_PATH') ? PYTHON_VENV_PATH : '';
                    
                    // 설정에 없으면 자동 탐색
                    if ($venvPython === '' || !is_file($venvPython)) {
                        $candidates = [
                            ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python3' : '',
                            '/usr/share/nginx/html/env/bin/python3',
                            ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python' : '',
                        ];
                        
                        foreach ($candidates as $path) {
                            if ($path !== '' && is_file($path)) {
                                $venvPython = $path;
                                break;
                            }
                        }
                    }
                    
                    if ($venvPython !== '' && is_file($venvPython)) {
                        // venv python 사용
                        $cmd = escapeshellarg($venvPython) . ' ' . escapeshellarg($py) . ' ' . escapeshellarg($arg1) . ' 2>&1';
                    } else {
                        // 못 찾으면 시스템 python3 (에러 날 수 있음)
                        $cmd = 'python3 ' . escapeshellarg($py) . ' ' . escapeshellarg($arg1) . ' 2>&1';
                    }
                    $output = (string)shell_exec($cmd);
                }

                $output = trim($output);
                $meta = json_decode($output, true);
                $status = 'success';
                $errorText = null;

                if (!is_array($meta)) {
                    $status = 'error';
                    $errorText = 'Invalid JSON from python';
                    // raw output을 저장해서 디버깅 가능하도록
                    $meta = [
                        'error' => 'Invalid JSON from python',
                        'raw_output' => $output !== '' ? substr($output, 0, 1000) : '(empty)',
                        'command' => substr($cmd, 0, 200),
                        'image_path' => substr($absPath, -100),
                    ];
                } elseif (!empty($meta['error'])) {
                    $status = 'error';
                    $errorText = is_string($meta['error']) ? $meta['error'] : 'error';
                }

                $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
                if ($metaJson === false) {
                    $metaJson = json_encode(['error' => 'json_encode_failed'], JSON_UNESCAPED_UNICODE);
                    $status = 'error';
                    $errorText = 'json_encode_failed';
                }

                // 테이블이 없거나 권한이 없을 수 있으므로 best-effort
                $stmt = $pdo->prepare(
                    'INSERT INTO caify_prompt_file_meta
                        (file_id, prompt_id, member_pk, model, status, meta_json, error_text)
                     VALUES
                        (:file_id, :prompt_id, :member_pk, :model, :status, :meta_json, :error_text)
                     ON DUPLICATE KEY UPDATE
                        model = VALUES(model),
                        status = VALUES(status),
                        meta_json = VALUES(meta_json),
                        error_text = VALUES(error_text),
                        updated_at = CURRENT_TIMESTAMP'
                );
                $stmt->execute([
                    ':file_id' => $fileId,
                    ':prompt_id' => $pId,
                    ':member_pk' => $mPk,
                    ':model' => 'gpt-4o-mini',
                    ':status' => $status,
                    ':meta_json' => $metaJson,
                    ':error_text' => $errorText,
                ]);
            }
        }
    } catch (Throwable $e) {
        // ignore (분석/저장 실패해도 업로드/저장은 유지)
    }

    // n8n 전송: INSERT면 전체, UPDATE면 변경/추가된 것만
    // - 전송 실패해도 저장 흐름은 유지 (n8n은 비동기 취급)
    try {
        // 숫자 코드 -> 텍스트 매핑(n8n 전송용)
        $map_service_types = [
            1 => '오프라인 매장',
            2 => '온라인 서비스',
            3 => '전국 서비스',
            4 => '프랜차이즈',
            5 => '기타',
        ];
        $map_goal = [
            1 => '매출을 늘리고 싶다',
            2 => '예약·방문을 늘리고 싶다.',
            3 => '문의·상담을 늘리고 싶다.',
            4 => '브랜드를 알리고 싶다.',
            5 => '신뢰를 확보하고 싶다.',
            6 => '기타',
        ];
        $map_ages = [
            1 => '10대',
            2 => '20대',
            3 => '30대',
            4 => '40대',
            5 => '50대',
        ];
        $map_strengths = [
            1 => '가격이 합리적이다.',
            2 => '결과·성과가 명확하다.',
            3 => '전문 인력이 직접 제공한다.',
            4 => '처리 속도가 빠르다.',
            5 => '경험·사례가 많다.',
            6 => '접근성이 좋다.',
            7 => '사후 관리가 잘 된다.',
            8 => '공식 인증·자격을 보유하고 있다.',
            9 => '기술력이 높다.',
            10 => '기타',
        ];
        $map_tones = [
            1 => '차분하게 설명한다.',
            2 => '친절하게 쉽게 설명한다.',
            3 => '단호하고 확신 있게 말한다.',
            4 => '전문가가 조언하는 느낌.',
        ];
        $map_keep_style = [
            1 => '유지한다.',
            2 => '유지하지 않는다.',
        ];
        $map_content_styles = [
            1 => '짧은 문장 위주',
            2 => '핵심 요약',
            3 => '질문으로 마무리',
            4 => '숫자·근거 강조',
        ];
        $map_action_style = [
            1 => '정보만 제공하고 판단은 맡긴다.',
            2 => '관심이 생기도록 자연스럽게 유도한다.',
            3 => '지금 바로 행동하도록 안내한다.',
        ];
        $map_expression = [
            1 => '과장된 표현',
            2 => '가격·할인 언급',
            3 => '타사 비교·비방 표현',
            4 => '기타',
        ];

        $join = function (array $vals, array $map): string {
            $out = [];
            foreach ($vals as $v) {
                $vv = (int)$v;
                if (isset($map[$vv])) $out[] = $map[$vv];
            }
            return implode(' / ', $out);
        };

        $service_types_text = $join($service_types, $map_service_types);
        $ages_text = $join($ages, $map_ages);
        $product_strengths_text = $join($product_types, $map_strengths);
        $tones_text = $join($tones, $map_tones);
        $content_styles_text = $join($content_styles, $map_content_styles);
        $goal_text = $goal !== null && isset($map_goal[(int)$goal]) ? $map_goal[(int)$goal] : '';
        $keep_style_text = $keep_style !== null && isset($map_keep_style[(int)$keep_style]) ? $map_keep_style[(int)$keep_style] : '';
        $action_style_text = $action_style !== null && isset($map_action_style[(int)$action_style]) ? $map_action_style[(int)$action_style] : '';
        $expression_text = $expression !== null && isset($map_expression[(int)$expression]) ? $map_expression[(int)$expression] : '';

        if ($isInsert) {
            n8n_send([
                'event' => 'prompt_insert',
                'operation' => 'create',
                'member_pk' => $memberPk,
                'member_id' => $memberId,
                'prompt_id' => $promptId,
                'data' => [
                    'brand_name' => $brand_name !== '' ? $brand_name : null,
                    'product_name' => $product_name,
                    'industry' => $industry,
                    'inquiry_channels' => $inquiry_channels,
                    'service_types' => $service_types_text,
                    'address_zip' => $address_zip !== '' ? $address_zip : null,
                    'address1' => $address1 !== '' ? $address1 : null,
                    'address2' => $address2 !== '' ? $address2 : null,
                    'goal' => $goal_text,
                    'ages' => $ages_text,
                    'product_strengths' => $product_strengths_text,
                    'tones' => $tones_text,
                    'content_styles' => $content_styles_text,
                    'keep_style' => $keep_style_text,
                    'style_url' => $style_url !== '' ? $style_url : null,
                    'extra_strength' => $extra_strength !== '' ? $extra_strength : null,
                    'action_style' => $action_style_text !== '' ? $action_style_text : null,
                    'expression' => $expression_text !== '' ? $expression_text : null,
                    'forbidden_phrases' => $forbidden_phrases !== '' ? $forbidden_phrases : null,
                ],
                'new_file_urls' => $newFileUrls, // 최초는 "이번에 업로드된 전체"
            ]);
        } else {
            if (count($changedFields) > 0 || count($newFileUrls) > 0) {
                // changed_fields도 "텍스트"로 변환해서 보냄
                $changedText = [];
                foreach ($changedFields as $k => $v) {
                    if (in_array($k, ['service_types', 'ages', 'product_strengths', 'tones', 'content_styles'], true)) {
                        $arr = [];
                        if (is_string($v)) {
                            $decoded = json_decode($v, true);
                            if (is_array($decoded)) $arr = $decoded;
                        } elseif (is_array($v)) {
                            $arr = $v;
                        }

                        if ($k === 'service_types') $changedText[$k] = $join($arr, $map_service_types);
                        if ($k === 'ages') $changedText[$k] = $join($arr, $map_ages);
                        if ($k === 'product_strengths') $changedText[$k] = $join($arr, $map_strengths);
                        if ($k === 'tones') $changedText[$k] = $join($arr, $map_tones);
                        if ($k === 'content_styles') $changedText[$k] = $join($arr, $map_content_styles);
                        continue;
                    }

                    if ($k === 'action_style') {
                        $vv = (int)$v;
                        $changedText[$k] = isset($map_action_style[$vv]) ? $map_action_style[$vv] : '';
                        continue;
                    }

                    if ($k === 'expressions') {
                        $arr = [];
                        if (is_string($v)) {
                            $decoded = json_decode($v, true);
                            if (is_array($decoded)) $arr = $decoded;
                        } elseif (is_array($v)) {
                            $arr = $v;
                        }
                        $first = (int)($arr[0] ?? 0);
                        $changedText[$k] = ($first > 0 && isset($map_expression[$first])) ? $map_expression[$first] : '';
                        continue;
                    }

                    if ($k === 'goal') {
                        $vv = (int)$v;
                        $changedText[$k] = isset($map_goal[$vv]) ? $map_goal[$vv] : '';
                        continue;
                    }

                    if ($k === 'keep_style') {
                        $vv = (int)$v;
                        $changedText[$k] = isset($map_keep_style[$vv]) ? $map_keep_style[$vv] : '';
                        continue;
                    }

                    $changedText[$k] = $v;
                }

                n8n_send([
                    'event' => 'prompt_update',
                    'operation' => 'update',
                    'member_pk' => $memberPk,
                    'member_id' => $memberId,
                    'prompt_id' => $promptId,
                    'changed_fields' => $changedText, // 수정된 것만(텍스트)
                    'new_file_urls' => $newFileUrls,    // 새로 추가된 파일만
                ]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    swal_and_redirect('저장 완료!', '정상적으로 저장되었습니다. (Prompt ID: ' . (int)$promptId . ')', 'success', 'prompt.php');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 이미 옮긴 파일은 삭제 시도
    foreach ($movedFiles as $p) {
        @unlink($p);
    }

    // 개발 디버깅용: 실제 에러 메시지 출력 (운영에서는 로그로만 남기는 것 권장)
    $errMsg = 'DB/권한을 확인해주세요.';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $errMsg .= ' [' . $e->getMessage() . ']';
    }
    swal_and_redirect('저장 실패', $errMsg, 'error', 'prompt.php');
}

