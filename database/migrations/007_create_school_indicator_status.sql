-- 007 · Create school_indicator_status table (FK → schools, indicators)
CREATE TABLE IF NOT EXISTS `school_indicator_status` (
  `id`           INT UNSIGNED AUTO_INCREMENT,
  `school_id`    INT UNSIGNED NOT NULL,
  `indicator_id` INT UNSIGNED NOT NULL,
  `status`       ENUM('pending','inprogress','done') NOT NULL DEFAULT 'pending',
  `note`         TEXT          DEFAULT NULL,
  `updated_by`   INT UNSIGNED DEFAULT NULL,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_school_ind` (`school_id`, `indicator_id`),
  CONSTRAINT `fk_sis_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sis_ind`    FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
