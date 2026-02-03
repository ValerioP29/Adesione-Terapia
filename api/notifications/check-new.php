<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth_middleware.php';

// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'data' => null, 'error' => 'Non autorizzato']);
    exit;
}

$pharmacy_id = get_panel_pharma_id(true);

try {
    // Usa la classe Database esistente
    $db = db();

    // Ottieni l'ID dell'ultima richiesta vista dall'utente
    $last_seen = isset($_GET['last_seen']) ? (int)$_GET['last_seen'] : 0;

    // Controlla se ci sono nuove richieste usando la stessa struttura delle altre API
    $result = $db->fetchOne("
        SELECT COUNT(*) as new_count, MAX(id) as latest_id
        FROM jta_requests
        WHERE id > ? AND deleted_at IS NULL AND pharma_id = ?
    ", [$last_seen, $pharmacy_id]);

    $new_count = (int)$result['new_count'];
    $latest_id = (int)$result['latest_id'];

    // Se ci sono nuove richieste, ottieni i dettagli
    $new_requests = [];
    if ($new_count > 0) {
        $requests = $db->fetchAll("
            SELECT r.id, r.request_type, r.status, r.created_at, r.message,
                   CONCAT(u.name, ' ', u.surname) as customer_name,
                   u.phone_number as customer_phone,
                   p.business_name as pharmacy_name
            FROM jta_requests r
            LEFT JOIN jta_users u ON r.user_id = u.id
            LEFT JOIN jta_pharmas p ON r.pharma_id = p.id
            WHERE r.id > ? AND r.deleted_at IS NULL AND r.pharma_id = ?
            ORDER BY r.created_at DESC
            LIMIT 10
        ", [$last_seen, $pharmacy_id]);

        // Formatta le richieste come nelle altre API
        foreach ($requests as $request) {
            $new_requests[] = [
                'id' => $request['id'],
                'customer_name' => $request['customer_name'],
                'customer_phone' => $request['customer_phone'],
                'status' => $request['status'],
                'created_at' => $request['created_at'],
                'product_name' => $request['request_type'], // Usa il tipo di richiesta come "prodotto"
                'pharmacy_name' => $request['pharmacy_name']
            ];
        }
    }

    // Recupera promemoria in scadenza per la farmacia
    $due_reminders = $db->fetchAll("
        SELECT r.id, r.title, r.message, r.type, r.channel, r.scheduled_at, r.therapy_id, t.therapy_title,
               p.first_name, p.last_name
        FROM jta_therapy_reminders r
        JOIN jta_therapies t ON r.therapy_id = t.id
        LEFT JOIN jta_patients p ON t.patient_id = p.id
        WHERE r.status = 'scheduled' AND r.scheduled_at <= NOW() AND t.pharmacy_id = ?
        ORDER BY r.scheduled_at ASC
        LIMIT 20
    ", [$pharmacy_id]);

    $reminders = [];
    $recurringTypes = ['daily', 'weekly', 'monthly'];
    if (!empty($due_reminders)) {
        foreach ($due_reminders as $reminder) {
            $patient_name = trim(($reminder['first_name'] ?? '') . ' ' . ($reminder['last_name'] ?? ''));
            $reminders[] = [
                'id' => $reminder['id'],
                'title' => $reminder['title'],
                'message' => $reminder['message'],
                'scheduled_at' => $reminder['scheduled_at'],
                'therapy_id' => $reminder['therapy_id'],
                'therapy_title' => $reminder['therapy_title'],
                'patient_name' => $patient_name,
            ];

            if (function_exists('logActivity')) {
                logActivity('therapy_reminder_shown', [
                    'reminder_id' => $reminder['id'],
                    'therapy_id' => $reminder['therapy_id'],
                    'scheduled_at' => $reminder['scheduled_at']
                ]);
            }

            if (in_array($reminder['type'] ?? 'one-shot', $recurringTypes, true)) {
                $scheduledAt = $reminder['scheduled_at'] ?? null;
                if ($scheduledAt) {
                    $nextScheduledAt = null;
                    try {
                        $dt = new DateTime($scheduledAt);
                        switch ($reminder['type']) {
                            case 'daily':
                                $dt->modify('+1 day');
                                break;
                            case 'weekly':
                                $dt->modify('+1 week');
                                break;
                            case 'monthly':
                                $dt->modify('+1 month');
                                break;
                            default:
                                break;
                        }
                        $nextScheduledAt = $dt->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $nextScheduledAt = null;
                    }

                    if ($nextScheduledAt) {
                        $existingNext = $db->fetchOne(
                            "SELECT id FROM jta_therapy_reminders WHERE therapy_id = ? AND title = ? AND type = ? AND scheduled_at = ? AND status IN ('scheduled', 'shown') LIMIT 1",
                            [$reminder['therapy_id'], $reminder['title'], $reminder['type'], $nextScheduledAt]
                        );

                        if (!$existingNext) {
                            db_query(
                                "INSERT INTO jta_therapy_reminders (therapy_id, title, message, type, scheduled_at, channel, status) VALUES (?,?,?,?,?,?,?)",
                                [
                                    $reminder['therapy_id'],
                                    $reminder['title'],
                                    $reminder['message'] ?? '',
                                    $reminder['type'],
                                    $nextScheduledAt,
                                    $reminder['channel'] ?? 'email',
                                    'scheduled'
                                ]
                            );

                            if (function_exists('logActivity')) {
                                logActivity('therapy_reminder_recurring_created', [
                                    'therapy_id' => $reminder['therapy_id'],
                                    'source_reminder_id' => $reminder['id'],
                                    'next_scheduled_at' => $nextScheduledAt
                                ]);
                            }
                        }
                    }
                }
            }
        }

        // Marca come mostrati per evitare duplicati
        $ids = array_column($due_reminders, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db_query("UPDATE jta_therapy_reminders SET status = 'shown' WHERE id IN ($placeholders)", $ids);
    }

    echo json_encode([
        'success' => true,
        'new_count' => $new_count,
        'latest_id' => $latest_id,
        'new_requests' => $new_requests,
        'reminders_count' => count($reminders),
        'reminders' => $reminders,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => null, 'error' => 'Errore: ' . $e->getMessage()]);
}
