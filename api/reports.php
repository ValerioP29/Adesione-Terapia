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
        error_log('reports.php fatal error: ' . json_encode($err));
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$publicToken = $_GET['token'] ?? null;
$action = $_GET['action'] ?? null;

if (!$publicToken) {
    requireApiAuth(['admin', 'pharmacist']);
}

function respondReports($success, $data = null, $error = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

function isDompdfAvailable() {
    if (!class_exists('Dompdf\\Dompdf')) {
        $paths = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../vendor/dompdf/autoload.inc.php'];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
    }

    return class_exists('Dompdf\\Dompdf');
}

function loadTherapyReportData($therapy_id, $pharmacy_id) {
    try {
        $therapy = db_fetch_one(
            "SELECT t.*, p.first_name AS patient_first_name, p.last_name AS patient_last_name, p.codice_fiscale, p.birth_date, p.phone, p.email,
                    ph.nice_name AS pharmacy_name, ph.business_name, ph.address AS pharmacy_address, ph.city AS pharmacy_city, ph.email AS pharmacy_email, ph.phone_number AS pharmacy_phone,
                    tcc.primary_condition, tcc.general_anamnesis, tcc.detailed_intake, tcc.adherence_base, tcc.risk_score AS chronic_risk_score, tcc.flags, tcc.notes_initial, tcc.follow_up_date AS chronic_follow_up_date
             FROM jta_therapies t
             JOIN jta_patients p ON t.patient_id = p.id
             JOIN jta_pharmas ph ON t.pharmacy_id = ph.id
             LEFT JOIN jta_therapy_chronic_care tcc ON t.id = tcc.therapy_id
             WHERE t.id = ? AND t.pharmacy_id = ?",
            [$therapy_id, $pharmacy_id]
        );
    } catch (Exception $e) {
        respondReports(false, null, 'Errore caricamento dati terapia', 500);
    }

    if (!$therapy) {
        return null;
    }

    try {
        $baseSurvey = db_fetch_one(
            "SELECT answers, compiled_at FROM jta_therapy_condition_surveys WHERE therapy_id = ? AND level = 'base' ORDER BY compiled_at DESC LIMIT 1",
            [$therapy_id]
        );
    } catch (Exception $e) {
        respondReports(false, null, 'Errore caricamento questionario', 500);
    }

    $surveyAnswers = $baseSurvey ? json_decode($baseSurvey['answers'] ?? '', true) : null;

    $chronicCare = [
        'condition' => $therapy['primary_condition'] ?? null,
        'general_anamnesis' => $therapy['general_anamnesis'] ? json_decode($therapy['general_anamnesis'], true) : null,
        'detailed_intake' => $therapy['detailed_intake'] ? json_decode($therapy['detailed_intake'], true) : null,
        'adherence_base' => $therapy['adherence_base'] ? json_decode($therapy['adherence_base'], true) : null,
        'risk_score' => $therapy['chronic_risk_score'] ?? null,
        'flags' => $therapy['flags'] ? json_decode($therapy['flags'], true) : null,
        'notes_initial' => $therapy['notes_initial'] ?? null,
        'follow_up_date' => $therapy['chronic_follow_up_date'] ?? null
    ];

    return [
        'therapy' => $therapy,
        'survey' => [
            'answers' => $surveyAnswers,
            'compiled_at' => $baseSurvey['compiled_at'] ?? null
        ],
        'chronic_care' => $chronicCare
    ];
}

function renderTherapyReportPdf($html, $storageDir, $filename) {
    if (!isDompdfAvailable()) {
        respondReports(false, null, 'Libreria DomPDF non disponibile', 500);
    }

    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true)) {
        respondReports(false, null, 'Impossibile creare la cartella di salvataggio', 500);
    }

    $pdfPath = rtrim($storageDir, '/\\') . '/' . $filename;

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    file_put_contents($pdfPath, $dompdf->output());

    return $pdfPath;
}

function buildReportHtml($data) {
    $templatePath = __DIR__ . '/../includes/pdf_templates/therapy_report.php';
    if (!file_exists($templatePath)) {
        respondReports(false, null, 'Template PDF mancante', 500);
    }

    ob_start();
    $reportData = $data;
    include $templatePath;
    return ob_get_clean();
}

function fetchFollowupsForReport($therapy_id, $pharmacy_id, $mode, $followup_id = null)
{
    $followupParams = [$therapy_id, $pharmacy_id];
    $followupSql = "SELECT f.* FROM jta_therapy_followups f JOIN jta_therapies t ON f.therapy_id = t.id WHERE f.therapy_id = ? AND t.pharmacy_id = ?";
    if ($mode === 'single') {
        $followupSql .= " AND f.id = ?";
        $followupParams[] = $followup_id;
    }
    $followupSql .= " ORDER BY f.created_at DESC";

    try {
        $followups = db_fetch_all($followupSql, $followupParams);
    } catch (Exception $e) {
        respondReports(false, null, 'Errore recupero follow-up', 500);
    }

    return array_map(function ($row) {
        $snapshot = json_decode($row['snapshot'] ?? '', true);
        return [
            'id' => $row['id'],
            'follow_up_date' => $row['follow_up_date'] ?? null,
            'risk_score' => $row['risk_score'] ?? null,
            'entry_type' => $row['entry_type'] ?? null,
            'check_type' => $row['check_type'] ?? null,
            'snapshot' => $snapshot,
            'pharmacist_notes' => $row['pharmacist_notes'] ?? null,
            'created_at' => $row['created_at'] ?? null
        ];
    }, $followups);
}

function fetchChecklistAnswersForFollowups(array $followupIds)
{
    if (!$followupIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($followupIds), '?'));
    try {
        $rows = db_fetch_all(
            "SELECT a.followup_id, q.id AS question_id, q.question_text, q.question_key, q.input_type, q.options_json, q.is_active, a.answer_value
             FROM jta_therapy_checklist_answers a
             JOIN jta_therapy_checklist_questions q ON a.question_id = q.id
             WHERE a.followup_id IN ($placeholders)
             ORDER BY q.sort_order ASC, q.id ASC",
            $followupIds
        );
    } catch (Exception $e) {
        respondReports(false, null, 'Errore recupero risposte checklist', 500);
    }

    $grouped = [];
    foreach ($rows as $row) {
        $answer = $row['answer_value'];
        $grouped[$row['followup_id']][] = [
            'id' => $row['question_id'],
            'key' => $row['question_key'],
            'text' => $row['question_text'],
            'input_type' => $row['input_type'],
            'options' => $row['options_json'] ? json_decode($row['options_json'], true) : null,
            'answer' => $answer
        ];
    }
    return $grouped;
}

function fetchCaregiversForReport($therapy_id)
{
    try {
        return db_fetch_all(
            "SELECT a.id, a.first_name, a.last_name, a.phone, a.email, a.type, a.relation_to_patient, ta.role, ta.contact_channel
             FROM jta_therapy_assistant ta
             JOIN jta_assistants a ON ta.assistant_id = a.id
             WHERE ta.therapy_id = ?
             ORDER BY a.last_name ASC, a.first_name ASC",
            [$therapy_id]
        );
    } catch (Exception $e) {
        respondReports(false, null, 'Errore recupero caregiver', 500);
    }
}

function buildReportContent($therapy_id, $pharmacy_id, $mode, $followup_id = null)
{
    if (!$therapy_id || !in_array($mode, ['all', 'single'], true)) {
        respondReports(false, null, 'Parametri mancanti o non validi', 400);
    }

    if ($mode === 'single' && !$followup_id) {
        respondReports(false, null, 'followup_id richiesto per la modalità singola', 400);
    }

    $therapyData = loadTherapyReportData($therapy_id, $pharmacy_id);
    if (!$therapyData) {
        respondReports(false, null, 'Terapia non trovata', 404);
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        try {
            $user = db_fetch_one('SELECT id, name, surname, slug_name, email FROM jta_users WHERE id = ?', [$userId]);
        } catch (Exception $e) {
            respondReports(false, null, 'Errore recupero utente', 500);
        }
    } else {
        $user = null;
    }
    $pharmacistName = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''));
    if (!$pharmacistName) {
        $pharmacistName = $user['slug_name'] ?? ($_SESSION['user_name'] ?? '');
    }

    $followupData = fetchFollowupsForReport($therapy_id, $pharmacy_id, $mode, $followup_id);
    $followupIds = array_map(function ($row) {
        return $row['id'];
    }, $followupData);
    $checklistAnswers = fetchChecklistAnswersForFollowups($followupIds);
    $checkFollowups = array_values(array_filter($followupData, function ($row) {
        $type = $row['entry_type'] ?? null;
        $snapshot = $row['snapshot'] ?? null;
        $hasSnapshot = is_array($snapshot) && !empty($snapshot);
        if ($type === 'check') {
            return true;
        }
        return $type === null && $hasSnapshot;
    }));
    $manualFollowups = array_values(array_filter($followupData, function ($row) {
        $type = $row['entry_type'] ?? null;
        $snapshot = $row['snapshot'] ?? null;
        $hasSnapshot = is_array($snapshot) && !empty($snapshot);
        if ($type === 'followup') {
            return true;
        }
        return $type === null && !$hasSnapshot;
    }));

    $therapy = $therapyData['therapy'];
    $checkFollowups = array_map(function ($row) use ($checklistAnswers) {
        if (isset($checklistAnswers[$row['id']])) {
            $row['questions'] = $checklistAnswers[$row['id']];
        }
        return $row;
    }, $checkFollowups);

    $caregivers = fetchCaregiversForReport($therapy_id);

    $reportContent = [
        'generated_at' => date('Y-m-d H:i'),
        'mode' => $mode,
        'pharmacy' => [
            'id' => $therapy['pharmacy_id'],
            'name' => $therapy['pharmacy_name'] ?? $therapy['business_name'] ?? '',
            'address' => $therapy['pharmacy_address'] ?? '',
            'city' => $therapy['pharmacy_city'] ?? '',
            'email' => $therapy['pharmacy_email'] ?? '',
            'phone' => $therapy['pharmacy_phone'] ?? ''
        ],
        'pharmacist' => [
            'id' => $userId,
            'name' => $pharmacistName,
            'email' => $user['email'] ?? null
        ],
        'patient' => [
            'id' => $therapy['patient_id'],
            'first_name' => $therapy['patient_first_name'] ?? null,
            'last_name' => $therapy['patient_last_name'] ?? null,
            'codice_fiscale' => $therapy['codice_fiscale'] ?? null,
            'birth_date' => $therapy['birth_date'] ?? null,
            'phone' => $therapy['phone'] ?? null,
            'email' => $therapy['email'] ?? null
        ],
        'caregivers' => $caregivers,
        'therapy' => [
            'id' => $therapy['id'],
            'title' => $therapy['therapy_title'] ?? null,
            'description' => $therapy['therapy_description'] ?? null,
            'status' => $therapy['status'] ?? null,
            'start_date' => $therapy['start_date'] ?? null,
            'end_date' => $therapy['end_date'] ?? null
        ],
        'chronic_care' => $therapyData['chronic_care'],
        'survey_base' => $therapyData['survey'],
        'followups' => $followupData,
        'check_followups' => $checkFollowups,
        'manual_followups' => $manualFollowups
    ];

    return $reportContent;
}

switch ($method) {
    case 'GET':
        if ($action === 'pdf') {
            $pharmacy_id = get_panel_pharma_id(true);
            $therapy_id = $_GET['therapy_id'] ?? null;
            $mode = $_GET['mode'] ?? 'all';
            $followup_id = $_GET['followup_id'] ?? null;

            if (!$therapy_id) {
                respondReports(false, null, 'therapy_id mancante', 400);
            }
            if (!in_array($mode, ['all', 'single'], true)) {
                respondReports(false, null, 'modalità non valida', 400);
            }
            if ($mode === 'single' && !$followup_id) {
                respondReports(false, null, 'followup_id richiesto per la modalità singola', 400);
            }

            $reportContent = buildReportContent($therapy_id, $pharmacy_id, $mode, $followup_id);
            $templatePath = __DIR__ . '/../includes/pdf_templates/therapy_report.php';
            if (!file_exists($templatePath)) {
                respondReports(false, null, 'Template PDF mancante', 500);
            }

            ob_start();
            $reportData = $reportContent;
            include $templatePath;
            $html = ob_get_clean();

            if (!isDompdfAvailable()) {
                respondReports(false, null, 'Libreria DomPDF non disponibile', 500);
            }

            $dompdf = new Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdfOutput = $dompdf->output();

            $date = date('Ymd');
            $filename = "report_therapy_{$therapy_id}_{$date}.pdf";
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $pdfOutput;
            exit;
        }

        if ($action === 'preview') {
            $pharmacy_id = get_panel_pharma_id(true);
            $therapy_id = $_GET['therapy_id'] ?? null;
            $mode = $_GET['mode'] ?? 'all';
            $followup_id = $_GET['followup_id'] ?? null;

            $reportContent = buildReportContent($therapy_id, $pharmacy_id, $mode, $followup_id);
            $html = buildReportHtml($reportContent);
            $pdfAvailable = isDompdfAvailable();

            respondReports(true, [
                'content' => $reportContent,
                'html' => $html,
                'pdf_available' => $pdfAvailable
            ]);
        }

        if ($action === 'generate') {
            $pharmacy_id = get_panel_pharma_id(true);
            $therapy_id = $_GET['therapy_id'] ?? null;
            $mode = $_GET['mode'] ?? 'all';
            $followup_id = $_GET['followup_id'] ?? null;

            $reportContent = buildReportContent($therapy_id, $pharmacy_id, $mode, $followup_id);
            $storageDir = __DIR__ . '/../storage/therapy_reports';
            $shareToken = bin2hex(random_bytes(16));
            $pdfAvailable = isDompdfAvailable();

            try {
                db_query(
                    "INSERT INTO jta_therapy_reports (therapy_id, pharmacy_id, content, share_token, pin_code, valid_until, recipients) VALUES (?,?,?,?,?,?,?)",
                    [
                        $therapy_id,
                        $pharmacy_id,
                        json_encode($reportContent),
                        $shareToken,
                        null,
                        null,
                        null
                    ]
                );
                $report_id = db()->getConnection()->lastInsertId();
            } catch (Exception $e) {
                respondReports(false, null, 'Errore creazione record report', 500);
            }

            $reportContent['share_token'] = $shareToken;
            $reportContent['report_id'] = $report_id;
            $reportContent['pdf_path'] = null;
            $html = buildReportHtml($reportContent);

            if ($pdfAvailable) {
                $reportContent['pdf_path'] = '/storage/therapy_reports/report_' . $report_id . '.pdf';
                $fullPdfPath = renderTherapyReportPdf($html, $storageDir, 'report_' . $report_id . '.pdf');
            }

            try {
                db_query(
                    "UPDATE jta_therapy_reports SET content = ? WHERE id = ?",
                    [json_encode($reportContent), $report_id]
                );
            } catch (Exception $e) {
                respondReports(false, null, 'Errore aggiornamento report', 500);
            }

            $responseData = [
                'pdf_url' => $reportContent['pdf_path'],
                'report_id' => $report_id,
                'content' => $reportContent,
                'html' => $html,
                'pdf_available' => $pdfAvailable
            ];

            if (!$pdfAvailable) {
                respondReports(true, $responseData, 'PDF non disponibile', 200);
            }

            respondReports(true, $responseData);
        }

        if ($publicToken) {
            try {
                $report = db_fetch_one("SELECT * FROM jta_therapy_reports WHERE share_token = ? AND (valid_until IS NULL OR valid_until >= NOW())", [$publicToken]);
            } catch (Exception $e) {
                respondReports(false, null, 'Errore recupero report', 500);
            }
            if (!$report) {
                respondReports(false, null, 'Report non trovato', 404);
            }
            $report['content'] = json_decode($report['content'] ?? '', true);
            $report['pdf_url'] = $report['content']['pdf_path'] ?? null;
            respondReports(true, ['report' => $report]);
        }

        $pharmacy_id = get_panel_pharma_id(true);
        $therapy_id = $_GET['therapy_id'] ?? null;
        if (!$therapy_id) {
            respondReports(false, null, 'therapy_id o farmacia mancanti', 400);
        }
        $sql = "SELECT * FROM jta_therapy_reports WHERE therapy_id = ? AND pharmacy_id = ? ORDER BY created_at DESC";
        try {
            $rows = db_fetch_all($sql, [$therapy_id, $pharmacy_id]);
            foreach ($rows as &$row) {
                $row['content'] = json_decode($row['content'] ?? '', true);
                $row['pdf_url'] = $row['content']['pdf_path'] ?? null;
            }
            respondReports(true, ['items' => $rows]);
        } catch (Exception $e) {
            respondReports(false, null, 'Errore recupero report', 500);
        }
        break;

    case 'POST':
        $pharmacy_id = get_panel_pharma_id(true);
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $therapy_id = $input['therapy_id'] ?? null;
        if (!$therapy_id) {
            respondReports(false, null, 'therapy_id o farmacia mancanti', 400);
        }
        try {
            $therapy = db_fetch_one("SELECT id FROM jta_therapies WHERE id = ? AND pharmacy_id = ?", [$therapy_id, $pharmacy_id]);
        } catch (Exception $e) {
            respondReports(false, null, 'Errore verifica terapia', 500);
        }
        if (!$therapy) {
            respondReports(false, null, 'Terapia non trovata per la farmacia', 404);
        }
        $content = $input['content'] ?? [];
        try {
            db_query(
                "INSERT INTO jta_therapy_reports (therapy_id, pharmacy_id, content, share_token, pin_code, valid_until, recipients) VALUES (?,?,?,?,?,?,?)",
                [
                    $therapy_id,
                    $pharmacy_id,
                    json_encode($content),
                    $input['share_token'] ?? null,
                    $input['pin_code'] ?? null,
                    $input['valid_until'] ?? null,
                    $input['recipients'] ?? null
                ]
            );
            $report_id = db()->getConnection()->lastInsertId();
            respondReports(true, ['report_id' => $report_id]);
        } catch (Exception $e) {
            respondReports(false, null, 'Errore creazione report', 500);
        }
        break;

    default:
        respondReports(false, null, 'Metodo non consentito', 405);
}
