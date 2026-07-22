-- Reference documents attached to a criterion (indicator) by a centraladmin.
-- Indicators are per fiscal year, so these files are inherently per-year.
-- Schools see them read-only as supporting material for the assessment.

CREATE TABLE IF NOT EXISTS `indicator_files` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `indicator_id` INT UNSIGNED NOT NULL,
  `title`        VARCHAR(300) NOT NULL,
  `file_path`    VARCHAR(300) NOT NULL,
  `type`         ENUM('image','file') NOT NULL DEFAULT 'file',
  `uploaded_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `k_if_ind` (`indicator_id`),
  CONSTRAINT `fk_if_ind` FOREIGN KEY (`indicator_id`) REFERENCES `indicators`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
