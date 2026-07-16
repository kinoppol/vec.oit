-- 002 · Create users table (FK → schools)
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED AUTO_INCREMENT,
  `school_id`      INT UNSIGNED DEFAULT NULL,
  `national_id`    VARCHAR(13)  NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `full_name`      VARCHAR(200) NOT NULL,
  `role`           ENUM('user','schooladmin','centraladmin') NOT NULL DEFAULT 'user',
  `status`         ENUM('active','disabled','pending') NOT NULL DEFAULT 'pending',
  `must_change_pw` TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`     TIMESTAMP    NULL DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_national_id` (`national_id`),
  CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
