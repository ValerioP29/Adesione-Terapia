<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/therapy_checklist.php';

header('Content-Type: application/json');
requireApiAuth(['admin', 'pharmacist']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

function respond($success, $data = null, $error = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

function getPharmacyId() {
    return get_panel_pharma_id(true);
}

function normalizeDateFields($data) {
    if (!is_array($data)) {
        return $data;
    }

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = normalizeDateFields($value);
            continue;
        }

        if (is_string($value) && $value === '' && preg_match('/(_date|_at)$/', $key)) {
            $data[$key] = null;
        }
    }

    return $data;
}

function executeQueryWithTypes(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $index = 1;

    foreach ($params as $value) {
        $type = is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR;
        $stmt->bindValue($index, $value, $type);
        $index++;
    }

    $stmt->execute();
    return $stmt;
}

function fetchPatientForPharmacy($patient_id, $pharmacy_id) {
    if (!$patient_id || !$pharmacy_id) {
        return null;
    }

    try {
        return db_fetch_one(
            "SELECT p.id FROM jta_patients p JOIN jta_pharma_patient pp ON p.id = pp.patient_id WHERE p.id = ? AND pp.pharma_id = ? AND pp.deleted_at IS NULL",
            [$patient_id, $pharmacy_id]
        );
    } catch (Exception $e) {
        respond(false, null, 'Errore verifica paziente', 500);
    }
}

function fetchAssistantForPharmacy($assistant_id, $pharmacy_id) {
    if (!$assistant_id || !$pharmacy_id) {
        return null;
    }

    try {
        return db_fetch_one(
            "SELECT id FROM jta_assistants WHERE id = ? AND pharma_id = ?",
            [$assistant_id, $pharmacy_id]
        );
    } catch (Exception $e) {
        respond(false, null, 'Errore verifica assistente', 500);
    }
}

$pdo = db()->getConnection();

switch ($method) {
    case 'GET':
        $pharmacy_id = getPharmacyId();
        $therapy_id = $_GET['id'] ?? null;
        $status = $_GET['status'] ?? null;
        $patient_id = $_GET['patient_id'] ?? null;
        $search = $_GET['q'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;

        $params = [];
        $where = [];

        if ($therapy_id) {
            $where[] = 't.id = ?';
            $params[] = $therapy_id;
        }
        if ($status) {
            $where[] = 't.status = ?';
            $params[] = sanitize($status);
        }
        if ($patient_id) {
            $where[] = 't.patient_id = ?';
            $params[] = $patient_id;
        }
        if ($pharmacy_id) {
            $where[] = 't.pharmacy_id = ?';
            $params[] = $pharmacy_id;
        }
        if ($search) {
            $where[] = '(p.first_name LIKE ? OR p.last_name LIKE ? OR p.codice_fiscale LIKE ? OR t.therapy_title LIKE ?)';
            $like = '%' . sanitize($search) . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT t.*, p.first_name, p.last_name, p.codice_fiscale, p.birth_date, p.phone, p.email, p.notes,
                       ph.nice_name AS pharmacy_name,
                       tcc.primary_condition, tcc.notes_initial, tcc.follow_up_date, tcc.risk_score,
                       tcc.flags, tcc.general_anamnesis, tcc.detailed_intake, tcc.adherence_base,
                       tcc.doctor_info, tcc.biometric_info, tcc.care_context,
                       tcc.consent AS consent
                FROM jta_therapies t
                JOIN jta_patients p ON t.patient_id = p.id
                JOIN jta_pharmas ph ON t.pharmacy_id = ph.id
                LEFT JOIN jta_therapy_chronic_care tcc ON t.id = tcc.therapy_id
                $whereSql
                ORDER BY t.created_at DESC
                LIMIT $per_page OFFSET $offset";

        try {
            $rows = db_fetch_all($sql, $params);
            foreach ($rows as &$row) {
                $row['consent'] = isset($row['consent']) ? ($row['consent'] ? json_decode($row['consent'], true) : null) : null;
                $row['flags'] = isset($row['flags']) ? ($row['flags'] ? json_decode($row['flags'], true) : null) : null;
                $row['general_anamnesis'] = isset($row['general_anamnesis']) ? ($row['general_anamnesis'] ? json_decode($row['general_anamnesis'], true) : null) : null;
                $row['detailed_intake'] = isset($row['detailed_intake']) ? ($row['detailed_intake'] ? json_decode($row['detailed_intake'], true) : null) : null;
                $row['adherence_base'] = isset($row['adherence_base']) ? ($row['adherence_base'] ? json_decode($row['adherence_base'], true) : null) : null;
                $row['doctor_info'] = isset($row['doctor_info']) ? ($row['doctor_info'] ? json_decode($row['doctor_info'], true) : null) : null;
                $row['biometric_info'] = isset($row['biometric_info']) ? ($row['biometric_info'] ? json_decode($row['biometric_info'], true) : null) : null;
                $row['care_context'] = isset($row['care_context']) ? ($row['care_context'] ? json_decode($row['care_context'], true) : null) : null;

                if ($therapy_id) {
                    $survey = db_fetch_one(
                        "SELECT condition_type, level, answers, compiled_at FROM jta_therapy_condition_surveys WHERE therapy_id = ? ORDER BY compiled_at DESC, id DESC LIMIT 1",
                        [$row['id']]
                    );
                    if ($survey) {
                        $survey['answers'] = isset($survey['answers']) ? ($survey['answers'] ? json_decode($survey['answers'], true) : null) : null;
                    }
                    $row['condition_survey'] = $survey ?: null;

                    $assistants = db_fetch_all(
                        "SELECT ta.assistant_id, ta.role, ta.contact_channel, ta.preferences_json, ta.consents_json,
                                a.first_name, a.last_name, a.phone, a.email, a.type, a.relation_to_patient, a.preferred_contact, a.notes
                         FROM jta_therapy_assistant ta
                         JOIN jta_assistants a ON ta.assistant_id = a.id
                         WHERE ta.therapy_id = ?
                         ORDER BY ta.id ASC",
                        [$row['id']]
                    );
                    foreach ($assistants as &$assistant) {
                        $assistant['preferences_json'] = isset($assistant['preferences_json']) ? ($assistant['preferences_json'] ? json_decode($assistant['preferences_json'], true) : null) : null;
                        $assistant['consents_json'] = isset($assistant['consents_json']) ? ($assistant['consents_json'] ? json_decode($assistant['consents_json'], true) : null) : null;
                    }
                    unset($assistant);
                    $row['therapy_assistants'] = $assistants;
                }
            }
            respond(true, ['items' => $rows, 'page' => $page, 'per_page' => $per_page]);
        } catch (Exception $e) {
            respond(false, null, 'Errore caricamento terapie', 500);
        }
        break;

    case 'POST':
        if ($action === 'delete') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $therapy_id = $input['therapy_id'] ?? $input['id'] ?? null;
            $pharmacy_id = get_panel_pharma_id(true);

            if (!$therapy_id) {
                respond(false, null, 'ID terapia mancante', 400);
            }

            $existing = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
            if (!$existing) {
                respond(false, null, 'Terapia non trovata', 404);
            }

            try {
                db_query("DELETE FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
                respond(true, ['therapy_id' => $therapy_id]);
            } catch (Exception $e) {
                respond(false, null, 'Errore eliminazione terapia', 500);
            }
        }

        $input = normalizeDateFields(json_decode(file_get_contents('php://input'), true) ?? []);
        $pharmacy_id = getPharmacyId();
        if (!$pharmacy_id) {
            respond(false, null, 'Farmacia non disponibile', 400);
        }

        $patient = normalizeDateFields(sanitize($input['patient'] ?? []));
        $primary_condition = sanitize($input['primary_condition'] ?? null);
        $initial_notes = $input['initial_notes'] ?? null;
        $general_anamnesis = $input['general_anamnesis'] ?? null;
        $detailed_intake = $input['detailed_intake'] ?? null;
        $therapy_assistants = $input['therapy_assistants'] ?? [];
        $adherence_base = $input['adherence_base'] ?? null;
        $condition_survey = $input['condition_survey'] ?? null;
        $risk_score = $input['risk_score'] ?? null;
        $flags = $input['flags'] ?? null;
        $notes_initial = $input['notes_initial'] ?? null;
        $follow_up_date = $input['follow_up_date'] ?? null;
        $consent = $input['consent'] ?? null;
        $doctor_info = $input['doctor_info'] ?? null;
        $biometric_info = $input['biometric_info'] ?? null;
        $care_context = $input['care_context'] ?? null;

        if (!$patient || empty($patient['first_name']) || empty($patient['last_name']) || !$primary_condition) {
            respond(false, null, 'Dati paziente o patologia mancanti', 400);
        }

        try {
            $pdo->beginTransaction();

            $patient_id = $patient['id'] ?? null;
            if (!$patient_id) {
                executeQueryWithTypes($pdo, 
                    "INSERT INTO jta_patients (pharmacy_id, first_name, last_name, birth_date, codice_fiscale, phone, email, notes) VALUES (?,?,?,?,?,?,?,?)",
                    [
                        $pharmacy_id,
                        $patient['first_name'],
                        $patient['last_name'],
                        $patient['birth_date'] ?? null,
                        $patient['codice_fiscale'] ?? null,
                        $patient['phone'] ?? null,
                        $patient['email'] ?? null,
                        $patient['notes'] ?? null
                    ]
                );
                $patient_id = $pdo->lastInsertId();
                executeQueryWithTypes($pdo, "INSERT INTO jta_pharma_patient (pharma_id, patient_id) VALUES (?, ?)", [$pharmacy_id, $patient_id]);
            } else {
                $patientCheck = fetchPatientForPharmacy($patient_id, $pharmacy_id);
                if (!$patientCheck) {
                    $pdo->rollBack();
                    respond(false, null, 'Paziente non trovato per la farmacia', 404);
                }
                executeQueryWithTypes($pdo, 
                    "UPDATE jta_patients SET first_name = ?, last_name = ?, birth_date = ?, codice_fiscale = ?, phone = ?, email = ?, notes = ? WHERE id = ? AND (pharmacy_id = ? OR pharmacy_id IS NULL)",
                    [
                        $patient['first_name'],
                        $patient['last_name'],
                        $patient['birth_date'] ?? null,
                        $patient['codice_fiscale'] ?? null,
                        $patient['phone'] ?? null,
                        $patient['email'] ?? null,
                        $patient['notes'] ?? null,
                        $patient_id,
                        $pharmacy_id
                    ]
                );
            }

            $therapy_title = $input['therapy_title'] ?? ('– ' . $primary_condition);
            $therapy_description = $input['therapy_description'] ?? $initial_notes;
            $status = $input['status'] ?? 'active';
            $start_date = $input['start_date'] ?? date('Y-m-d');
            $end_date = $input['end_date'] ?? null;

            executeQueryWithTypes($pdo, 
                "INSERT INTO jta_therapies (pharmacy_id, patient_id, therapy_title, therapy_description, status, start_date, end_date) VALUES (?,?,?,?,?,?,?)",
                [$pharmacy_id, $patient_id, $therapy_title, $therapy_description, $status, $start_date, $end_date]
            );
            $therapy_id = $pdo->lastInsertId();

            executeQueryWithTypes($pdo, 
                "INSERT INTO jta_therapy_chronic_care (therapy_id, primary_condition, general_anamnesis, detailed_intake, adherence_base, risk_score, flags, notes_initial, follow_up_date, consent, doctor_info, biometric_info, care_context) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $therapy_id,
                    $primary_condition,
                    $general_anamnesis ? json_encode($general_anamnesis) : null,
                    $detailed_intake ? json_encode($detailed_intake) : null,
                    $adherence_base ? json_encode($adherence_base) : null,
                    $risk_score,
                    $flags ? json_encode($flags) : null,
                    $notes_initial ?? $initial_notes,
                    $follow_up_date,
                    $consent ? json_encode($consent) : null,
                    $doctor_info ? json_encode($doctor_info) : null,
                    $biometric_info ? json_encode($biometric_info) : null,
                    $care_context ? json_encode($care_context) : null
                ]
            );

            foreach ($therapy_assistants as $assistant) {
                $assistant = sanitize($assistant);
                $assistant_id = $assistant['assistant_id'] ?? null;
                if (!$assistant_id) {
                    executeQueryWithTypes($pdo, 
                        "INSERT INTO jta_assistants (pharma_id, first_name, last_name, phone, email, type, relation_to_patient, preferred_contact, notes) VALUES (?,?,?,?,?,?,?,?,?)",
                        [
                            $pharmacy_id,
                            $assistant['first_name'] ?? '',
                            $assistant['last_name'] ?? null,
                            $assistant['phone'] ?? null,
                            $assistant['email'] ?? null,
                            $assistant['type'] ?? 'familiare',
                            $assistant['relation_to_patient'] ?? null,
                            $assistant['preferred_contact'] ?? null,
                            $assistant['notes'] ?? null
                        ]
                    );
                    $assistant_id = $pdo->lastInsertId();
                } else {
                    $assistantCheck = fetchAssistantForPharmacy($assistant_id, $pharmacy_id);
                    if (!$assistantCheck) {
                        $pdo->rollBack();
                        respond(false, null, 'Assistente non trovato per la farmacia', 404);
                    }
                }

                executeQueryWithTypes($pdo, 
                    "INSERT INTO jta_therapy_assistant (therapy_id, assistant_id, role, contact_channel, preferences_json, consents_json) VALUES (?,?,?,?,?,?)",
                    [
                        $therapy_id,
                        $assistant_id,
                        $assistant['role'] ?? 'familiare',
                        $assistant['contact_channel'] ?? null,
                        isset($assistant['preferences_json']) ? json_encode($assistant['preferences_json']) : null,
                        isset($assistant['consents_json']) ? json_encode($assistant['consents_json']) : null
                    ]
                );
            }

            if ($condition_survey) {
                executeQueryWithTypes($pdo, 
                    "INSERT INTO jta_therapy_condition_surveys (therapy_id, condition_type, level, answers, compiled_at) VALUES (?,?,?,?,?)",
                    [
                        $therapy_id,
                        $condition_survey['condition_type'] ?? $primary_condition,
                        $condition_survey['level'] ?? 'base',
                        isset($condition_survey['answers']) ? json_encode($condition_survey['answers']) : null,
                        $condition_survey['compiled_at'] ?? date('Y-m-d H:i:s')
                    ]
                );
            }

            ensureTherapyChecklist($pdo, $therapy_id, $pharmacy_id, $primary_condition);

            $userId = $_SESSION['user_id'] ?? null;
            executeQueryWithTypes(
                $pdo,
                "INSERT INTO jta_therapy_followups (therapy_id, pharmacy_id, created_by, entry_type, check_type, follow_up_date) VALUES (?,?,?,?,?,?)",
                [
                    $therapy_id,
                    $pharmacy_id,
                    $userId,
                    'check',
                    'initial',
                    null
                ]
            );
            $initialCheckId = $pdo->lastInsertId();

            $answersMap = $condition_survey && isset($condition_survey['answers']) && is_array($condition_survey['answers'])
                ? $condition_survey['answers']
                : [];
            $questionRows = db_fetch_all(
                "SELECT id, question_key FROM jta_therapy_checklist_questions WHERE therapy_id = ? ORDER BY sort_order ASC, id ASC",
                [$therapy_id]
            );
            foreach ($questionRows as $row) {
                $answerValue = null;
                $key = $row['question_key'] ?? null;
                if ($key && array_key_exists($key, $answersMap)) {
                    $answerValue = $answersMap[$key];
                    if (is_bool($answerValue)) {
                        $answerValue = $answerValue ? 'true' : 'false';
                    }
                }
                executeQueryWithTypes(
                    $pdo,
                    "INSERT INTO jta_therapy_checklist_answers (followup_id, question_id, answer_value) VALUES (?,?,?)",
                    [
                        $initialCheckId,
                        $row['id'],
                        $answerValue
                    ]
                );
            }

            if ($consent) {
                $consentSignerName = $consent['signer_name'] ?? '';
                $consentText = $consent['consent_text'] ?? 'Consenso informato e trattamento dati';
                $consentSignedAt = $consent['signed_at'] ?? null;
                if (empty($consentSignedAt)) {
                    $consentSignedAt = date('Y-m-d H:i:s');
                }
                $signatures = $consent['signatures'] ?? null;
                $signaturePayload = $signatures ? json_encode($signatures) : null;

                executeQueryWithTypes($pdo,
                    "INSERT INTO jta_therapy_consents (therapy_id, signer_name, signer_relation, consent_text, signed_at, ip_address, signature_image, scopes_json, signer_role) VALUES (?,?,?,?,?,?,?,?,?)",
                    [
                        $therapy_id,
                        $consentSignerName,
                        $consent['signer_relation'] ?? 'patient',
                        $consentText,
                        $consentSignedAt,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $signaturePayload,
                        isset($consent['scopes']) ? json_encode($consent['scopes']) : null,
                        $consent['signer_role'] ?? null
                    ]
                );
            }

            $pdo->commit();
            respond(true, ['therapy_id' => $therapy_id]);
       } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('therapies.php create error: ' . $e->getMessage());
            respond(false, null, 'Errore salvataggio terapia', 500);
        }

        break;

    case 'PUT':
        $input = normalizeDateFields(json_decode(file_get_contents('php://input'), true) ?? []);
        $therapy_id = $input['id'] ?? ($_GET['id'] ?? null);
        $pharmacy_id = getPharmacyId();
        if (!$therapy_id || !$pharmacy_id) {
            respond(false, null, 'ID terapia o farmacia mancanti', 400);
        }

        $patient = normalizeDateFields(sanitize($input['patient'] ?? []));
        $primary_condition = sanitize($input['primary_condition'] ?? null);
        $initial_notes = $input['initial_notes'] ?? null;
        $general_anamnesis = $input['general_anamnesis'] ?? null;
        $detailed_intake = $input['detailed_intake'] ?? null;
        $therapy_assistants = $input['therapy_assistants'] ?? [];
        $adherence_base = $input['adherence_base'] ?? null;
        $condition_survey = $input['condition_survey'] ?? null;
        $risk_score = $input['risk_score'] ?? null;
        $flags = $input['flags'] ?? null;
        $notes_initial = $input['notes_initial'] ?? null;
        $follow_up_date = $input['follow_up_date'] ?? null;
        $consent = $input['consent'] ?? null;
        $doctor_info = $input['doctor_info'] ?? null;
        $biometric_info = $input['biometric_info'] ?? null;
        $care_context = $input['care_context'] ?? null;

        try {
            $pdo->beginTransaction();

            $therapy = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
            if (!$therapy) {
                $pdo->rollBack();
                respond(false, null, 'Terapia non trovata per la farmacia', 404);
            }

            $patient_id = $patient['id'] ?? null;
            if ($patient_id) {
                $patientCheck = fetchPatientForPharmacy($patient_id, $pharmacy_id);
                if (!$patientCheck) {
                    $pdo->rollBack();
                    respond(false, null, 'Paziente non trovato per la farmacia', 404);
                }
                executeQueryWithTypes($pdo, 
                    "UPDATE jta_patients SET first_name = ?, last_name = ?, birth_date = ?, codice_fiscale = ?, phone = ?, email = ?, notes = ? WHERE id = ? AND (pharmacy_id = ? OR pharmacy_id IS NULL)",
                    [
                        $patient['first_name'] ?? '',
                        $patient['last_name'] ?? '',
                        $patient['birth_date'] ?? null,
                        $patient['codice_fiscale'] ?? null,
                        $patient['phone'] ?? null,
                        $patient['email'] ?? null,
                        $patient['notes'] ?? null,
                        $patient_id,
                        $pharmacy_id
                    ]
                );
            }

            executeQueryWithTypes($pdo, 
                "UPDATE jta_therapies SET therapy_title = ?, therapy_description = ?, status = ?, start_date = ?, end_date = ? WHERE id = ? AND pharmacy_id = ?",
                [
                    $input['therapy_title'] ?? ('– ' . $primary_condition),
                    $input['therapy_description'] ?? $initial_notes,
                    $input['status'] ?? 'active',
                    $input['start_date'] ?? date('Y-m-d'),
                    $input['end_date'] ?? null,
                    $therapy_id,
                    $pharmacy_id
                ]
            );

            $existing = db_fetch_one("SELECT id FROM jta_therapy_chronic_care WHERE therapy_id = ?", [$therapy_id]);
            $existingDetails = db_fetch_one("SELECT doctor_info, biometric_info, care_context FROM jta_therapy_chronic_care WHERE therapy_id = ?", [$therapy_id]);
            $doctorInfoPayload = array_key_exists('doctor_info', $input)
                ? ($doctor_info ? json_encode($doctor_info) : null)
                : ($existingDetails['doctor_info'] ?? null);
            $biometricInfoPayload = array_key_exists('biometric_info', $input)
                ? ($biometric_info ? json_encode($biometric_info) : null)
                : ($existingDetails['biometric_info'] ?? null);
            $careContextPayload = array_key_exists('care_context', $input)
                ? ($care_context ? json_encode($care_context) : null)
                : ($existingDetails['care_context'] ?? null);
            if ($existing) {
                executeQueryWithTypes($pdo,
                    "UPDATE jta_therapy_chronic_care SET primary_condition = ?, general_anamnesis = ?, detailed_intake = ?, adherence_base = ?, risk_score = ?, flags = ?, notes_initial = ?, follow_up_date = ?, consent = ?, doctor_info = ?, biometric_info = ?, care_context = ?, updated_at = NOW() WHERE therapy_id = ?",
                    [
                        $primary_condition,
                        $general_anamnesis ? json_encode($general_anamnesis) : null,
                        $detailed_intake ? json_encode($detailed_intake) : null,
                        $adherence_base ? json_encode($adherence_base) : null,
                        $risk_score,
                        $flags ? json_encode($flags) : null,
                        $notes_initial ?? $initial_notes,
                        $follow_up_date,
                        $consent ? json_encode($consent) : null,
                        $doctorInfoPayload,
                        $biometricInfoPayload,
                        $careContextPayload,
                        $therapy_id
                    ]
                );
            } else {
                executeQueryWithTypes($pdo,
                    "INSERT INTO jta_therapy_chronic_care (therapy_id, primary_condition, general_anamnesis, detailed_intake, adherence_base, risk_score, flags, notes_initial, follow_up_date, consent, doctor_info, biometric_info, care_context) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $therapy_id,
                        $primary_condition,
                        $general_anamnesis ? json_encode($general_anamnesis) : null,
                        $detailed_intake ? json_encode($detailed_intake) : null,
                        $adherence_base ? json_encode($adherence_base) : null,
                        $risk_score,
                        $flags ? json_encode($flags) : null,
                        $notes_initial ?? $initial_notes,
                        $follow_up_date,
                        $consent ? json_encode($consent) : null,
                        $doctorInfoPayload,
                        $biometricInfoPayload,
                        $careContextPayload
                    ]
                );
            }

            executeQueryWithTypes($pdo, "DELETE FROM jta_therapy_assistant WHERE therapy_id = ?", [$therapy_id]);
            foreach ($therapy_assistants as $assistant) {
                $assistant = sanitize($assistant);
                $assistant_id = $assistant['assistant_id'] ?? null;
                if (!$assistant_id) {
                    executeQueryWithTypes($pdo, 
                        "INSERT INTO jta_assistants (pharma_id, first_name, last_name, phone, email, type, relation_to_patient, preferred_contact, notes) VALUES (?,?,?,?,?,?,?,?,?)",
                        [
                            $pharmacy_id,
                            $assistant['first_name'] ?? '',
                            $assistant['last_name'] ?? null,
                            $assistant['phone'] ?? null,
                            $assistant['email'] ?? null,
                            $assistant['type'] ?? 'familiare',
                            $assistant['relation_to_patient'] ?? null,
                            $assistant['preferred_contact'] ?? null,
                            $assistant['notes'] ?? null
                        ]
                    );
                    $assistant_id = $pdo->lastInsertId();
                } else {
                    $assistantCheck = fetchAssistantForPharmacy($assistant_id, $pharmacy_id);
                    if (!$assistantCheck) {
                        $pdo->rollBack();
                        respond(false, null, 'Assistente non trovato per la farmacia', 404);
                    }
                }
                executeQueryWithTypes($pdo, 
                    "INSERT INTO jta_therapy_assistant (therapy_id, assistant_id, role, contact_channel, preferences_json, consents_json) VALUES (?,?,?,?,?,?)",
                    [
                        $therapy_id,
                        $assistant_id,
                        $assistant['role'] ?? 'familiare',
                        $assistant['contact_channel'] ?? null,
                        isset($assistant['preferences_json']) ? json_encode($assistant['preferences_json']) : null,
                        isset($assistant['consents_json']) ? json_encode($assistant['consents_json']) : null
                    ]
                );
            }

            executeQueryWithTypes($pdo, "DELETE FROM jta_therapy_condition_surveys WHERE therapy_id = ?", [$therapy_id]);
            if ($condition_survey) {
                executeQueryWithTypes($pdo, 
                    "INSERT INTO jta_therapy_condition_surveys (therapy_id, condition_type, level, answers, compiled_at) VALUES (?,?,?,?,?)",
                    [
                        $therapy_id,
                        $condition_survey['condition_type'] ?? $primary_condition,
                        $condition_survey['level'] ?? 'base',
                        isset($condition_survey['answers']) ? json_encode($condition_survey['answers']) : null,
                        $condition_survey['compiled_at'] ?? date('Y-m-d H:i:s')
                    ]
                );
            }

            executeQueryWithTypes($pdo, "DELETE FROM jta_therapy_consents WHERE therapy_id = ?", [$therapy_id]);
            if ($consent) {
                $consentSignerName = $consent['signer_name'] ?? '';
                $consentText = $consent['consent_text'] ?? 'Consenso informato e trattamento dati';
                $consentSignedAt = $consent['signed_at'] ?? null;
                if (empty($consentSignedAt)) {
                    $consentSignedAt = date('Y-m-d H:i:s');
                }
                $signatures = $consent['signatures'] ?? null;
                $signaturePayload = $signatures ? json_encode($signatures) : null;

                executeQueryWithTypes($pdo,
                    "INSERT INTO jta_therapy_consents (therapy_id, signer_name, signer_relation, consent_text, signed_at, ip_address, signature_image, scopes_json, signer_role) VALUES (?,?,?,?,?,?,?,?,?)",
                    [
                        $therapy_id,
                        $consentSignerName,
                        $consent['signer_relation'] ?? 'patient',
                        $consentText,
                        $consentSignedAt,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $signaturePayload,
                        isset($consent['scopes']) ? json_encode($consent['scopes']) : null,
                        $consent['signer_role'] ?? null
                    ]
                );
            }

            $pdo->commit();
            respond(true, ['therapy_id' => $therapy_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(false, null, 'Errore aggiornamento terapia', 500);
        }
        break;

    case 'DELETE':
        $therapy_id = $_GET['id'] ?? null;
        $pharmacy_id = getPharmacyId();
        if (!$therapy_id || !$pharmacy_id) {
            respond(false, null, 'ID terapia o farmacia mancanti', 400);
        }
        try {
            executeQueryWithTypes($pdo, "UPDATE jta_therapies SET status = 'suspended', end_date = ? WHERE id = ? AND pharmacy_id = ?", [date('Y-m-d'), $therapy_id, $pharmacy_id]);
            respond(true, ['therapy_id' => $therapy_id]);
        } catch (Exception $e) {
            respond(false, null, 'Errore sospensione terapia', 500);
        }
        break;

    default:
        respond(false, null, 'Metodo non consentito', 405);
}
?>
