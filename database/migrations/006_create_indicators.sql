-- 006 · Create indicators table (FK → indicator_subsections)
CREATE TABLE IF NOT EXISTS `indicators` (
  `id`            INT UNSIGNED AUTO_INCREMENT,
  `subsection_id` INT UNSIGNED NOT NULL,
  `code`          VARCHAR(10)  NOT NULL,
  `title`         VARCHAR(300) NOT NULL,
  `criteria`      TEXT         DEFAULT NULL,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ind_sub` FOREIGN KEY (`subsection_id`) REFERENCES `indicator_subsections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
