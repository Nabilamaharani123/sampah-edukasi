CREATE DATABASE IF NOT EXISTS sampah_app
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sampah_app;

CREATE TABLE IF NOT EXISTS access_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip VARCHAR(45) NULL,
  method VARCHAR(12) NULL,
  path VARCHAR(1024) NULL,
  query_string VARCHAR(2048) NULL,
  user_agent VARCHAR(1024) NULL,
  referer VARCHAR(1024) NULL,
  request_body MEDIUMTEXT NULL,
  response_status SMALLINT UNSIGNED NULL,
  response_time_ms INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_ip (ip),
  KEY idx_path (path(255))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  konteks TEXT NULL,
  pertanyaan TEXT NOT NULL,
  jawaban MEDIUMTEXT NULL,
  prediction_model VARCHAR(128) NULL,
  prediction_score DOUBLE NULL,
  matched_question TEXT NULL,
  access_log_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  FULLTEXT KEY ft_pertanyaan (pertanyaan),
  CONSTRAINT fk_chat_access
    FOREIGN KEY (access_log_id) REFERENCES access_logs(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;
