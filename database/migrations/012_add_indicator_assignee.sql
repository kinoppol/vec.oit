-- Add responsible-user assignment per school+indicator.
-- Stored on school_indicator_status (unique per school_id+indicator_id).
-- Split into two statements so each is independently idempotent for migrate.php
-- (duplicate column = 1060, duplicate FK = 1826 are treated as skips).

ALTER TABLE `school_indicator_status`
  ADD COLUMN `assigned_user_id` INT UNSIGNED DEFAULT NULL AFTER `note`;

ALTER TABLE `school_indicator_status`
  ADD CONSTRAINT `fk_sis_assignee` FOREIGN KEY (`assigned_user_id`)
  REFERENCES `users` (`id`) ON DELETE SET NULL;
