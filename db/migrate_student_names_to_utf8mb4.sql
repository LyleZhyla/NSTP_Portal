-- Run once on existing installations. The main schema already uses utf8mb4
-- for new installations after this migration was added.
ALTER TABLE `tbl_student`
    CONVERT TO CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;
