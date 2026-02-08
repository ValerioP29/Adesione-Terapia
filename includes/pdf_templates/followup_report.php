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
    if (is_object($value)) {
        $value = (array)$value;
    }
    if (is_array($value)) {
        if (!$value) {
            return '-';
        }
        if (array_values($value) === $value) {
            return implode(', ', array_map('formatAnswer', $value));
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = $key . ': ' . formatAnswer($item);
        }
        return implode('; ', $parts);
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

$pharmacy = $reportData['pharmacy'] ?? [];
$pharmacist = $reportData['pharmacist'] ?? [];
$patient = $reportData['patient'] ?? [];
$therapy = $reportData['therapy'] ?? [];
$chronic = $reportData['chronic_care'] ?? [];
$followup = $reportData['followup'] ?? [];
$questions = $followup['questions'] ?? [];
$snapshot = $followup['snapshot'] ?? [];
$legacyQuestions = $snapshot['questions'] ?? [];
$customQuestions = $snapshot['custom_questions'] ?? [];
$checkTypeLabel = ($followup['check_type'] ?? null) === 'initial' ? 'Iniziale' : 'Periodico';
$headingLabel = ($followup['entry_type'] ?? null) === 'check' ? "Check {$checkTypeLabel}" : 'Follow-up';
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
    </style>
</head>
<body>
    <h1><?= e($headingLabel) ?></h1>
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
            <tr><th>Periodo</th><td><?= e(formatRomeDate($therapy['start_date'] ?? null)) ?> - <?= e(formatRomeDate($therapy['end_date'] ?? null)) ?></td></tr>
            <tr><th>Condizione primaria</th><td><?= e($chronic['condition'] ?? '-') ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Dati <?= e($headingLabel) ?></h2>
        <table>
            <tr><th>Data follow-up</th><td><?= e(formatRomeDate($followup['follow_up_date'] ?? null)) ?></td></tr>
            <tr><th>Rischio</th><td><?= e($followup['risk_score'] ?? '-') ?></td></tr>
            <tr><th>Note farmacista</th><td><?= e($followup['pharmacist_notes'] ?? '-') ?></td></tr>
            <tr><th>Creato il</th><td><?= e(formatRomeDate($followup['created_at'] ?? null, 'd/m/Y H:i')) ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Checklist follow-up</h2>
        <?php if ($questions && is_array($questions) && count($questions)): ?>
            <table>
                <thead>
                    <tr><th>Domanda</th><th>Risposta</th></tr>
                </thead>
                <tbody>
                <?php foreach ($questions as $question): ?>
                    <tr>
                        <td><?= e($question['text'] ?? $question['question_text'] ?? $question['key'] ?? '') ?></td>
                        <td><?= e(formatAnswer($question['answer'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <?php if ($legacyQuestions || $customQuestions): ?>
                <p class="muted">Checklist non disponibile. Mostro domande da snapshot.</p>
                <?php if ($legacyQuestions): ?>
                    <table>
                        <thead><tr><th>Domanda</th><th>Risposta</th></tr></thead>
                        <tbody>
                        <?php foreach ($legacyQuestions as $question): ?>
                            <tr>
                                <td><?= e($question['label'] ?? $question['text'] ?? $question['key'] ?? '') ?></td>
                                <td><?= e(formatAnswer($question['answer'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($customQuestions): ?>
                    <table>
                        <thead><tr><th>Domanda personalizzata</th><th>Risposta</th></tr></thead>
                        <tbody>
                        <?php foreach ($customQuestions as $question): ?>
                            <tr>
                                <td><?= e($question['label'] ?? $question['text'] ?? '') ?></td>
                                <td><?= e(formatAnswer($question['answer'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">Nessuna risposta checklist registrata.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <p class="muted">Documento generato automaticamente dal sistema.</p>
</body>
</html>
