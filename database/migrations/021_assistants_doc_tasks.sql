-- Team workflow per indicator: assistant responsibles, document tasks, and
-- an acceptance gate on evidence before it is published.
--   indicator_assistants     : helpers on an indicator (proposed by the responsible,
--                              approved by a schooladmin — or added approved directly).
--   document_tasks           : "หัวข้อเอกสาร" — a required document with a description,
--                              assigned to one or more approved assistants.
--   document_task_assignees  : many assignees per document task.
--   evidences.task_id        : links an evidence to a document task (NULL = free/indicator-level).
--   evidences.accepted...    : an assistant's file only publishes once the responsible accepts it;
--                              the responsible's / schooladmin's own files are accepted on upload.
-- Split into independent statements so migrate.php can treat "already exists" as a skip.

CREATE TABLE IF NOT EXISTS `indicator_assistants` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `school_id`    INT UNSIGNED NOT NULL,
  `indicator_id` INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `status`       ENUM('proposed','approved') NOT NULL DEFAULT 'proposed',
  `proposed_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_assistant` (`school_id`,`indicator_id`,`user_id`),
  KEY `k_ia_ind` (`school_id`,`indicator_id`),
  CONSTRAINT `fk_ia_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_tasks` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `school_id`    INT UNSIGNED NOT NULL,
  `indicator_id` INT UNSIGNED NOT NULL,
  `title`        VARCHAR(300) NOT NULL,
  `description`  TEXT NOT NULL,
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `k_dt_ind` (`school_id`,`indicator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_task_assignees` (
  `task_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`task_id`,`user_id`),
  CONSTRAINT `fk_dta_task` FOREIGN KEY (`task_id`) REFERENCES `document_tasks`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dta_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `evidences` ADD COLUMN `task_id` INT UNSIGNED DEFAULT NULL AFTER `indicator_id`;

ALTER TABLE `evidences` ADD COLUMN `accepted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_by`;

ALTER TABLE `evidences` ADD COLUMN `accepted_by` INT UNSIGNED DEFAULT NULL AFTER `accepted`;

ALTER TABLE `evidences` ADD COLUMN `accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `accepted_by`;

UPDATE `evidences` SET `accepted` = 1 WHERE `accepted` = 0;
