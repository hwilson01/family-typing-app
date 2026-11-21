-- Canonical MySQL schema (reference). setup.php builds these same tables via
-- api/lib/migrate.php; you only need this file for manual/CLI table creation.
-- Phase 1 uses profiles, lessons, attempts, key_stats, settings.
-- content_items, topics and rewards are created now for Phase 2 but stay empty.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(64) PRIMARY KEY,
  svalue TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profiles (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(60)  NOT NULL,
  avatar          VARCHAR(40)  NOT NULL DEFAULT 'cat',
  role            VARCHAR(10)  NOT NULL DEFAULT 'child',   -- 'child' | 'parent'
  pin_hash        TEXT         NOT NULL,
  current_lesson  INT          NOT NULL DEFAULT 1,
  reading_level   INT          NOT NULL DEFAULT 4,
  settings        TEXT,
  failed_attempts INT          NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,
  created_at      DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lessons (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  lesson_order   INT           NOT NULL,
  stage          VARCHAR(16)   NOT NULL,
  title          VARCHAR(120)  NOT NULL,
  new_keys       TEXT,                         -- JSON array
  pass_threshold DECIMAL(5,4)  NOT NULL DEFAULT 0.9,
  drill_data     TEXT          NOT NULL,       -- JSON {intro_keys, steps[]}
  created_at     DATETIME      NOT NULL,
  UNIQUE KEY idx_lessons_order (lesson_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attempts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  profile_id      INT          NOT NULL,
  lesson_id       INT          NULL,
  content_item_id INT          NULL,           -- Phase 2
  source          VARCHAR(16)  NOT NULL DEFAULT 'lesson', -- lesson|placement|drill|content
  duration_ms     INT          NOT NULL DEFAULT 0,
  wpm             INT          NOT NULL DEFAULT 0,
  accuracy        DECIMAL(5,4) NOT NULL DEFAULT 0,
  stars           INT          NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  KEY idx_attempts_profile (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS key_stats (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  profile_id    INT          NOT NULL,
  key_code      VARCHAR(16)  NOT NULL,         -- normalized physical key: 'a','1',';'
  hits          INT          NOT NULL DEFAULT 0,
  misses        INT          NOT NULL DEFAULT 0,
  total_time_ms INT          NOT NULL DEFAULT 0,
  UNIQUE KEY idx_keystats (profile_id, key_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Phase 2 tables (created now, unused in Phase 1) ----
CREATE TABLE IF NOT EXISTS content_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  profile_id    INT          NULL,
  topic         VARCHAR(200),
  reading_level INT          NOT NULL DEFAULT 4,
  text          TEXT         NOT NULL,
  allowed_keys  TEXT,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending|approved|rejected
  created_at    DATETIME     NOT NULL,
  reviewed_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topics (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  profile_id INT          NOT NULL,
  topic      VARCHAR(200) NOT NULL,
  created_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rewards (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  profile_id INT          NOT NULL,
  type       VARCHAR(32)  NOT NULL,
  value      INT          NOT NULL DEFAULT 0,
  meta       TEXT,
  created_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
