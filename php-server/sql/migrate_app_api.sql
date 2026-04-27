-- ============================================================
-- Caify 앱 API 실서버 전환용 DB 마이그레이션
-- 실행 대상: ai_database (183.111.227.123)
-- ============================================================

-- ── 1. caify_member 컬럼 추가 ─────────────────────────────────
ALTER TABLE caify_member
  ADD COLUMN IF NOT EXISTS api_token        VARCHAR(64)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tier             TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS blog_id          VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS n8n_workflow_ids JSON         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS schedule_days    JSON         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS schedule_hour    TINYINT      DEFAULT NULL;

ALTER TABLE caify_member
  ADD UNIQUE INDEX IF NOT EXISTS idx_api_token (api_token);

-- ── 2. ai_posts 컬럼 추가 ─────────────────────────────────────
ALTER TABLE ai_posts
  ADD COLUMN IF NOT EXISTS tags JSON DEFAULT NULL;

-- ── 3. caify_messages 테이블 ──────────────────────────────────
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

-- ── 4. caify_case 테이블 ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS caify_case (
  id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  member_pk   INT UNSIGNED  NOT NULL,
  case_title  VARCHAR(500)  NOT NULL,
  raw_content TEXT          NOT NULL,
  ai_status   ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  ai_title    VARCHAR(500)  NULL,
  ai_summary  TEXT          NULL,
  files       JSON          NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_member_pk (member_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. caify_rank 테이블 ──────────────────────────────────────
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
