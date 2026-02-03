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

$pharmacy = $reportData['pharmacy'] ?? [];
$pharmacist = $reportData['pharmacist'] ?? [];
$patient = $reportData['patient'] ?? [];
$caregivers = $reportData['caregivers'] ?? [];
$therapy = $reportData['therapy'] ?? [];
$chronic = $reportData['chronic_care'] ?? [];
$survey = $reportData['survey_base']['answers'] ?? [];
$checkFollowups = $reportData['check_followups'] ?? [];
$manualFollowups = $reportData['manual_followups'] ?? [];
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
    <h1>Report terapia</h1>
    <p class="muted">Generato il <?= e($reportData['generated_at'] ?? '') ?></p>

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
            <tr><th>Data di nascita</th><td><?= e($patient['birth_date'] ?? '-') ?></td></tr>
            <tr><th>Contatti</th><td>Email: <?= e($patient['email'] ?? '-') ?> | Telefono: <?= e($patient['phone'] ?? '-') ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Caregiver</h2>
        <?php if ($caregivers && is_array($caregivers) && count($caregivers)): ?>
            <table>
                <thead>
                    <tr><th>Nome</th><th>Relazione</th><th>Contatti</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($caregivers as $caregiver): ?>
                        <tr>
                            <td><?= e(trim(($caregiver['first_name'] ?? '') . ' ' . ($caregiver['last_name'] ?? ''))) ?></td>
                            <td><?= e($caregiver['relation_to_patient'] ?? $caregiver['type'] ?? '-') ?></td>
                            <td><?= e(($caregiver['email'] ?? '-') . ' | ' . ($caregiver['phone'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Nessun caregiver registrato.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Terapia</h2>
        <table>
            <tr><th>Titolo</th><td><?= e($therapy['title'] ?? '-') ?></td></tr>
            <tr><th>Descrizione</th><td><?= e($therapy['description'] ?? '-') ?></td></tr>
            <tr><th>Stato</th><td><?= e($therapy['status'] ?? '-') ?></td></tr>
            <tr><th>Periodo</th><td><?= e($therapy['start_date'] ?? '-') ?> - <?= e($therapy['end_date'] ?? '-') ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Condizione e anamnesi</h2>
        <table>
            <tr><th>Condizione primaria</th><td><?= e($chronic['condition'] ?? '-') ?></td></tr>
            <tr><th>Anamnesi generale</th><td><?= e(json_encode($chronic['general_anamnesis'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></td></tr>
            <tr><th>Intake</th><td><?= e(json_encode($chronic['detailed_intake'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></td></tr>
            <tr><th>Adherence base</th><td><?= e(json_encode($chronic['adherence_base'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></td></tr>
            <tr><th>Note iniziali</th><td><?= e($chronic['notes_initial'] ?? '-') ?></td></tr>
            <tr><th>Rischio</th><td><?= e($chronic['risk_score'] ?? '-') ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Survey base</h2>
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
            <p class="muted">Nessun questionario base disponibile.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Check periodici</h2>
        <?php if ($checkFollowups && is_array($checkFollowups) && count($checkFollowups)): ?>
            <?php foreach ($checkFollowups as $index => $followup):
                $snapshot = $followup['snapshot'] ?? [];
                $questions = $followup['questions'] ?? null;
                $legacyQuestions = $snapshot['questions'] ?? [];
                $custom = $snapshot['custom_questions'] ?? [];
                $checkType = $followup['check_type'] ?? null;
                $checkLabel = $checkType === 'initial' ? 'Iniziale' : 'Periodico';
            ?>
                <h3>Check <?= e($checkLabel) ?> #<?= e($followup['id'] ?? ($index + 1)) ?> - <?= e($followup['follow_up_date'] ?? $followup['created_at'] ?? '') ?></h3>
                <table>
                    <tr><th>Rischio</th><td><?= e($followup['risk_score'] ?? '-') ?></td></tr>
                    <tr><th>Note farmacista</th><td><?= e($followup['pharmacist_notes'] ?? '-') ?></td></tr>
                </table>
                <h4>Domande</h4>
                <?php if ($questions && is_array($questions) && count($questions)): ?>
                    <table>
                        <thead><tr><th>Domanda</th><th>Risposta</th></tr></thead>
                        <tbody>
                            <?php foreach ($questions as $q): ?>
                                <tr>
                                    <td><?= e($q['text'] ?? $q['label'] ?? $q['key'] ?? '') ?></td>
                                    <td><?= e(formatAnswer($q['answer'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <h4>Domande base</h4>
                    <?php if ($legacyQuestions): ?>
                        <table>
                            <thead><tr><th>Domanda</th><th>Risposta</th></tr></thead>
                            <tbody>
                                <?php foreach ($legacyQuestions as $q): ?>
                                    <tr>
                                        <td><?= e($q['label'] ?? $q['text'] ?? $q['key'] ?? '') ?></td>
                                        <td><?= e(formatAnswer($q['answer'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted">Nessuna domanda base.</p>
                    <?php endif; ?>

                    <h4>Domande personalizzate</h4>
                    <?php if ($custom): ?>
                        <table>
                            <thead><tr><th>Domanda</th><th>Risposta</th></tr></thead>
                            <tbody>
                                <?php foreach ($custom as $q): ?>
                                    <tr>
                                        <td><?= e($q['label'] ?? $q['text'] ?? '') ?></td>
                                        <td><?= e(formatAnswer($q['answer'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted">Nessuna domanda personalizzata.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="muted">Nessun check periodico disponibile.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Follow-up</h2>
        <?php if ($manualFollowups && is_array($manualFollowups) && count($manualFollowups)): ?>
            <?php foreach ($manualFollowups as $index => $followup): ?>
                <h3>Follow-up #<?= e($followup['id'] ?? ($index + 1)) ?> - <?= e($followup['follow_up_date'] ?? $followup['created_at'] ?? '') ?></h3>
                <table>
                    <tr><th>Rischio</th><td><?= e($followup['risk_score'] ?? '-') ?></td></tr>
                    <tr><th>Note farmacista</th><td><?= e($followup['pharmacist_notes'] ?? '-') ?></td></tr>
                </table>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="muted">Nessun follow-up disponibile.</p>
        <?php endif; ?>
    </div>

    <p class="muted">Report generato automaticamente dal sistema.</p>
</body>
</html>
