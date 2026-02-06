ALTER TABLE jta_therapy_reminders
    ADD COLUMN description TEXT NULL AFTER title,
    ADD COLUMN frequency ENUM('one_shot','weekly','biweekly','monthly') NOT NULL DEFAULT 'one_shot' AFTER description,
    ADD COLUMN interval_value INT NOT NULL DEFAULT 1 AFTER frequency,
    ADD COLUMN weekday TINYINT NULL AFTER interval_value,
    ADD COLUMN first_due_at DATETIME NULL AFTER weekday,
    ADD COLUMN next_due_at DATETIME NULL AFTER first_due_at,
    ADD COLUMN status_new ENUM('active','done','cancelled') NOT NULL DEFAULT 'active' AFTER next_due_at;

UPDATE jta_therapy_reminders
SET description = message,
    frequency = CASE type
        WHEN 'one-shot' THEN 'one_shot'
        WHEN 'weekly' THEN 'weekly'
        WHEN 'monthly' THEN 'monthly'
        WHEN 'daily' THEN 'weekly'
        ELSE 'one_shot'
    END,
    interval_value = 1,
    weekday = CASE
        WHEN type IN ('weekly','daily') THEN ((DAYOFWEEK(scheduled_at) + 5) % 7) + 1
        ELSE NULL
    END,
    first_due_at = scheduled_at,
    next_due_at = scheduled_at,
    status_new = CASE
        WHEN status = 'canceled' THEN 'cancelled'
        ELSE 'active'
    END;

ALTER TABLE jta_therapy_reminders
    MODIFY COLUMN title VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY COLUMN description TEXT COLLATE utf8mb4_unicode_ci NULL,
    MODIFY COLUMN first_due_at DATETIME NOT NULL,
    MODIFY COLUMN next_due_at DATETIME NOT NULL;

ALTER TABLE jta_therapy_reminders
    DROP COLUMN message,
    DROP COLUMN type,
    DROP COLUMN scheduled_at,
    DROP COLUMN channel,
    DROP COLUMN status,
    CHANGE COLUMN status_new status ENUM('active','done','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active';

DROP INDEX idx_tr_schedule ON jta_therapy_reminders;
CREATE INDEX idx_tr_status_due ON jta_therapy_reminders (status, next_due_at);
CREATE INDEX idx_tr_therapy_status ON jta_therapy_reminders (therapy_id, status);
