<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth_middleware.php';

date_default_timezone_set('Europe/Rome');

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

    echo json_encode([
        'success' => true,
        'new_count' => $new_count,
        'latest_id' => $latest_id,
        'new_requests' => $new_requests,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => null, 'error' => 'Errore: ' . $e->getMessage()]);
}
