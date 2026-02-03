ALTER TABLE jta_therapy_followups
    ADD COLUMN check_type VARCHAR(20) NULL AFTER entry_type,
    ADD COLUMN pharmacy_id INT NULL AFTER therapy_id,
    ADD COLUMN created_by INT NULL AFTER pharmacy_id;

CREATE TABLE IF NOT EXISTS jta_therapy_checklist_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    therapy_id INT NOT NULL,
    pharmacy_id INT NOT NULL,
    condition_key VARCHAR(100) NULL,
    question_key VARCHAR(191) NULL,
    question_text TEXT NOT NULL,
    input_type VARCHAR(20) NOT NULL DEFAULT 'text',
    options_json JSON NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tcq_therapy_key (therapy_id, question_key),
    INDEX idx_tcq_therapy (therapy_id),
    INDEX idx_tcq_pharmacy (pharmacy_id)
);

CREATE TABLE IF NOT EXISTS jta_therapy_checklist_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    followup_id INT NOT NULL,
    question_id INT NOT NULL,
    answer_value TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_check_answer (followup_id, question_id),
    INDEX idx_tca_followup (followup_id),
    INDEX idx_tca_question (question_id)
);

UPDATE jta_therapy_followups f
JOIN jta_therapies t ON f.therapy_id = t.id
SET f.pharmacy_id = t.pharmacy_id
WHERE f.pharmacy_id IS NULL;

UPDATE jta_therapy_followups
SET check_type = 'periodic'
WHERE check_type = 'followup';

CREATE INDEX idx_tf_therapy_pharmacy ON jta_therapy_followups (therapy_id, pharmacy_id);
