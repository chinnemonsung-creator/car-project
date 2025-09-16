-- Migration: create table verify_sessions
-- Run this on your DB (MySQL/MariaDB). Requires InnoDB + utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- สร้างตารางถ้ายังไม่มี
CREATE TABLE IF NOT EXISTS `verify_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `sid` VARCHAR(64) NOT NULL COMMENT 'สำเนา session_id จาก orders เพื่ออ้างอิงง่าย',
  `share_token` VARCHAR(128) NOT NULL COMMENT 'โทเคนที่แชร์ให้ลูกค้า (ต้องเดายาก)',
  `status` ENUM('waiting','used','expired','cancelled') NOT NULL DEFAULT 'waiting',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_share_token` (`share_token`),

  KEY `idx_order_id` (`order_id`),
  KEY `idx_status_expires` (`status`, `expires_at`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_sid` (`sid`),

  CONSTRAINT `fk_verify_sessions_order`
    FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`)
    ON DELETE CASCADE
    ON UPDATE RESTRICT
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
ROW_FORMAT=DYNAMIC;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- (ออปชัน) สคริปต์ Rollback:
-- DROP TABLE IF EXISTS `verify_sessions`;
-- ------------------------------------------------------------
