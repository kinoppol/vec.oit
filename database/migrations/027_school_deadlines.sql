-- Per-school data-collection deadline for a fiscal year (set by a schooladmin).
-- A live countdown to this is shown throughout the app (never on the public site).

CREATE TABLE IF NOT EXISTS `school_deadlines` (
  `school_id`      INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `deadline`       DATETIME NOT NULL,
  `updated_by`     INT UNSIGNED DEFAULT NULL,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`school_id`, `fiscal_year_id`),
  CONSTRAINT `fk_sd_school` FOREIGN KEY (`school_id`)      REFERENCES `schools`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_sd_year`   FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
