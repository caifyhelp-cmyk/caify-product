-- ============================================================
-- Caify 앱 API 실서버 전환용 DB 마이그레이션
-- 실행 대상: ai_database (183.111.227.123)
--
-- 주의: caify_case 테이블은 기존 PHP가 이미 사용 중 — 재생성하지 않음
--       누락 컬럼만 ALTER TABLE로 추가
-- ============================================================

-- ── 1. caify_member 컬럼 추가 ─────────────────────────────────
-- (app_login.php에서 이미 api_token, tier 추가됐을 수 있으므로 IF NOT EXISTS 사용)
ALTER TABLE caify_member
  ADD COLUMN IF NOT EXISTS api_token        VARCHAR(64)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tier             TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS blog_id          VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS n8n_workflow_ids JSON         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS schedule_days    JSON         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS schedule_hour    TINYINT      DEFAULT NULL;

-- api_token 유니크 인덱스 (중복 실행 안전)
CREATE UNIQUE INDEX IF NOT EXISTS idx_api_token ON caify_member (api_token);

-- ── 2. ai_posts 컬럼 추가 ─────────────────────────────────────
ALTER TABLE ai_posts
  ADD COLUMN IF NOT EXISTS tags JSON DEFAULT NULL;

-- ── 3. caify_messages 테이블 (앱 채팅용 — 신규) ───────────────
CREATE TABLE IF NOT EXISTS caify_messages (
  id         INT UNSIGNED       AUTO_INCREMENT PRIMARY KEY,
  member_pk  INT UNSIGNED       NOT NULL,
  type       VARCHAR(50)        NOT NULL DEFAULT 'user_text',
  is_system  TINYINT(1)         NOT NULL DEFAULT 0,
  text       TEXT               NOT NULL,
  post_id    INT UNSIGNED       NULL,
  post_title VARCHAR(500)       NULL,
  post_html  LONGTEXT           NULL,
  meta       JSON               NULL,
  actions    JSON               NULL,
  is_read    TINYINT(1)         NOT NULL DEFAULT 0,
  created_at DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_member_pk  (member_pk),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. caify_rank 테이블 (순위 이력 — 신규) ──────────────────
CREATE TABLE IF NOT EXISTS caify_rank (
  id         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  member_pk  INT UNSIGNED  NOT NULL,
  keyword    VARCHAR(200)  NOT NULL,
  rank       INT           NULL,
  found      TINYINT(1)    NOT NULL DEFAULT 0,
  checked_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_member_keyword (member_pk, keyword),
  INDEX idx_checked_at     (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. caify_case 기존 테이블에 누락 컬럼 추가 ──────────────────
-- status: 기존 웹 PHP가 이미 사용 중 (app_case.php도 status=1 조건 사용)
-- post_id: n8n 완료 후 ai_posts 연결 (index.php가 업데이트)
ALTER TABLE caify_case
  ADD COLUMN IF NOT EXISTS status  TINYINT(1)   NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS post_id INT UNSIGNED DEFAULT NULL;

-- ── 6. caify_case_file 테이블 확인용 (이미 있으면 무시) ──────────
-- 기존 PHP prompt/case_submit.php가 이미 이 테이블 사용 중이므로 존재할 가능성 높음
CREATE TABLE IF NOT EXISTS caify_case_file (
  id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  case_id       INT UNSIGNED  NOT NULL,
  member_pk     INT UNSIGNED  NOT NULL,
  original_name VARCHAR(500)  NULL,
  stored_path   VARCHAR(1000) NOT NULL,
  mime_type     VARCHAR(100)  NULL,
  file_size     INT UNSIGNED  NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_case_id   (case_id),
  INDEX idx_member_pk (member_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
