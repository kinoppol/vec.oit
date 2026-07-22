-- Central (OVEC-wide) positions: a position with school_id = NULL is a "ตำแหน่งกลาง"
-- available to every school and editable only by a centraladmin. A schooladmin's
-- position can be promoted to central by a centraladmin. Making school_id nullable
-- is enough — the FK already permits NULL, and central-name uniqueness is enforced
-- in application code (the UNIQUE(school_id,name) key does not constrain NULLs).

ALTER TABLE `positions` MODIFY COLUMN `school_id` INT UNSIGNED NULL;
