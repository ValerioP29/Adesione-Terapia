<?php
if (!isset($reportData) || !is_array($reportData)) {
    return;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatAnswer($value) {
    if ($value === null || $value === '') {
        return '-';
    }
    if (is_array($value) || is_object($value)) {
        if (is_array($value) && array_values($value) === $value) {
            return implode(', ', array_map('strval', $value));
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    if (is_bool($value)) {
        return $value ? 'Sì' : 'No';
    }
    if ($value === 'true' || $value === '1' || $value === 1) {
        return 'Sì';
    }
    if ($value === 'false' || $value === '0' || $value === 0) {
        return 'No';
    }
    return $value;
}

function formatRomeDate($value, $format = 'd/m/Y') {
    if (!$value) {
        return '-';
    }
    try {
        $tz = new DateTimeZone('Europe/Rome');
        $date = new DateTime($value, $tz);
        return $date->format($format);
    } catch (Exception $e) {
        return $value;
    }
}

function renderKeyValue($arr) {
    if (!$arr || !is_array($arr) || count($arr) === 0) {
        return '-';
    }

    $items = [];
    foreach ($arr as $key => $value) {
        if (is_array($value)) {
            $rendered = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $rendered = $value ? 'Sì' : 'No';
        } elseif ($value === null || $value === '') {
            $rendered = '-';
        } else {
            $rendered = (string)$value;
        }

        if (is_string($rendered)) {
            $len = function_exists('mb_strlen') ? mb_strlen($rendered) : strlen($rendered);
            if ($len > 200) {
                $cut = function_exists('mb_substr') ? mb_substr($rendered, 0, 200) : substr($rendered, 0, 200);
                $rendered = $cut . '…';
            }
        }

        $items[] = '<li><strong>' . e($key) . '</strong>: ' . e($rendered) . '</li>';
    }

    return '<ul>' . implode('', $items) . '</ul>';
}

function formatSignatureImage($value) {
    if ($value === null || $value === '') {
        return null;
    }

    $clean = trim(preg_replace('/\s+/', '', (string)$value));
    if ($clean === '') {
        return null;
    }

    if (stripos($clean, 'data:image') === 0) {
        return $clean;
    }

    $isBase64 = (bool)preg_match('/^[A-Za-z0-9+\/=]+$/', $clean);
    $payload = $isBase64 ? $clean : base64_encode((string)$value);
    $mime = stripos((string)$value, 'jpeg') !== false || stripos((string)$value, 'jpg') !== false
        ? 'image/jpeg'
        : 'image/png';
    return 'data:' . $mime . ';base64,' . $payload;
}

function hasScope($scopes, $key) {
    if (!$scopes) {
        return false;
    }
    if (is_array($scopes) && array_values($scopes) === $scopes) {
        return in_array($key, $scopes, true);
    }
    return !empty($scopes[$key]);
}

function checkboxSymbol($checked) {
    $symbol = $checked ? '&#x2611;' : '&#x2610;';
    $fallback = $checked ? '[X]' : '[ ]';
    return '<span class="checkbox-symbol">' . $symbol . '</span><span class="checkbox-fallback">' . $fallback . '</span>';
}

$pharmacy = $reportData['pharmacy'] ?? [];
$pharmacist = $reportData['pharmacist'] ?? [];
$patient = $reportData['patient'] ?? [];
$therapy = $reportData['therapy'] ?? [];
$chronic = $reportData['chronic_care'] ?? [];
$consents = $reportData['consents'] ?? [];
$caregivers = $reportData['caregivers'] ?? [];
$consentData = $reportData['chronic_care']['consent'] ?? [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #333; }
        h1, h2, h3 { margin: 0 0 8px; }
        .section { margin-bottom: 18px; }
        .section h2 { font-size: 16px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8px; }
        table th, table td { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; word-break: break-word; white-space: normal; }
        .muted { color: #777; }
        .consent-check { margin-bottom: 6px; }
        .checkbox-symbol { font-family: DejaVu Sans, Arial, sans-serif; margin-right: 6px; }
        .checkbox-fallback { display: none; font-family: Arial, sans-serif; font-size: 11px; margin-left: 4px; }
        .signature-table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 12px; }
        .signature-table td { border: none; padding: 0 6px; text-align: center; vertical-align: bottom; }
        .signature-line { border-top: 1px solid #333; margin: 12px 8px 6px; }
        .signature-image { max-width: 100%; max-height: 90px; margin-bottom: 6px; }
        ul { padding-left: 16px; margin: 6px 0; }
    </style>
</head>
<body>
    <h1>Riepilogo terapia</h1>
    <p class="muted">Generato il <?= e(formatRomeDate($reportData['generated_at'] ?? '', 'd/m/Y H:i')) ?></p>

    <div class="section">
        <h2>Farmacia</h2>
        <div><strong><?= e($pharmacy['name'] ?? '') ?></strong></div>
        <div><?= e($pharmacy['address'] ?? '') ?> <?= e($pharmacy['city'] ?? '') ?></div>
        <div>Email: <?= e($pharmacy['email'] ?? '-') ?> | Telefono: <?= e($pharmacy['phone'] ?? '-') ?></div>
    </div>

    <div class="section">
        <h2>Farmacista</h2>
        <div><strong><?= e($pharmacist['name'] ?? '-') ?></strong></div>
        <div>Email: <?= e($pharmacist['email'] ?? '-') ?></div>
    </div>

    <div class="section">
        <h2>Paziente</h2>
        <table>
            <tr><th>Nome</th><td><?= e(trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''))) ?></td></tr>
            <tr><th>Codice fiscale</th><td><?= e($patient['codice_fiscale'] ?? '-') ?></td></tr>
            <tr><th>Data di nascita</th><td><?= e(formatRomeDate($patient['birth_date'] ?? null)) ?></td></tr>
            <tr><th>Contatti</th><td>Email: <?= e($patient['email'] ?? '-') ?> | Telefono: <?= e($patient['phone'] ?? '-') ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Caregiver / Familiare</h2>
        <?php if ($caregivers && is_array($caregivers) && count($caregivers)): ?>
            <table>
                <thead>
                    <tr><th>Nome</th><th>Relazione</th><th>Contatti</th></tr>
                </thead>
                <tbody>
                <?php foreach ($caregivers as $caregiver): ?>
                    <tr>
                        <td><?= e(trim(($caregiver['first_name'] ?? '') . ' ' . ($caregiver['last_name'] ?? ''))) ?></td>
                        <td><?= e($caregiver['relation_to_patient'] ?? $caregiver['role'] ?? '-') ?></td>
                        <td>Email: <?= e($caregiver['email'] ?? '-') ?> | Telefono: <?= e($caregiver['phone'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Nessun caregiver o familiare indicato.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Terapia</h2>
        <table>
            <tr><th>Titolo</th><td><?= e($therapy['title'] ?? '-') ?></td></tr>
            <tr><th>Descrizione</th><td><?= e($therapy['description'] ?? '-') ?></td></tr>
            <tr><th>Stato</th><td><?= e($therapy['status'] ?? '-') ?></td></tr>
            <tr><th>Periodo</th><td><?= e(formatRomeDate($therapy['start_date'] ?? null)) ?> - <?= e(formatRomeDate($therapy['end_date'] ?? null)) ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Condizione primaria</h2>
        <table>
            <tr><th>Condizione primaria</th><td><?= e($chronic['condition'] ?? '-') ?></td></tr>
            <tr><th>Note iniziali</th><td><?= e($chronic['notes_initial'] ?? '-') ?></td></tr>
            <tr><th>Data follow-up</th><td><?= e(formatRomeDate($chronic['follow_up_date'] ?? null)) ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Consensi</h2>
        <?php
            $scopes = is_array($consentData) ? ($consentData['scopes'] ?? []) : [];
            $place = is_array($consentData) ? ($consentData['place'] ?? null) : null;
            $signedAt = is_array($consentData) ? ($consentData['signed_at'] ?? null) : null;
            $pharmacistName = is_array($consentData) ? ($consentData['pharmacist_name'] ?? null) : null;
            $consentSigner = is_array($consentData) ? ($consentData['signer_name'] ?? null) : null;
            $patientName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
            $caregiverName = '';
            if ($caregivers && is_array($caregivers) && count($caregivers)) {
                $caregiverName = trim(($caregivers[0]['first_name'] ?? '') . ' ' . ($caregivers[0]['last_name'] ?? ''));
            }
            $signatureImage = null;
            if (is_array($consentData) && !empty($consentData['signature_image'])) {
                $signatureImage = formatSignatureImage($consentData['signature_image']);
            } elseif ($consents && is_array($consents) && count($consents)) {
                $signatureImage = formatSignatureImage($consents[0]['signature_image'] ?? null);
            }
            $patientLabel = $consentSigner ?: $patientName;
            $caregiverLabel = $caregiverName ?: 'Caregiver/Familiare';
            $pharmacistLabel = $pharmacistName ?: ($pharmacist['name'] ?? '-');
        ?>
        <p><strong>Consenso informato e trattamento dati</strong></p>
        <div class="consent-check">
            <?= checkboxSymbol(hasScope($scopes, 'care_followup')) ?>
            Acconsento all'avvio del percorso di aderenza terapeutica e alle attività di cura, monitoraggio e follow-up.
        </div>
        <div class="consent-check">
            <?= checkboxSymbol(hasScope($scopes, 'contact_for_reminders')) ?>
            Acconsento al trattamento dei dati personali e al contatto da parte della farmacia per comunicazioni legate alla terapia.
        </div>
        <div class="consent-check">
            <?= checkboxSymbol(hasScope($scopes, 'anonymous_stats')) ?>
            Acconsento all'utilizzo dei dati in forma anonima per finalità statistiche e miglioramento del servizio.
        </div>
        <p>Luogo: <?= e($place ?? '-') ?> | Data: <?= e(formatRomeDate($signedAt ?? null)) ?></p>
        <p>Farmacista: <?= e($pharmacistLabel) ?></p>
        <table class="signature-table">
            <tr>
                <td>
                    <?php if ($signatureImage): ?>
                        <div><img class="signature-image" src="<?= e($signatureImage) ?>" alt="Firma"></div>
                    <?php endif; ?>
                    <div class="signature-line"></div>
                    Firma Paziente<?= $patientLabel ? ': ' . e($patientLabel) : '' ?>
                </td>
                <td>
                    <div class="signature-line"></div>
                    Firma <?= e($caregiverLabel) ?>
                </td>
                <td>
                    <div class="signature-line"></div>
                    Firma <?= e($pharmacistLabel) ?>
                </td>
            </tr>
        </table>
    </div>

    <p class="muted">Documento generato automaticamente dal sistema.</p>
</body>
</html>
