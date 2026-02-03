ALTER TABLE jta_therapy_reminders
    MODIFY COLUMN status ENUM('scheduled','shown','sent','canceled') NOT NULL DEFAULT 'scheduled';

ALTER TABLE jta_therapy_followups
    ADD COLUMN entry_type VARCHAR(20) NOT NULL DEFAULT 'followup' AFTER therapy_id;

UPDATE jta_therapy_followups
SET entry_type = CASE
    WHEN COALESCE(JSON_LENGTH(snapshot), 0) > 0 THEN 'check'
    ELSE 'followup'
END;

CREATE INDEX idx_tf_entry_type ON jta_therapy_followups (entry_type);
