-- Master list of job positions per school (editable), used as suggestions
-- for the users.position field. Typing a new value auto-adds it here.

CREATE TABLE IF NOT EXISTS `positions` (
  `id`         INT UNSIGNED AUTO_INCREMENT,
  `school_id`  INT UNSIGNED NOT NULL,
  `name`       VARCHAR(150) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_school_pos` (`school_id`, `name`),
  CONSTRAINT `fk_pos_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from positions already typed on users
INSERT IGNORE INTO `positions` (`school_id`, `name`)
SELECT `school_id`, `position` FROM `users`
WHERE `school_id` IS NOT NULL AND `position` IS NOT NULL AND `position` <> ''
GROUP BY `school_id`, `position`;
