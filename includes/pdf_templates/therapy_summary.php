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

$pharmacy = $reportData['pharmacy'] ?? [];
$pharmacist = $reportData['pharmacist'] ?? [];
$patient = $reportData['patient'] ?? [];
$therapy = $reportData['therapy'] ?? [];
$chronic = $reportData['chronic_care'] ?? [];
$survey = $reportData['survey_base']['answers'] ?? [];
$consents = $reportData['consents'] ?? [];
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
        .signature { max-width: 240px; max-height: 120px; }
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
        <h2>Terapia</h2>
        <table>
            <tr><th>Titolo</th><td><?= e($therapy['title'] ?? '-') ?></td></tr>
            <tr><th>Descrizione</th><td><?= e($therapy['description'] ?? '-') ?></td></tr>
            <tr><th>Stato</th><td><?= e($therapy['status'] ?? '-') ?></td></tr>
            <tr><th>Periodo</th><td><?= e(formatRomeDate($therapy['start_date'] ?? null)) ?> - <?= e(formatRomeDate($therapy['end_date'] ?? null)) ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Anamnesi e dati clinici</h2>
        <table>
            <tr><th>Condizione primaria</th><td><?= e($chronic['condition'] ?? '-') ?></td></tr>
            <tr><th>Anamnesi generale</th><td><?= renderKeyValue($chronic['general_anamnesis'] ?? []) ?></td></tr>
            <tr><th>Intake</th><td><?= renderKeyValue($chronic['detailed_intake'] ?? []) ?></td></tr>
            <tr><th>Adherence base</th><td><?= renderKeyValue($chronic['adherence_base'] ?? []) ?></td></tr>
            <tr><th>Note iniziali</th><td><?= e($chronic['notes_initial'] ?? '-') ?></td></tr>
            <tr><th>Rischio</th><td><?= e($chronic['risk_score'] ?? '-') ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Survey condition</h2>
        <?php if ($survey && is_array($survey) && count($survey)): ?>
            <table>
                <thead>
                    <tr><th>Domanda</th><th>Risposta</th></tr>
                </thead>
                <tbody>
                <?php foreach ($survey as $question => $answer): ?>
                    <tr>
                        <td><?= e($question) ?></td>
                        <td><?= e(formatAnswer($answer)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Nessuna survey base disponibile.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Consensi</h2>
        <?php if ($consents && is_array($consents) && count($consents)): ?>
            <?php foreach ($consents as $consent): ?>
                <div class="section">
                    <p><strong><?= e($consent['consent_text'] ?? 'Consenso') ?></strong></p>
                    <p>Firmatario: <?= e($consent['signer_name'] ?? '-') ?> (<?= e($consent['signer_relation'] ?? $consent['signer_role'] ?? '-') ?>)</p>
                    <p>Data: <?= e(formatRomeDate($consent['signed_at'] ?? null, 'd/m/Y H:i')) ?></p>
                    <?php $signatureImage = formatSignatureImage($consent['signature_image'] ?? null); ?>
                    <?php if ($signatureImage): ?>
                        <div><img class="signature" src="<?= e($signatureImage) ?>" alt="Firma"></div>
                    <?php else: ?>
                        <p class="muted">Firma non disponibile.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="muted">Nessun consenso registrato.</p>
        <?php endif; ?>
    </div>

    <p class="muted">Documento generato automaticamente dal sistema.</p>
</body>
</html>
