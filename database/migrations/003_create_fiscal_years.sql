-- 003 · Create fiscal_years table
CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id`         INT UNSIGNED AUTO_INCREMENT,
  `year_code`  VARCHAR(10)  NOT NULL,
  `label`      VARCHAR(100) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_year_code` (`year_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
