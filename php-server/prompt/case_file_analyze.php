<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

ini_set('display_errors', '0');
ini_set('max_execution_time', '180');
set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');

function fa_json_exit(array $payload): void
{
    if (ob_get_level() > 0) ob_clean();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ob_start();
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fa_json_exit(['error' => 'AI 처리 중 서버 오류가 발생했습니다.']);
    }
});

require_login();
$member   = current_member();
$memberPk = (int)($member['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fa_json_exit(['error' => '잘못된 요청입니다.']);
}

$caseType = trim((string)($_POST['case_type'] ?? ''));
$validTypes = ['problem_solve', 'process_work', 'consulting_qa', 'review_experience'];
if (!in_array($caseType, $validTypes, true)) {
    fa_json_exit(['error' => '올바른 사례 유형이 필요합니다.']);
}

if (empty($_FILES['ref_file']) || ($_FILES['ref_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fa_json_exit(['error' => '파일이 첨부되지 않았습니다.']);
}

$file    = $_FILES['ref_file'];
$tmpPath = (string)($file['tmp_name'] ?? '');
$origName = (string)($file['name'] ?? '');
$fileSize = (int)($file['size'] ?? 0);

if ($fileSize > 5 * 1024 * 1024) {
    fa_json_exit(['error' => '파일 크기는 5MB 이하만 지원합니다.']);
}

$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowedExts = ['txt', 'md', 'csv', 'log', 'json', 'html', 'htm', 'xml', 'rtf'];

$fileContent = '';

if (in_array($ext, $allowedExts, true)) {
    $fileContent = @file_get_contents($tmpPath);
    if ($fileContent === false) {
        fa_json_exit(['error' => '파일을 읽을 수 없습니다.']);
    }
    $fileContent = mb_convert_encoding($fileContent, 'UTF-8', 'auto');
    $fileContent = strip_tags($fileContent);
} elseif ($ext === 'pdf') {
    $projectRoot = realpath(__DIR__ . '/..');
    $venvPython = '';
    $candidates = [
        ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python3' : '',
        '/usr/share/nginx/html/env/bin/python3',
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path)) {
            $venvPython = $path;
            break;
        }
    }
    if ($venvPython === '') $venvPython = 'python3';

    $pdfScript = 'import sys; '
        . 'from PyPDF2 import PdfReader; '
        . 'r = PdfReader(sys.argv[1]); '
        . 'print("\\n".join(p.extract_text() or "" for p in r.pages))';
    $cmd = escapeshellarg($venvPython) . ' -c ' . escapeshellarg($pdfScript) . ' ' . escapeshellarg($tmpPath) . ' 2>&1';
    $fileContent = trim((string)shell_exec($cmd));

    if ($fileContent === '' || mb_strlen(preg_replace('/\s+/', '', $fileContent)) < 30) {
        $ocrScript = __DIR__ . '/pdf_vision_ocr.py';
        if (is_file($ocrScript)) {
            $ocrCmd = escapeshellarg($venvPython) . ' ' . escapeshellarg($ocrScript) . ' ' . escapeshellarg($tmpPath) . ' 3 2>&1';
            $ocrOutput = trim((string)shell_exec($ocrCmd));
            if ($ocrOutput !== '') {
                $fileContent = $ocrOutput;
            }
        }
    }

    if ($fileContent === '') {
        fa_json_exit(['error' => 'PDF에서 텍스트를 추출할 수 없습니다. 텍스트 기반 PDF이거나 스캔 품질이 충분해야 합니다.']);
    }
} elseif (in_array($ext, ['doc', 'docx'], true)) {
    $projectRoot = realpath(__DIR__ . '/..');
    $venvPython = '';
    $candidates = [
        ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python3' : '',
        '/usr/share/nginx/html/env/bin/python3',
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path)) {
            $venvPython = $path;
            break;
        }
    }
    if ($venvPython === '') $venvPython = 'python3';

    $docScript = 'import sys; '
        . 'from docx import Document; '
        . 'd = Document(sys.argv[1]); '
        . 'print("\\n".join(p.text for p in d.paragraphs))';
    $cmd = escapeshellarg($venvPython) . ' -c ' . escapeshellarg($docScript) . ' ' . escapeshellarg($tmpPath) . ' 2>&1';
    $fileContent = trim((string)shell_exec($cmd));
    if ($fileContent === '') {
        fa_json_exit(['error' => 'Word 파일 텍스트 추출에 실패했습니다.']);
    }
} elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'], true)) {
    $projectRoot = realpath(__DIR__ . '/..');
    $venvPython = '';
    $candidates = [
        ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python3' : '',
        '/usr/share/nginx/html/env/bin/python3',
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path)) {
            $venvPython = $path;
            break;
        }
    }
    if ($venvPython === '') $venvPython = 'python3';

    $imgOcrScript = 'import os, sys, base64; '
        . 'from openai import OpenAI; '
        . 'from dotenv import load_dotenv; '
        . 'load_dotenv(os.path.join(os.path.dirname(os.path.abspath("' . addslashes(__DIR__) . '")), "api", ".env")); '
        . 'c = OpenAI(api_key=os.getenv("OPENAI_API_KEY","")); '
        . 'b = base64.b64encode(open(sys.argv[1],"rb").read()).decode(); '
        . 'r = c.chat.completions.create(model="gpt-4o-mini", messages=[{"role":"user","content":[{"type":"text","text":"이 이미지에 보이는 모든 텍스트를 빠짐없이 그대로 읽어서 출력해주세요. 구조를 유지하고 설명은 추가하지 마세요."},{"type":"image_url","image_url":{"url":"data:image/png;base64,"+b,"detail":"high"}}]}], temperature=0.1, max_tokens=4000); '
        . 'print(r.choices[0].message.content or "")';
    $cmd = escapeshellarg($venvPython) . ' -c ' . escapeshellarg($imgOcrScript) . ' ' . escapeshellarg($tmpPath) . ' 2>&1';
    $fileContent = trim((string)shell_exec($cmd));
    if ($fileContent === '') {
        fa_json_exit(['error' => '이미지에서 텍스트를 읽을 수 없습니다.']);
    }
} else {
    fa_json_exit(['error' => '지원하지 않는 파일 형식입니다. (txt, pdf, docx, csv, md, jpg, png 등을 사용해주세요)']);
}

$fileContent = trim($fileContent);
if (mb_strlen($fileContent) < 20) {
    fa_json_exit(['error' => '파일에서 충분한 텍스트를 추출할 수 없습니다.']);
}

$brandName = '';
$industry  = '';
if ($memberPk > 0) {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT brand_name, industry FROM caify_prompt WHERE member_pk = :member_pk LIMIT 1');
        $stmt->execute([':member_pk' => $memberPk]);
        $pRow = $stmt->fetch();
        if (is_array($pRow)) {
            $brandName = trim((string)($pRow['brand_name'] ?? ''));
            $industry  = trim((string)($pRow['industry'] ?? ''));
        }
    } catch (\Throwable $e) { }
}

$data = [
    'case_type'    => $caseType,
    'file_content' => $fileContent,
    'brand_name'   => $brandName,
    'industry'     => $industry,
];

$projectRoot = realpath(__DIR__ . '/..');
$pyScript    = __DIR__ . '/case_file_analyze.py';

$venvPython = '';
$candidates = [
    ($projectRoot !== false) ? rtrim($projectRoot, '/') . '/env/bin/python3' : '',
    '/usr/share/nginx/html/env/bin/python3',
];
foreach ($candidates as $path) {
    if ($path !== '' && is_file($path)) {
        $venvPython = $path;
        break;
    }
}
if ($venvPython === '') $venvPython = 'python3';

$cmd = escapeshellarg($venvPython) . ' ' . escapeshellarg($pyScript) . ' '
     . escapeshellarg(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE))) . ' 2>&1';

$output = trim((string)shell_exec($cmd));
if ($output === '') {
    fa_json_exit(['error' => 'AI 분석 스크립트 실행에 실패했습니다.']);
}

$response = json_decode($output, true);
if (!is_array($response)) {
    fa_json_exit(['error' => 'AI 응답 파싱 실패', 'raw' => mb_substr($output, 0, 500)]);
}

if (isset($response['response'])) {
    fa_json_exit(['success' => true, 'data' => $response['response']]);
} elseif (isset($response['error'])) {
    fa_json_exit(['error' => $response['error']]);
} else {
    fa_json_exit(['error' => 'AI 응답 처리 오류', 'raw' => mb_substr($output, 0, 500)]);
}
