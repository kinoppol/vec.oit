-- 004 · Create indicator_sections table (FK → fiscal_years)
CREATE TABLE IF NOT EXISTS `indicator_sections` (
  `id`             INT UNSIGNED AUTO_INCREMENT,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `code`           VARCHAR(10)  NOT NULL,
  `title`          VARCHAR(300) NOT NULL,
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_sec_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
