-- Nickname imported from RMS (people_nickname).

ALTER TABLE `users`
  ADD COLUMN `nickname` VARCHAR(100) DEFAULT NULL AFTER `full_name`;
