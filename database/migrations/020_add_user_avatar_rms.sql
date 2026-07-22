-- Profile picture + RMS-origin flag for users.
--   avatar   : filename under uploads/avatars/ (NULL → UI falls back to initials).
--   from_rms : 1 when the account was imported from an external RMS, so the
--              password-reset UI can tell the admin to change it at the RMS instead.
-- Split into two statements so each is independently idempotent for migrate.php
-- (duplicate column = 1060 is treated as a skip).

ALTER TABLE `users`
  ADD COLUMN `avatar` VARCHAR(255) DEFAULT NULL AFTER `email`;

ALTER TABLE `users`
  ADD COLUMN `from_rms` TINYINT(1) NOT NULL DEFAULT 0 AFTER `avatar`;
