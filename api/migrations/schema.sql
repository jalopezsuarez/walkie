-- =====================================================================
--  Walkie — Database schema
--  MySQL 5.7+ / MariaDB 10.3+  (utf8mb4)
--
--  Every table stores as little identifiable data as possible.
--  Message contents are encrypted at rest (AES-256-GCM) and are
--  hard-deleted the moment they are read or when they expire.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
--  Users
--  A user is nothing more than an email + a display name.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255)    NOT NULL,
    display_name  VARCHAR(60)     NOT NULL DEFAULT 'Walkie',
    created_at    DATETIME        NOT NULL,
    updated_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Login codes (6-digit, 5 minute TTL)
--  Only the hash of the code is stored, never the code itself.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email       VARCHAR(255)    NOT NULL,
    code_hash   CHAR(64)        NOT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME        NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_codes_email (email),
    KEY idx_login_codes_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Sessions (opaque bearer tokens, only the hash is stored)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    ip          VARBINARY(16)   NULL,
    user_agent  VARCHAR(255)    NULL,
    created_at  DATETIME        NOT NULL,
    last_seen   DATETIME        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_token (token_hash),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Pairing tokens (the payload behind a QR code, 5 minute TTL)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pairing_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    used_at     DATETIME        NULL,
    expires_at  DATETIME        NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pairing_token (token_hash),
    KEY idx_pairing_user (user_id),
    KEY idx_pairing_expires (expires_at),
    CONSTRAINT fk_pairing_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Links (a confirmed pairing between two users)
--  user_low < user_high guarantees a single row per pair.
--  secret is a per-conversation random key used to derive the
--  message-encryption key (defence in depth on top of APP_KEY).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS links (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_low    BIGINT UNSIGNED NOT NULL,
    user_high   BIGINT UNSIGNED NOT NULL,
    secret      VARBINARY(32)   NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_links_pair (user_low, user_high),
    KEY idx_links_low (user_low),
    KEY idx_links_high (user_high),
    CONSTRAINT fk_links_low  FOREIGN KEY (user_low)  REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_links_high FOREIGN KEY (user_high) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Messages
--  body_cipher holds iv || tag || ciphertext (raw bytes).
--  type: 't' = text, 'a' = audio.
--  read_at marks when the recipient read it (triggers deletion).
--  expires_at is the hard cap: +1h for audio, +24h for text.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    link_id      BIGINT UNSIGNED NOT NULL,
    sender_id    BIGINT UNSIGNED NOT NULL,
    type         CHAR(1)         NOT NULL,
    body_cipher  LONGBLOB        NOT NULL,
    mime         VARCHAR(40)     NULL,
    duration_ms  INT UNSIGNED    NULL,
    delivered_at DATETIME        NULL,  -- reached the recipient's client (single check)
    read_at      DATETIME        NULL,  -- audio played / text seen on screen (double check)
    created_at   DATETIME        NOT NULL,
    expires_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_messages_link (link_id, id),
    KEY idx_messages_expires (expires_at),
    KEY idx_messages_delivered (delivered_at),
    KEY idx_messages_read (read_at),
    CONSTRAINT fk_messages_link   FOREIGN KEY (link_id)   REFERENCES links (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Rate limiting / abuse control (fixed-window counters)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket        VARCHAR(191)    NOT NULL,
    window_start  DATETIME        NOT NULL,
    counter       INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket),
    KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  OAuth 2.0 refresh tokens (RFC 6749 §1.5) — only SHA-256 hashes stored
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64)        NOT NULL,
    created_at    DATETIME        NOT NULL,
    expires_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_refresh_hash (token_hash),
    KEY idx_oauth_refresh_user (user_id),
    KEY idx_oauth_refresh_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
