<?php
session_start();
date_default_timezone_set('Europe/Rome');
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null) {
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'] ?? null, $fatalTypes, true)) {
            return;
        }
        error_log('reminders.php fatal error: ' . json_encode($err));
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

header('Content-Type: application/json');
requireApiAuth(['admin', 'pharmacist']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

function respondReminders($success, $data = null, $error = null, $code = 200, $meta = []) {
    http_response_code($code);
    $response = ['success' => $success, 'status' => $success, 'data' => $data, 'error' => $error];
    if (isset($meta['code'])) {
        $response['code'] = $meta['code'];
    }
    if (isset($meta['message'])) {
        $response['message'] = $meta['message'];
    }
    if (array_key_exists('details', $meta)) {
        $response['details'] = $meta['details'];
    }
    echo json_encode($response);
    exit;
}

function parseReminderDateTime($value, DateTimeZone $timezone) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    foreach (['Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $candidate = DateTime::createFromFormat($format, $raw, $timezone);
        $errors = DateTime::getLastErrors();
        if ($errors === false) {
            $errors = ['warning_count' => 0, 'error_count' => 0];
        }
        if ($candidate && $errors['warning_count'] === 0 && $errors['error_count'] === 0) {
            return $candidate;
        }
    }
    return null;
}

function normalizeReminderFrequency($value) {
    $raw = strtolower(trim((string) $value));
    $map = [
        'one-shot' => 'one_shot',
        'one shot' => 'one_shot',
        'once' => 'one_shot',
        'bi-weekly' => 'biweekly',
        'bi weekly' => 'biweekly'
    ];
    return $map[$raw] ?? $raw;
}

function addMonthsKeepDay(DateTime $date, $months) {
    $day = (int) $date->format('d');
    $time = $date->format('H:i:s');
    $candidate = (clone $date)->modify("first day of +{$months} month");
    $year = (int) $candidate->format('Y');
    $month = (int) $candidate->format('m');
    $daysInMonth = (int) $candidate->format('t');
    $targetDay = min($day, $daysInMonth);
    $candidate->setDate($year, $month, $targetDay);
    [$hour, $minute, $second] = array_map('intval', explode(':', $time));
    $candidate->setTime($hour, $minute, $second);
    return $candidate;
}

function computeNextDueAt(array $reminder, DateTimeZone $timezone) {
    $frequency = normalizeReminderFrequency($reminder['frequency'] ?? 'one_shot');
    $intervalValue = (int) ($reminder['interval_value'] ?? 1);
    if ($intervalValue < 1) {
        $intervalValue = 1;
    }
    $base = $reminder['next_due_at'] ?? $reminder['first_due_at'] ?? null;
    if (!$base) {
        return null;
    }
    $current = new DateTime($base, $timezone);

    switch ($frequency) {
        case 'weekly':
            $weeks = 1 * $intervalValue;
            $current->modify("+{$weeks} week");
            return $current;
        case 'biweekly':
            $weeks = 2 * $intervalValue;
            $current->modify("+{$weeks} week");
            return $current;
        case 'monthly':
            return addMonthsKeepDay($current, 1 * $intervalValue);
        default:
            return $current;
    }
}

$pharmacy_id = get_panel_pharma_id(true);

switch ($method) {
    case 'GET':
        $reminder_id = $_GET['id'] ?? null;
        if ($reminder_id) {
            try {
                $reminder = db_fetch_one(
                    "SELECT r.*, t.therapy_title, t.patient_id FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                    [$reminder_id, $pharmacy_id]
                );
                if (!$reminder) {
                    respondReminders(false, null, 'Promemoria non trovato', 404);
                }
                respondReminders(true, ['item' => $reminder]);
            } catch (Exception $e) {
                respondReminders(false, null, 'Errore recupero promemoria', 500, [
                    'details' => ['message' => $e->getMessage()]
                ]);
            }
        }

        $view = $_GET['view'] ?? null;
        if ($view !== 'agenda') {
            respondReminders(false, null, 'view=agenda richiesto', 400);
        }

        $therapy_id = $_GET['therapy_id'] ?? null;
        $patient_id = $_GET['patient_id'] ?? null;
        $timezone = new DateTimeZone('Europe/Rome');
        $now = new DateTime('now', $timezone);
        $startTomorrow = (clone $now)->setTime(0, 0, 0)->modify('+1 day');

        $where = ['t.pharmacy_id = ?'];
        $params = [$pharmacy_id];
        if ($therapy_id) {
            $where[] = 't.id = ?';
            $params[] = $therapy_id;
        }
        if ($patient_id) {
            $where[] = 't.patient_id = ?';
            $params[] = $patient_id;
        }
        $whereSql = implode(' AND ', $where);
        $baseSql = "FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id LEFT JOIN jta_patients p ON t.patient_id = p.id WHERE {$whereSql}";
        $selectSql = "SELECT r.*, t.therapy_title, t.patient_id, p.first_name, p.last_name {$baseSql}";

        try {
            $overdue = db_fetch_all(
                $selectSql . " AND r.status = 'active' AND r.next_due_at < ? ORDER BY r.next_due_at ASC",
                array_merge($params, [$now->format('Y-m-d H:i:s')])
            );
            $todayItems = db_fetch_all(
                $selectSql . " AND r.status = 'active' AND r.next_due_at >= ? AND r.next_due_at < ? ORDER BY r.next_due_at ASC",
                array_merge($params, [$now->format('Y-m-d H:i:s'), $startTomorrow->format('Y-m-d H:i:s')])
            );
            $upcoming = db_fetch_all(
                $selectSql . " AND r.status = 'active' AND r.next_due_at >= ? ORDER BY r.next_due_at ASC",
                array_merge($params, [$startTomorrow->format('Y-m-d H:i:s')])
            );

            respondReminders(true, [
                'overdue' => $overdue,
                'today' => $todayItems,
                'upcoming' => $upcoming
            ]);
        } catch (Exception $e) {
            respondReminders(false, null, 'Errore recupero agenda', 500, [
                'details' => ['message' => $e->getMessage()]
            ]);
        }
        break;

    case 'POST':
        if ($action === 'cancel') {
            $reminder_id = $_GET['id'] ?? null;
            if (!$reminder_id) {
                respondReminders(false, null, 'id promemoria richiesto', 400);
            }

            try {
                $reminder = db_fetch_one(
                    "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                    [$reminder_id, $pharmacy_id]
                );

                if (!$reminder) {
                    respondReminders(false, null, 'Promemoria non trovato', 404);
                }

                db_query(
                    "UPDATE jta_therapy_reminders SET status = 'cancelled' WHERE id = ? AND therapy_id IN (SELECT id FROM jta_therapies WHERE pharmacy_id = ?)",
                    [$reminder_id, $pharmacy_id]
                );

                $updated = db_fetch_one(
                    "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                    [$reminder_id, $pharmacy_id]
                );

                respondReminders(true, ['item' => $updated]);
            } catch (Exception $e) {
                $logTherapyId = isset($reminder['therapy_id']) ? $reminder['therapy_id'] : 'null';
                error_log(
                    "reminders.php ERROR pharmacy={$pharmacy_id} therapy={$logTherapyId} cancel: " . $e->getMessage()
                );
                $details = ['message' => $e->getMessage()];
                if ($e->getCode()) {
                    $details['code'] = $e->getCode();
                }
                respondReminders(false, null, 'Errore annullamento promemoria', 500, [
                    'details' => $details
                ]);
            }
        }

        if ($action === 'mark_done') {
            $reminder_id = $_GET['id'] ?? null;
            if (!$reminder_id) {
                respondReminders(false, null, 'id promemoria richiesto', 400);
            }

            try {
                $reminder = db_fetch_one(
                    "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                    [$reminder_id, $pharmacy_id]
                );

                if (!$reminder) {
                    respondReminders(false, null, 'Promemoria non trovato', 404);
                }

                $timezone = new DateTimeZone('Europe/Rome');
                $frequency = normalizeReminderFrequency($reminder['frequency'] ?? 'one_shot');
                if ($frequency === 'one_shot') {
                    db_query(
                        "UPDATE jta_therapy_reminders SET status = 'done' WHERE id = ?",
                        [$reminder_id]
                    );
                } else {
                    $reminder['frequency'] = $frequency;
                    $nextDueAt = computeNextDueAt($reminder, $timezone);
                    if (!$nextDueAt) {
                        respondReminders(false, null, 'Impossibile calcolare la prossima scadenza', 422);
                    }
                    db_query(
                        "UPDATE jta_therapy_reminders SET next_due_at = ?, status = 'active' WHERE id = ?",
                        [$nextDueAt->format('Y-m-d H:i:s'), $reminder_id]
                    );
                }

                $updated = db_fetch_one(
                    "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                    [$reminder_id, $pharmacy_id]
                );

                respondReminders(true, ['item' => $updated]);
            } catch (Exception $e) {
                $logTherapyId = isset($reminder['therapy_id']) ? $reminder['therapy_id'] : 'null';
                error_log(
                    "reminders.php ERROR pharmacy={$pharmacy_id} therapy={$logTherapyId} mark_done: " . $e->getMessage()
                );
                $details = ['message' => $e->getMessage()];
                if ($e->getCode()) {
                    $details['code'] = $e->getCode();
                }
                respondReminders(false, null, 'Errore aggiornamento promemoria', 500, [
                    'details' => $details
                ]);
            }
        }

        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true);
        if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
            respondReminders(false, null, 'JSON non valido', 400);
        }
        if (!is_array($input)) {
            respondReminders(false, null, 'JSON non valido', 400);
        }
        $therapy_id = $input['therapy_id'] ?? null;
        $title = trim((string) ($input['title'] ?? ''));
        $frequency = normalizeReminderFrequency($input['frequency'] ?? null);
        $firstDueRaw = $input['first_due_at'] ?? null;
        if (!$therapy_id || $title === '' || !$frequency || !$firstDueRaw) {
            respondReminders(false, null, 'Campi obbligatori mancanti', 400);
        }

        $allowedFrequencies = ['one_shot', 'weekly', 'biweekly', 'monthly'];
        if (!in_array($frequency, $allowedFrequencies, true)) {
            respondReminders(false, null, 'Tipo promemoria non valido', 422);
        }

        $timezone = new DateTimeZone('Europe/Rome');
        $firstDueAt = parseReminderDateTime($firstDueRaw, $timezone);
        if (!$firstDueAt) {
            respondReminders(false, null, 'Formato data/ora non valido', 422, [
                'code' => 'REMINDER_INVALID_DATETIME',
                'message' => 'Formato data/ora non valido.',
                'details' => ['first_due_at' => $firstDueRaw]
            ]);
        }

        $weekday = $input['weekday'] ?? null;
        if ($frequency === 'weekly') {
            if ($weekday === null || $weekday === '') {
                $weekday = (int) $firstDueAt->format('N');
            }
            $weekday = (int) $weekday;
            if ($weekday < 1 || $weekday > 7) {
                respondReminders(false, null, 'Weekday non valido', 422);
            }
        } else {
            $weekday = null;
        }

        $intervalValue = (int) ($input['interval_value'] ?? 1);
        if ($intervalValue < 1) {
            $intervalValue = 1;
        }

        try {
            $therapy = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
            if (!$therapy) {
                respondReminders(false, null, 'Terapia non trovata per la farmacia', 400);
            }

            db_query(
                "INSERT INTO jta_therapy_reminders (therapy_id, title, description, frequency, interval_value, weekday, first_due_at, next_due_at, status) VALUES (?,?,?,?,?,?,?,?,?)",
                [
                    $therapy_id,
                    sanitize($title),
                    isset($input['description']) && trim((string) $input['description']) !== '' ? sanitize($input['description']) : null,
                    $frequency,
                    $intervalValue,
                    $weekday,
                    $firstDueAt->format('Y-m-d H:i:s'),
                    $firstDueAt->format('Y-m-d H:i:s'),
                    'active'
                ]
            );
            $reminder_id = db()->getConnection()->lastInsertId();
            $reminder = db_fetch_one(
                "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                [$reminder_id, $pharmacy_id]
            );
            respondReminders(true, ['item' => $reminder]);
        } catch (Exception $e) {
            $logTherapyId = $therapy_id ?? 'null';
            $logScheduledRaw = $firstDueRaw ?? 'null';
            error_log(
                "reminders.php ERROR pharmacy={$pharmacy_id} therapy={$logTherapyId} first_due_raw={$logScheduledRaw}: " . $e->getMessage()
            );
            $details = ['message' => $e->getMessage()];
            if ($e->getCode()) {
                $details['code'] = $e->getCode();
            }
            respondReminders(false, null, 'Errore creazione promemoria', 500, [
                'details' => $details
            ]);
        }
        break;

    case 'PUT':
    case 'PATCH':
        $reminder_id = $_GET['id'] ?? null;
        if (!$reminder_id) {
            respondReminders(false, null, 'id promemoria richiesto', 400);
        }

        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true);
        if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
            respondReminders(false, null, 'JSON non valido', 400);
        }
        if (!is_array($input)) {
            respondReminders(false, null, 'JSON non valido', 400);
        }
        $therapy_id = $input['therapy_id'] ?? null;
        $title = trim((string) ($input['title'] ?? ''));
        $frequency = normalizeReminderFrequency($input['frequency'] ?? null);
        $firstDueRaw = $input['first_due_at'] ?? null;
        if (!$therapy_id || $title === '' || !$frequency || !$firstDueRaw) {
            respondReminders(false, null, 'Campi obbligatori mancanti', 400);
        }

        $allowedFrequencies = ['one_shot', 'weekly', 'biweekly', 'monthly'];
        if (!in_array($frequency, $allowedFrequencies, true)) {
            respondReminders(false, null, 'Tipo promemoria non valido', 422);
        }

        $timezone = new DateTimeZone('Europe/Rome');
        $firstDueAt = parseReminderDateTime($firstDueRaw, $timezone);
        if (!$firstDueAt) {
            respondReminders(false, null, 'Formato data/ora non valido', 422, [
                'code' => 'REMINDER_INVALID_DATETIME',
                'message' => 'Formato data/ora non valido.',
                'details' => ['first_due_at' => $firstDueRaw]
            ]);
        }

        $weekday = $input['weekday'] ?? null;
        if ($frequency === 'weekly') {
            if ($weekday === null || $weekday === '') {
                $weekday = (int) $firstDueAt->format('N');
            }
            $weekday = (int) $weekday;
            if ($weekday < 1 || $weekday > 7) {
                respondReminders(false, null, 'Weekday non valido', 422);
            }
        } else {
            $weekday = null;
        }

        $intervalValue = (int) ($input['interval_value'] ?? 1);
        if ($intervalValue < 1) {
            $intervalValue = 1;
        }

        try {
            $reminder = db_fetch_one(
                "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                [$reminder_id, $pharmacy_id]
            );
            if (!$reminder) {
                respondReminders(false, null, 'Promemoria non trovato', 404);
            }

            $therapy = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
            if (!$therapy) {
                respondReminders(false, null, 'Terapia non trovata per la farmacia', 400);
            }

            $firstDueFormatted = $firstDueAt->format('Y-m-d H:i:s');
            $existingFirstDue = $reminder['first_due_at'] ?? null;
            $nextDueAtValue = $firstDueFormatted;
            if ($existingFirstDue && $existingFirstDue === $firstDueFormatted) {
                $nextDueAtValue = $reminder['next_due_at'] ?? $firstDueFormatted;
            }

            db_query(
                "UPDATE jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id SET r.therapy_id = ?, r.title = ?, r.description = ?, r.frequency = ?, r.interval_value = ?, r.weekday = ?, r.first_due_at = ?, r.next_due_at = ? WHERE r.id = ? AND t.pharmacy_id = ?",
                [
                    $therapy_id,
                    sanitize($title),
                    isset($input['description']) && trim((string) $input['description']) !== '' ? sanitize($input['description']) : null,
                    $frequency,
                    $intervalValue,
                    $weekday,
                    $firstDueFormatted,
                    $nextDueAtValue,
                    $reminder_id,
                    $pharmacy_id
                ]
            );

            $updated = db_fetch_one(
                "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                [$reminder_id, $pharmacy_id]
            );
            respondReminders(true, ['item' => $updated]);
        } catch (Exception $e) {
            $logTherapyId = $therapy_id ?? 'null';
            error_log(
                "reminders.php ERROR pharmacy={$pharmacy_id} therapy={$logTherapyId} update: " . $e->getMessage()
            );
            $details = ['message' => $e->getMessage()];
            if ($e->getCode()) {
                $details['code'] = $e->getCode();
            }
            respondReminders(false, null, 'Errore aggiornamento promemoria', 500, [
                'details' => $details
            ]);
        }
        break;

    case 'DELETE':
        $reminder_id = $_GET['id'] ?? null;
        if (!$reminder_id) {
            respondReminders(false, null, 'id promemoria richiesto', 400);
        }

        try {
            $reminder = db_fetch_one(
                "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                [$reminder_id, $pharmacy_id]
            );

            if (!$reminder) {
                respondReminders(false, null, 'Promemoria non trovato', 404);
            }

            db_query(
                "UPDATE jta_therapy_reminders SET status = 'cancelled' WHERE id = ? AND therapy_id IN (SELECT id FROM jta_therapies WHERE pharmacy_id = ?)",
                [$reminder_id, $pharmacy_id]
            );

            $updated = db_fetch_one(
                "SELECT r.* FROM jta_therapy_reminders r JOIN jta_therapies t ON r.therapy_id = t.id WHERE r.id = ? AND t.pharmacy_id = ?",
                [$reminder_id, $pharmacy_id]
            );

            respondReminders(true, ['item' => $updated]);
        } catch (Exception $e) {
            $logTherapyId = isset($reminder['therapy_id']) ? $reminder['therapy_id'] : 'null';
            error_log(
                "reminders.php ERROR pharmacy={$pharmacy_id} therapy={$logTherapyId} delete: " . $e->getMessage()
            );
            $details = ['message' => $e->getMessage()];
            if ($e->getCode()) {
                $details['code'] = $e->getCode();
            }
            respondReminders(false, null, 'Errore eliminazione promemoria', 500, [
                'details' => $details
            ]);
        }
        break;

    default:
        respondReminders(false, null, 'Metodo non consentito', 405);
}
