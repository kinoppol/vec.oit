-- Allow assigning an indicator to a position (everyone holding it is responsible),
-- in addition to assigning to a single user. Stored on school_indicator_status.

ALTER TABLE `school_indicator_status`
  ADD COLUMN `assigned_position_id` INT UNSIGNED DEFAULT NULL AFTER `assigned_user_id`;

ALTER TABLE `school_indicator_status`
  ADD CONSTRAINT `fk_sis_assign_pos` FOREIGN KEY (`assigned_position_id`)
  REFERENCES `positions` (`id`) ON DELETE SET NULL;
