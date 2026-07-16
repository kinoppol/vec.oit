-- 005 · Create indicator_subsections table (FK → indicator_sections)
CREATE TABLE IF NOT EXISTS `indicator_subsections` (
  `id`         INT UNSIGNED AUTO_INCREMENT,
  `section_id` INT UNSIGNED NOT NULL,
  `code`       VARCHAR(10)  NOT NULL,
  `title`      VARCHAR(300) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_sub_sec` FOREIGN KEY (`section_id`) REFERENCES `indicator_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
