-- Job position/title per user, set manually in the users admin page.
-- NOT touched by RMS import, so it survives re-imports.

ALTER TABLE `users`
  ADD COLUMN `position` VARCHAR(150) DEFAULT NULL AFTER `nickname`;
