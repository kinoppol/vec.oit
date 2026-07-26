-- Optional grouping label for evidence ("ประเด็นที่พิจารณา"). NULL/empty = ungrouped,
-- shown as a plain list. Used to organise published documents by sub-topic.

ALTER TABLE `evidences`
  ADD COLUMN `group_label` VARCHAR(200) DEFAULT NULL AFTER `note`;
