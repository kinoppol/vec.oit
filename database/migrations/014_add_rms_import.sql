-- Support importing users from an external RMS per school.
-- schools.rms_base_url: the source origin (e.g. http://rms.rvc.ac.th), admin-editable.
-- users.email: from people_email in the RMS payload.
-- Split statements so each is independently idempotent for migrate.php.

ALTER TABLE `schools`
  ADD COLUMN `rms_base_url` VARCHAR(300) DEFAULT NULL AFTER `website`;

ALTER TABLE `users`
  ADD COLUMN `email` VARCHAR(200) DEFAULT NULL AFTER `full_name`;
