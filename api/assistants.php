<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

header('Content-Type: application/json');
requireApiAuth(['admin', 'pharmacist']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function respondAssistants($success, $data = null, $error = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
if (!$pharmacy_id) {
    respondAssistants(false, null, 'Farmacia non disponibile', 400);
}

switch ($method) {
    case 'GET':
        $q = sanitize($_GET['q'] ?? '');
        $params = [$pharmacy_id];
        $where = 'pharma_id = ?';
        if ($q) {
            $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like);
        }
        $sql = "SELECT * FROM jta_assistants WHERE $where ORDER BY last_name, first_name";
        try {
            $rows = db_fetch_all($sql, $params);
            respondAssistants(true, ['items' => $rows]);
        } catch (Exception $e) {
            respondAssistants(false, null, 'Errore recupero assistenti', 500);
        }
        break;

    case 'POST':
        $input = sanitize(json_decode(file_get_contents('php://input'), true) ?? []);
        if (empty($input['first_name'])) {
            respondAssistants(false, null, 'Nome assistente obbligatorio', 400);
        }
        try {
            db_query(
                "INSERT INTO jta_assistants (pharma_id, first_name, last_name, phone, email, type, relation_to_patient, preferred_contact, notes) VALUES (?,?,?,?,?,?,?,?,?)",
                [
                    $pharmacy_id,
                    $input['first_name'],
                    $input['last_name'] ?? null,
                    $input['phone'] ?? null,
                    $input['email'] ?? null,
                    $input['type'] ?? 'familiare',
                    $input['relation_to_patient'] ?? null,
                    $input['preferred_contact'] ?? null,
                    $input['notes'] ?? null
                ]
            );
            $assistant_id = db()->getConnection()->lastInsertId();
            respondAssistants(true, ['assistant_id' => $assistant_id]);
        } catch (Exception $e) {
            respondAssistants(false, null, 'Errore creazione assistente', 500);
        }
        break;

    case 'PUT':
        $input = sanitize(json_decode(file_get_contents('php://input'), true) ?? []);
        $assistant_id = $input['id'] ?? null;
        if (!$assistant_id) {
            respondAssistants(false, null, 'ID assistente mancante', 400);
        }
        try {
            db_query(
                "UPDATE jta_assistants SET first_name = ?, last_name = ?, phone = ?, email = ?, type = ?, relation_to_patient = ?, preferred_contact = ?, notes = ?, updated_at = NOW() WHERE id = ? AND pharma_id = ?",
                [
                    $input['first_name'] ?? '',
                    $input['last_name'] ?? null,
                    $input['phone'] ?? null,
                    $input['email'] ?? null,
                    $input['type'] ?? 'familiare',
                    $input['relation_to_patient'] ?? null,
                    $input['preferred_contact'] ?? null,
                    $input['notes'] ?? null,
                    $assistant_id,
                    $pharmacy_id
                ]
            );
            respondAssistants(true, ['assistant_id' => $assistant_id]);
        } catch (Exception $e) {
            respondAssistants(false, null, 'Errore aggiornamento assistente', 500);
        }
        break;

    default:
        respondAssistants(false, null, 'Metodo non consentito', 405);
}
?>
