<?php
$host = '183.111.227.123';
$user = 'ais';
$password = 'whtkfkd0519!';
$dbname = 'ai_database';

$conn = new mysqli($host, $user, $password, $dbname);

// 연결 오류 확인
if ($conn->connect_error) {
    die("연결 실패: " . $conn->connect_error);
}


echo "111";