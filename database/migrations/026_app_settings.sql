-- Central-admin adjustable runtime settings (key/value).
CREATE TABLE IF NOT EXISTS `app_settings` (
  `skey`       VARCHAR(64) NOT NULL,
  `svalue`     TEXT NULL,
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-file upload ceiling in MB. Absent row = the MAX_UPLOAD_DEFAULT constant.
INSERT IGNORE INTO `app_settings` (`skey`, `svalue`) VALUES ('max_upload_mb', '50');
