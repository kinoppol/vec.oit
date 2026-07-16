-- 008 · Create evidences table (FK → schools, indicators)
CREATE TABLE IF NOT EXISTS `evidences` (
  `id`           INT UNSIGNED AUTO_INCREMENT,
  `school_id`    INT UNSIGNED NOT NULL,
  `indicator_id` INT UNSIGNED NOT NULL,
  `type`         ENUM('link','file','image','text') NOT NULL DEFAULT 'link',
  `title`        VARCHAR(300)  NOT NULL,
  `url`          VARCHAR(1000) DEFAULT NULL,
  `file_path`    VARCHAR(300)  DEFAULT NULL,
  `note`         TEXT          DEFAULT NULL,
  `created_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ev_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ev_ind`    FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
