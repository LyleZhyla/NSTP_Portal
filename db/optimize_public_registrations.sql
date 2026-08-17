-- Run once after deploying the public-registration performance update.

ALTER TABLE tbl_users
    ADD COLUMN IF NOT EXISTS first_login_at DATETIME NULL AFTER last_password_change,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER first_login_at,
    ADD COLUMN IF NOT EXISTS login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at;

UPDATE tbl_users u
LEFT JOIN (
    SELECT user_id, MIN(created_at) AS first_login_at,
           MAX(created_at) AS last_login_at, COUNT(*) AS login_count
    FROM tbl_system_logs
    WHERE action = 'user_login' AND user_id IS NOT NULL
    GROUP BY user_id
) activity ON activity.user_id = u.user_id
SET u.first_login_at = COALESCE(u.first_login_at, activity.first_login_at),
    u.last_login_at = COALESCE(u.last_login_at, activity.last_login_at),
    u.login_count = GREATEST(u.login_count, COALESCE(activity.login_count, 0));

ALTER TABLE tbl_users
    ADD INDEX IF NOT EXISTS idx_users_role_program (role, program),
    ADD INDEX IF NOT EXISTS idx_users_last_login (role, last_login_at);

ALTER TABLE tbl_public_student_registrations
    ADD INDEX IF NOT EXISTS idx_public_reg_student_status (student_number, registrant_role, status),
    ADD INDEX IF NOT EXISTS idx_public_reg_list (registrant_role, status, component, created_at),
    ADD INDEX IF NOT EXISTS idx_public_reg_user (user_id);

ALTER TABLE tbl_system_logs
    ADD INDEX IF NOT EXISTS idx_logs_login_user_date (action, user_id, created_at);
