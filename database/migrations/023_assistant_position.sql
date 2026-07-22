-- Allow an indicator assistant to be a position (everyone holding it helps),
-- in addition to an individual user. user_id XOR position_id.
-- Split statements so migrate.php treats "already exists" as a skip.

ALTER TABLE `indicator_assistants` MODIFY COLUMN `user_id` INT UNSIGNED NULL;

ALTER TABLE `indicator_assistants`
  ADD COLUMN `position_id` INT UNSIGNED NULL AFTER `user_id`;

ALTER TABLE `indicator_assistants`
  ADD CONSTRAINT `fk_ia_pos` FOREIGN KEY (`position_id`) REFERENCES `positions`(`id`) ON DELETE CASCADE;

ALTER TABLE `indicator_assistants`
  ADD UNIQUE KEY `uq_assistant_pos` (`school_id`,`indicator_id`,`position_id`);
