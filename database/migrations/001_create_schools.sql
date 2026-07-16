-- 001 · Create schools table
CREATE TABLE IF NOT EXISTS `schools` (
  `id`          INT UNSIGNED AUTO_INCREMENT,
  `name`        VARCHAR(200)  NOT NULL,
  `code`        VARCHAR(20)   DEFAULT NULL,
  `province`    VARCHAR(100)  NOT NULL DEFAULT '',
  `slug`        VARCHAR(120)  NOT NULL,
  `website`     VARCHAR(500)  DEFAULT NULL,
  `emblem_path` VARCHAR(300)  DEFAULT NULL,
  `status`      ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
