-- Ordering for evidences so they can be reordered via drag-and-drop.
-- Backfill existing rows from their id (stable creation order within an indicator).

ALTER TABLE `evidences`
  ADD COLUMN `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `note`;

UPDATE `evidences` SET `sort_order` = `id` WHERE `sort_order` = 0;
