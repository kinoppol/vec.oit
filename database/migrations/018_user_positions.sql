-- Allow a user to hold multiple positions (many-to-many).
-- users.position is kept as a denormalized ", "-joined cache for display/search.

CREATE TABLE IF NOT EXISTS `user_positions` (
  `user_id`     INT UNSIGNED NOT NULL,
  `position_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `position_id`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_up_pos`  FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from the existing single-value users.position
INSERT IGNORE INTO `user_positions` (`user_id`, `position_id`)
SELECT u.id, p.id FROM `users` u
JOIN `positions` p ON p.school_id = u.school_id AND p.name = u.position
WHERE u.position IS NOT NULL AND u.position <> '';
