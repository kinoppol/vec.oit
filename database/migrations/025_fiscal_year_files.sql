-- Reference documents attached to a whole fiscal year by a centraladmin
-- (general assessment guidelines/manuals for that year). Schools see them read-only.

CREATE TABLE IF NOT EXISTS `fiscal_year_files` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `title`          VARCHAR(300) NOT NULL,
  `file_path`      VARCHAR(300) NOT NULL,
  `type`           ENUM('image','file') NOT NULL DEFAULT 'file',
  `uploaded_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `k_fyf_year` (`fiscal_year_id`),
  CONSTRAINT `fk_fyf_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
