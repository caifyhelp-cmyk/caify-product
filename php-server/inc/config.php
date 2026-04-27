<?php
/**
 * DB 접속 정보 설정
 * - 로컬/서버 환경에 맞게 값만 바꿔서 사용하세요.
 */

declare(strict_types=1);

if (!function_exists('load_dotenv_file')) {
    function load_dotenv_file(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);
            if ($key === '') {
                continue;
            }

            $len = strlen($val);
            if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }

            if (getenv($key) === false || getenv($key) === '') {
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }
}

if (!function_exists('load_caify_env')) {
    function load_caify_env(): void
    {
        $envPath = dirname(__DIR__) . '/api/.env';
        load_dotenv_file($envPath);
    }
}

/**
 * 환경변수를 읽고 없으면 기본값을 반환합니다.
 */
function env_or_default(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }

    return $default;
}

load_caify_env();

// 예: 127.0.0.1 또는 localhost
define('DB_HOST', '183.111.227.123');

// 예: caify
define('DB_NAME', 'ai_database');

// 예: root
define('DB_USER', 'ais');

// 예: 비밀번호
define('DB_PASS', 'whtkfkd0519!');

// 예: 3306
define('DB_PORT', '3306');

// 예: utf8mb4
define('DB_CHARSET', 'utf8mb4');

// n8n Webhook URL (비워두면 전송하지 않음)
define('N8N_WEBHOOK_URL', 'https://n8n.caify.ai/webhook/4c60f500-0955-449d-9ebc-21d617d9adcb');

// n8n REST API 설정 (워크플로우 관리용)
define('N8N_URL', env_or_default('N8N_URL', 'https://n8n.caify.ai'));
define('N8N_API_KEY', env_or_default('N8N_API_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMmNlNTVlNS01YTUwLTQyMjgtOWM5Yi1hNWM0MzBmNzM4NDEiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwiaWF0IjoxNzc2OTgyMzIzfQ.zeIagMQxIuDN-OwQHhKuATLM0CDb-dNRCLuB5zCFzGI'));

// n8n 워크플로우 템플릿 ID
define('N8N_TEMPLATE_INFO',  env_or_default('N8N_TEMPLATE_INFO',  'DvvwnamBcqnqVgCz'));
define('N8N_TEMPLATE_MIXED', env_or_default('N8N_TEMPLATE_MIXED', 'zUhFnjJvA7Fuz6UG'));
define('N8N_TEMPLATE_CASE',  env_or_default('N8N_TEMPLATE_CASE',  'vUlrwTSj0b3TcIKg'));

// Python venv 경로 (이미지 분석용)
// 서버에서 `which python3` (venv 활성화 후) 결과를 여기 넣으세요
define('PYTHON_VENV_PATH', '/usr/share/nginx/html/api/env/bin/python3');

// UIXLAB Bridge 설정 (게시물 게시 + 계정정보 전달)
define('UIXLAB_API_BASE', env_or_default('UIXLAB_API_BASE', 'https://uixlab.co.kr'));
define('UIXLAB_DRAFT_CREATE_PATH', env_or_default('UIXLAB_DRAFT_CREATE_PATH', '/design/theme_01/admin/gallery_draft_create.php'));
define('UIXLAB_DRAFT_WRITE_PATH', env_or_default('UIXLAB_DRAFT_WRITE_PATH', '/design/theme_01/admin/gallery_write.php'));
define('UIXLAB_ACCOUNT_DRAFT_CREATE_PATH', env_or_default('UIXLAB_ACCOUNT_DRAFT_CREATE_PATH', '/account_draft_create.php'));
define('UIXLAB_ACCOUNT_REGISTER_PATH', env_or_default('UIXLAB_ACCOUNT_REGISTER_PATH', '/_regist/register.php'));
define('UIXLAB_SOURCE_DOMAIN', env_or_default('UIXLAB_SOURCE_DOMAIN', 'caify.ai'));
define('UIXLAB_BRIDGE_API_KEY', env_or_default('UIXLAB_BRIDGE_API_KEY', ''));
define('UIXLAB_ALLOW_HTTP', env_or_default('UIXLAB_ALLOW_HTTP', '0'));
