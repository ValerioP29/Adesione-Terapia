<?php
session_start();
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null) {
       $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
       if (!in_array($err['type'] ?? null, $fatalTypes, true)) {
           return;
       }
        error_log('followups.php fatal error: ' . json_encode($err));
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        http_response_code(500);
        ob_get_clean();
        echo json_encode([
            'success' => false,
            'data' => null,
            'error' => 'Fatal error'
        ]);
    }
});
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/therapy_checklist.php';

header('Content-Type: application/json');
requireApiAuth(['admin', 'pharmacist']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

function respondFollowups($success, $data = null, $error = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

$pharmacy_id = get_panel_pharma_id(true);

function getFollowupById($followup_id, $pharmacy_id)
{
    try {
        return db_fetch_one(
            "SELECT f.* FROM jta_therapy_followups f JOIN jta_therapies t ON f.therapy_id = t.id WHERE f.id = ? AND t.pharmacy_id = ?",
            [$followup_id, $pharmacy_id]
        );
    } catch (Exception $e) {
        respondFollowups(false, null, 'Errore recupero follow-up', 500);
    }
}

function normalizeSnapshot($snapshot)
{
    if (!is_array($snapshot)) {
        $snapshot = [];
    }

    $snapshot['condition'] = $snapshot['condition'] ?? null;
    $snapshot['questions'] = array_values($snapshot['questions'] ?? []);
    $snapshot['custom_questions'] = array_values($snapshot['custom_questions'] ?? []);

    return $snapshot;
}

function fetchChecklistQuestions($therapy_id, $pharmacy_id, $includeInactive = false)
{
    $params = [$therapy_id, $pharmacy_id];
    $sql = "SELECT id, question_key, question_text, input_type, options_json, sort_order, is_active
            FROM jta_therapy_checklist_questions
            WHERE therapy_id = ? AND pharmacy_id = ?";
    if (!$includeInactive) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";

    try {
        $rows = db_fetch_all($sql, $params);
    } catch (Exception $e) {
        respondFollowups(false, null, 'Errore recupero checklist', 500);
    }

    foreach ($rows as &$row) {
        $row['options'] = $row['options_json'] ? json_decode($row['options_json'], true) : null;
        unset($row['options_json']);
    }
    return $rows;
}

function fetchChecklistAnswers($followup_id)
{
    try {
        $rows = db_fetch_all(
            "SELECT question_id, answer_value FROM jta_therapy_checklist_answers WHERE followup_id = ?",
            [$followup_id]
        );
    } catch (Exception $e) {
        respondFollowups(false, null, 'Errore recupero risposte', 500);
    }

    $answers = [];
    foreach ($rows as $row) {
        $answers[$row['question_id']] = $row['answer_value'];
    }
    return $answers;
}

function buildBaseQuestions($answers)
{
    if (!is_array($answers)) {
        return [];
    }

    $questions = [];
    foreach ($answers as $key => $value) {
        $type = is_bool($value) ? 'boolean' : 'text';
        $questions[] = [
            'key' => (string)$key,
            'text' => (string)$key,
            'label' => (string)$key,
            'type' => $type,
            'answer' => null
        ];
    }
    return $questions;
}

function withFollowupStatus(array $rows)
{
    return array_map(function ($row) {
        $snapshot = json_decode($row['snapshot'] ?? '', true);
        $isCanceled = is_array($snapshot) && !empty($snapshot['canceled']);
        $row['status'] = $isCanceled ? 'canceled' : 'scheduled';
        return $row;
    }, $rows);
}

switch ($method) {
    case 'GET':
        if ($action === 'checklist') {
            $therapy_id = $_GET['therapy_id'] ?? null;
            if (!$therapy_id) {
                respondFollowups(false, null, 'therapy_id richiesto', 400);
            }
            $questions = fetchChecklistQuestions($therapy_id, $pharmacy_id);
            respondFollowups(true, ['questions' => $questions]);
        }

        if ($action === 'check-answers') {
            $followup_id = $_GET['id'] ?? null;
            if (!$followup_id) {
                respondFollowups(false, null, 'id follow-up richiesto', 400);
            }
            $followup = getFollowupById($followup_id, $pharmacy_id);
            if (!$followup) {
                respondFollowups(false, null, 'Follow-up non trovato', 404);
            }
            $answers = fetchChecklistAnswers($followup_id);
            respondFollowups(true, ['answers' => $answers]);
        }

        $therapy_id = $_GET['therapy_id'] ?? null;
        $entry_type = $_GET['entry_type'] ?? null;
        if (!$therapy_id) {
            respondFollowups(false, null, 'therapy_id richiesto', 400);
        }
        $params = [$therapy_id, $pharmacy_id];
        $sql = "SELECT f.*,
                    (SELECT COUNT(*)
                     FROM jta_therapy_checklist_answers a
                     WHERE a.followup_id = f.id AND a.answer_value IS NOT NULL AND a.answer_value <> '') AS answer_count
                FROM jta_therapy_followups f
                JOIN jta_therapies t ON f.therapy_id = t.id
                WHERE f.therapy_id = ? AND t.pharmacy_id = ?";
        if ($entry_type === 'check') {
            $sql .= " AND (f.entry_type = ? OR (f.entry_type IS NULL AND COALESCE(JSON_LENGTH(f.snapshot), 0) > 0))";
            $params[] = 'check';
        } elseif ($entry_type === 'followup') {
            $sql .= " AND (f.entry_type = ? OR (f.entry_type IS NULL AND COALESCE(JSON_LENGTH(f.snapshot), 0) = 0))";
            $params[] = 'followup';
        } elseif ($entry_type) {
            $sql .= " AND f.entry_type = ?";
            $params[] = $entry_type;
        }
        $sql .= " ORDER BY f.created_at DESC";
        try {
            $rows = db_fetch_all($sql, $params);
            respondFollowups(true, ['items' => withFollowupStatus($rows)]);
        } catch (Exception $e) {
            respondFollowups(false, null, 'Errore recupero follow-up', 500);
        }
        break;

    case 'POST':
        if ($action === 'checklist-add') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $therapy_id = $input['therapy_id'] ?? null;
            $text = trim($input['text'] ?? '');
            $type = $input['type'] ?? 'text';
            $options = $input['options'] ?? null;
            if (!$therapy_id || !$text || !in_array($type, ['text', 'boolean', 'select'], true)) {
                respondFollowups(false, null, 'Dati domanda non validi', 400);
            }
            $therapy = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
            if (!$therapy) {
                respondFollowups(false, null, 'Terapia non trovata per la farmacia', 400);
            }
            $conditionRow = db_fetch_one(
                "SELECT primary_condition FROM jta_therapy_chronic_care WHERE therapy_id = ?",
                [$therapy_id]
            );
            $maxRow = db_fetch_one(
                "SELECT MAX(sort_order) AS max_sort FROM jta_therapy_checklist_questions WHERE therapy_id = ?",
                [$therapy_id]
            );
            $nextSort = (int)($maxRow['max_sort'] ?? 0) + 1;
            try {
                db_query(
                    "INSERT INTO jta_therapy_checklist_questions (therapy_id, pharmacy_id, condition_key, question_key, question_text, input_type, options_json, sort_order, is_active)
                     VALUES (?,?,?,?,?,?,?,?,1)",
                    [
                        $therapy_id,
                        $pharmacy_id,
                        $conditionRow['primary_condition'] ?? null,
                        null,
                        $text,
                        $type,
                        is_array($options) ? json_encode($options) : null,
                        $nextSort
                    ]
                );
                $question_id = db()->getConnection()->lastInsertId();
                respondFollowups(true, ['question_id' => $question_id]);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore aggiunta domanda', 500);
            }
        }

        if ($action === 'checklist-remove') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $question_id = $input['question_id'] ?? null;
            $therapy_id = $input['therapy_id'] ?? null;
            if (!$question_id || !$therapy_id) {
                respondFollowups(false, null, 'question_id o therapy_id richiesti', 400);
            }
            try {
                db_query(
                    "UPDATE jta_therapy_checklist_questions q
                     JOIN jta_therapies t ON q.therapy_id = t.id
                     SET q.is_active = 0
                     WHERE q.id = ? AND q.therapy_id = ? AND t.pharmacy_id = ?",
                    [$question_id, $therapy_id, $pharmacy_id]
                );
                respondFollowups(true, ['question_id' => $question_id]);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore rimozione domanda', 500);
            }
        }

        if ($action === 'checklist-reorder') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $order = $input['order'] ?? [];
            $therapy_id = $input['therapy_id'] ?? null;
            if (!is_array($order) || empty($order) || !$therapy_id) {
                respondFollowups(false, null, 'Ordine o therapy_id non validi', 400);
            }
            try {
                $sort = 1;
                foreach ($order as $question_id) {
                    db_query(
                        "UPDATE jta_therapy_checklist_questions q
                         JOIN jta_therapies t ON q.therapy_id = t.id
                         SET q.sort_order = ?
                         WHERE q.id = ? AND q.therapy_id = ? AND t.pharmacy_id = ?",
                        [$sort, $question_id, $therapy_id, $pharmacy_id]
                    );
                    $sort++;
                }
                respondFollowups(true, ['order' => $order]);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore riordino domande', 500);
            }
        }

        if ($action === 'check-answers') {
            $followup_id = $_GET['id'] ?? null;
            if (!$followup_id) {
                respondFollowups(false, null, 'id follow-up richiesto', 400);
            }
            $followup = getFollowupById($followup_id, $pharmacy_id);
            if (!$followup) {
                respondFollowups(false, null, 'Follow-up non trovato', 404);
            }
            $therapyId = $followup['therapy_id'] ?? null;
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $answers = $input['answers'] ?? [];
            if (!is_array($answers)) {
                respondFollowups(false, null, 'Formato risposte non valido', 400);
            }

            try {
                $validRows = db_fetch_all(
                    "SELECT id FROM jta_therapy_checklist_questions WHERE therapy_id = ? AND pharmacy_id = ?",
                    [$therapyId, $pharmacy_id]
                );
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore recupero domande checklist', 500);
            }
            $validIds = array_map(function ($row) {
                return (int)$row['id'];
            }, $validRows);
            $validIdSet = array_flip($validIds);

            try {
                foreach ($answers as $answer) {
                    $question_id = $answer['question_id'] ?? null;
                    if (!$question_id) {
                        continue;
                    }
                    $question_id = (int)$question_id;
                    if (!isset($validIdSet[$question_id])) {
                        respondFollowups(false, null, 'Domanda non valida per la terapia', 422);
                    }
                    $value = $answer['answer'] ?? null;
                    if (is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }
                    db_query(
                        "INSERT INTO jta_therapy_checklist_answers (followup_id, question_id, answer_value)
                         VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE answer_value = VALUES(answer_value), updated_at = NOW()",
                        [$followup_id, $question_id, $value]
                    );
                }
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore salvataggio risposte', 500);
            }
            $answersMap = fetchChecklistAnswers($followup_id);
            respondFollowups(true, ['answers' => $answersMap]);
        }

        if ($action === 'check-meta') {
            $followup_id = $_GET['id'] ?? null;
            if (!$followup_id) {
                respondFollowups(false, null, 'id follow-up richiesto', 400);
            }
            $followup = getFollowupById($followup_id, $pharmacy_id);
            if (!$followup) {
                respondFollowups(false, null, 'Follow-up non trovato', 404);
            }
            if (($followup['entry_type'] ?? null) !== 'check') {
                respondFollowups(false, null, 'Tipo follow-up non valido', 422);
            }
            $rawBody = file_get_contents('php://input');
            $input = json_decode($rawBody, true);
            if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
                respondFollowups(false, null, 'JSON non valido', 400);
            }
            if (!is_array($input)) {
                $input = [];
            }
            $fields = [];
            $params = [];

            if (array_key_exists('risk_score', $input)) {
                $riskScore = $input['risk_score'];
                if ($riskScore !== null && filter_var($riskScore, FILTER_VALIDATE_INT) === false) {
                    respondFollowups(false, null, 'risk_score non valido', 422);
                }
                $fields[] = 'risk_score = ?';
                $params[] = $riskScore;
            }
            if (array_key_exists('follow_up_date', $input)) {
                $followUpDate = $input['follow_up_date'];
                if ($followUpDate !== null) {
                    $formatOk = is_string($followUpDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $followUpDate);
                    if (!$formatOk) {
                        respondFollowups(false, null, 'follow_up_date non valida', 422);
                    }
                    $date = DateTime::createFromFormat('Y-m-d', $followUpDate);
                    if (!$date || $date->format('Y-m-d') !== $followUpDate) {
                        respondFollowups(false, null, 'follow_up_date non valida', 422);
                    }
                }
                $fields[] = 'follow_up_date = ?';
                $params[] = $followUpDate;
            }
            if (array_key_exists('pharmacist_notes', $input)) {
                $fields[] = 'pharmacist_notes = ?';
                $params[] = $input['pharmacist_notes'];
            }

            if (!$fields) {
                respondFollowups(false, null, 'Nessun campo da aggiornare', 400);
            }

            try {
                $params[] = $followup_id;
                db_query(
                    "UPDATE jta_therapy_followups SET " . implode(', ', $fields) . " WHERE id = ?",
                    $params
                );
                $updated = getFollowupById($followup_id, $pharmacy_id);
                if ($updated) {
                    $updated = withFollowupStatus([$updated])[0];
                }
                respondFollowups(true, ['followup' => $updated]);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore aggiornamento follow-up', 500);
            }
        }

        if ($action === 'init') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $therapy_id = $input['therapy_id'] ?? null;
            if (!$therapy_id) {
                respondFollowups(false, null, 'therapy_id richiesto', 400);
            }
            try {
                $therapy = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
                if (!$therapy) {
                    respondFollowups(false, null, 'Terapia non trovata per la farmacia', 400);
                }

                $conditionRow = db_fetch_one(
                    "SELECT primary_condition FROM jta_therapy_chronic_care WHERE therapy_id = ?",
                    [$therapy_id]
                );
                $baseSurvey = db_fetch_one(
                    "SELECT answers FROM jta_therapy_condition_surveys WHERE therapy_id = ? AND level = 'base' ORDER BY compiled_at DESC LIMIT 1",
                    [$therapy_id]
                );
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore recupero dati terapia', 500);
            }

            try {
                $pdo = db()->getConnection();
                $pdo->beginTransaction();

                ensureTherapyChecklist($pdo, $therapy_id, $pharmacy_id, $conditionRow['primary_condition'] ?? null);
                $userId = $_SESSION['user_id'] ?? null;
                db_query(
                    "INSERT INTO jta_therapy_followups (therapy_id, pharmacy_id, created_by, entry_type, check_type) VALUES (?,?,?,?,?)",
                    [
                        $therapy_id,
                        $pharmacy_id,
                        $userId,
                        'check',
                        'periodic'
                    ]
                );
                $followup_id = db()->getConnection()->lastInsertId();
                $questions = fetchChecklistQuestions($therapy_id, $pharmacy_id);
                foreach ($questions as $question) {
                    db_query(
                        "INSERT INTO jta_therapy_checklist_answers (followup_id, question_id, answer_value) VALUES (?,?,?)",
                        [$followup_id, $question['id'], null]
                    );
                }

                $pdo->commit();
                $followup = db_fetch_one("SELECT * FROM jta_therapy_followups WHERE id = ?", [$followup_id]);
                if ($followup) {
                    $followup['status'] = 'scheduled';
                }
            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                respondFollowups(false, null, 'Errore creazione follow-up', 500);
            }
            $answersMap = fetchChecklistAnswers($followup_id);
            respondFollowups(true, ['followup' => $followup, 'questions' => $questions, 'answers' => $answersMap]);
            break;
        }

        if ($action === 'add-question') {
            $followup_id = $_GET['id'] ?? null;
            if (!$followup_id) {
                respondFollowups(false, null, 'id follow-up richiesto', 400);
            }
            $followup = getFollowupById($followup_id, $pharmacy_id);
            if (!$followup) {
                respondFollowups(false, null, 'Follow-up non trovato', 404);
            }
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $text = trim($input['text'] ?? '');
            $type = $input['type'] ?? 'text';
            if (!$text || !in_array($type, ['text', 'boolean'], true)) {
                respondFollowups(false, null, 'Testo o tipo domanda non validi', 400);
            }

            $snapshot = normalizeSnapshot(json_decode($followup['snapshot'] ?? '', true));
                $snapshot['custom_questions'][] = [
                    'text' => $text,
                    'label' => $text,
                    'type' => $type,
                    'answer' => null
                ];

            try {
                db_query(
                    "UPDATE jta_therapy_followups SET snapshot = ? WHERE id = ?",
                    [json_encode($snapshot), $followup_id]
                );
                respondFollowups(true, ['snapshot' => $snapshot]);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore aggiunta domanda', 500);
            }
            break;
        }

        if ($action === 'remove-question') {
            $followup_id = $_GET['id'] ?? null;
            if (!$followup_id) {
                respondFollowups(false, null, 'id follow-up richiesto', 400);
            }
            $followup = getFollowupById($followup_id, $pharmacy_id);
            if (!$followup) {
                respondFollowups(false, null, 'Follow-up non trovato', 404);
            }
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $index = $input['index'] ?? null;
            if ($index === null || !is_numeric($index)) {
                respondFollowups(false, null, 'Indice domanda mancante', 400);
            }
            $snapshot = normalizeSnapshot(json_decode($followup['snapshot'] ?? '', true));
            if (!isset($snapshot['questions'][$index])) {
                respondFollowups(false, null, 'Domanda non trovata', 404);
            }
            array_splice($snapshot['questions'], (int)$index, 1);
            try {
                db_query(
                    "UPDATE jta_therapy_followups SET snapshot = ? WHERE id = ?",
                    [json_encode($snapshot), $followup_id]
                );
                respondFollowups(true, ['snapshot' => $snapshot]);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore rimozione domanda', 500);
            }
            break;
        }

        if ($action === 'answer') {
            $followup_id = $_GET['id'] ?? null;
            if (!$followup_id) {
                respondFollowups(false, null, 'id follow-up richiesto', 400);
            }
            $followup = getFollowupById($followup_id, $pharmacy_id);
            if (!$followup) {
                respondFollowups(false, null, 'Follow-up non trovato', 404);
            }
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $answers = $input['answers'] ?? [];
            if (!is_array($answers)) {
                respondFollowups(false, null, 'Formato risposte non valido', 400);
            }

            $snapshot = normalizeSnapshot(json_decode($followup['snapshot'] ?? '', true));
            foreach ($answers as $answer) {
                $index = $answer['index'] ?? null;
                $value = $answer['answer'] ?? null;
                $label = $answer['label'] ?? null;
                $isCustom = !empty($answer['custom']);
                if ($index === null || !is_numeric($index)) {
                    continue;
                }
                if ($isCustom && isset($snapshot['custom_questions'][(int)$index])) {
                    $snapshot['custom_questions'][(int)$index]['answer'] = $value;
                    if ($label !== null) {
                        $snapshot['custom_questions'][(int)$index]['label'] = $label;
                    }
                } elseif (!$isCustom && isset($snapshot['questions'][(int)$index])) {
                    $snapshot['questions'][(int)$index]['answer'] = $value;
                    if ($label !== null) {
                        $snapshot['questions'][(int)$index]['label'] = $label;
                    }
                }
            }

            try {
                db_query(
                    "UPDATE jta_therapy_followups SET snapshot = ? WHERE id = ?",
                    [json_encode($snapshot), $followup_id]
                );
                respondFollowups(true, ['snapshot' => $snapshot]);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore salvataggio risposte', 500);
            }
            break;
        }

        if ($action === 'cancel') {
            $followup_id = $_GET['id'] ?? null;
            if (!$followup_id) {
                respondFollowups(false, null, 'id follow-up richiesto', 400);
            }
            $followup = getFollowupById($followup_id, $pharmacy_id);
            if (!$followup) {
                respondFollowups(false, null, 'Follow-up non trovato', 404);
            }
            try {
                db_query(
                    "UPDATE jta_therapy_followups SET snapshot = JSON_SET(COALESCE(snapshot, '{}'), '$.canceled', true) WHERE id = ?",
                    [$followup_id]
                );
                $updatedSnapshot = json_decode($followup['snapshot'] ?? '', true);
                if (!is_array($updatedSnapshot)) {
                    $updatedSnapshot = [];
                }
                $updatedSnapshot['canceled'] = true;
                respondFollowups(true, ['snapshot' => $updatedSnapshot, 'status' => 'canceled']);
            } catch (Exception $e) {
                respondFollowups(false, null, 'Errore annullamento follow-up', 500);
            }
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $therapy_id = $input['therapy_id'] ?? null;
        $risk_score = $input['risk_score'] ?? null;
        $follow_up_date = $input['follow_up_date'] ?? null;
        $pharmacist_notes = $input['pharmacist_notes'] ?? null;

        if (!$therapy_id || $risk_score === null || !$follow_up_date) {
            respondFollowups(false, null, 'Campi obbligatori mancanti', 400);
        }
        if (filter_var($risk_score, FILTER_VALIDATE_INT) === false) {
            respondFollowups(false, null, 'risk_score non valido', 422);
        }
        $formatOk = is_string($follow_up_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow_up_date);
        if (!$formatOk) {
            respondFollowups(false, null, 'follow_up_date non valida', 422);
        }
        $date = DateTime::createFromFormat('Y-m-d', $follow_up_date);
        if (!$date || $date->format('Y-m-d') !== $follow_up_date) {
            respondFollowups(false, null, 'follow_up_date non valida', 422);
        }

        try {
            $therapy = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
            if (!$therapy) {
                respondFollowups(false, null, 'Terapia non trovata per la farmacia', 400);
            }

            $userId = $_SESSION['user_id'] ?? null;
            db_query(
                "INSERT INTO jta_therapy_followups (therapy_id, pharmacy_id, created_by, entry_type, risk_score, pharmacist_notes, follow_up_date) VALUES (?,?,?,?,?,?,?)",
                [
                    $therapy_id,
                    $pharmacy_id,
                    $userId,
                    'followup',
                    $risk_score,
                    $pharmacist_notes,
                    $follow_up_date
                ]
            );
            $followup_id = db()->getConnection()->lastInsertId();
            $followup = db_fetch_one("SELECT * FROM jta_therapy_followups WHERE id = ?", [$followup_id]);
            if ($followup) {
                $followup['status'] = 'scheduled';
            }
            respondFollowups(true, ['followup' => $followup]);
        } catch (Exception $e) {
            respondFollowups(false, null, 'Errore creazione follow-up', 500);
        }
        break;

    default:
        respondFollowups(false, null, 'Metodo non consentito', 405);
}
