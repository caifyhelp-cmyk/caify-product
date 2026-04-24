
<?php
header("Content-Type: application/json");

// Zoom Webhook Secret (App 설정에 있는 Secret Token)
$secretToken = 'scS_q59hRPicxh1ekXVQkA';

// Zoom에서 보낸 raw body 읽기
$rawBody = file_get_contents('php://input');
$request = json_decode($rawBody, true);

// URL Validation 요청인지 확인
if (
    isset($request['event']) &&
    $request['event'] === 'endpoint.url_validation'
) {

    $plainToken = $request['payload']['plainToken'];

    // HMAC SHA256 해시 생성
    $encryptedToken = hash_hmac('sha256', $plainToken, $secretToken);

    http_response_code(200);

    echo json_encode([
        'plainToken'     => $plainToken,
        'encryptedToken' => $encryptedToken
    ]);

    exit;
}

// 여기 아래는 실제 webhook 이벤트 처리 영역
http_response_code(200);
echo json_encode(['status' => 'ok']);
