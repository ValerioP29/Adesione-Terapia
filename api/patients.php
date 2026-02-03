<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

header('Content-Type: application/json');
requireApiAuth(['admin', 'pharmacist']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function respondPatients($success, $data = null, $error = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
if (!$pharmacy_id) {
    respondPatients(false, null, 'Farmacia non disponibile', 400);
}

switch ($method) {
    case 'GET':
        $q = sanitize($_GET['q'] ?? '');
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $params = [$pharmacy_id];
        $where = 'pp.pharma_id = ? AND p.id = pp.patient_id AND pp.deleted_at IS NULL';
        if ($q) {
            $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.codice_fiscale LIKE ?)";
            $like = "%$q%";
            array_push($params, $like, $like, $like);
        }
        $sql = "SELECT p.* FROM jta_patients p JOIN jta_pharma_patient pp ON p.id = pp.patient_id WHERE $where ORDER BY p.last_name, p.first_name LIMIT $limit";
        try {
            $rows = db_fetch_all($sql, $params);
            respondPatients(true, ['items' => $rows]);
        } catch (Exception $e) {
            respondPatients(false, null, 'Errore recupero pazienti', 500);
        }
        break;

    case 'POST':
        $input = sanitize(json_decode(file_get_contents('php://input'), true) ?? []);
        if (empty($input['first_name']) || empty($input['last_name'])) {
            respondPatients(false, null, 'Nome e cognome obbligatori', 400);
        }
        try {
            db_query(
                "INSERT INTO jta_patients (pharmacy_id, first_name, last_name, birth_date, codice_fiscale, phone, email, notes) VALUES (?,?,?,?,?,?,?,?)",
                [
                    $pharmacy_id,
                    $input['first_name'],
                    $input['last_name'],
                    $input['birth_date'] ?? null,
                    $input['codice_fiscale'] ?? null,
                    $input['phone'] ?? null,
                    $input['email'] ?? null,
                    $input['notes'] ?? null
                ]
            );
            $patient_id = db()->getConnection()->lastInsertId();
            db_query("INSERT INTO jta_pharma_patient (pharma_id, patient_id) VALUES (?, ?)", [$pharmacy_id, $patient_id]);
            respondPatients(true, ['patient_id' => $patient_id]);
        } catch (Exception $e) {
            respondPatients(false, null, 'Errore creazione paziente', 500);
        }
        break;

    case 'PUT':
        $input = sanitize(json_decode(file_get_contents('php://input'), true) ?? []);
        $patient_id = $input['id'] ?? null;
        if (!$patient_id) {
            respondPatients(false, null, 'ID paziente mancante', 400);
        }
        try {
            db_query(
                "UPDATE jta_patients SET first_name = ?, last_name = ?, birth_date = ?, codice_fiscale = ?, phone = ?, email = ?, notes = ?, updated_at = NOW() WHERE id = ? AND pharmacy_id = ?",
                [
                    $input['first_name'] ?? '',
                    $input['last_name'] ?? '',
                    $input['birth_date'] ?? null,
                    $input['codice_fiscale'] ?? null,
                    $input['phone'] ?? null,
                    $input['email'] ?? null,
                    $input['notes'] ?? null,
                    $patient_id,
                    $pharmacy_id
                ]
            );
            respondPatients(true, ['patient_id' => $patient_id]);
        } catch (Exception $e) {
            respondPatients(false, null, 'Errore aggiornamento paziente', 500);
        }
        break;

    default:
        respondPatients(false, null, 'Metodo non consentito', 405);
}
?>
