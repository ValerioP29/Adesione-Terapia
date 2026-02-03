<?php

function normalizeConditionKey($condition)
{
    $normalized = strtolower(trim((string)$condition));
    $normalized = str_replace(['à', 'á', 'â', 'ä'], 'a', $normalized);
    $normalized = str_replace(['è', 'é', 'ê', 'ë'], 'e', $normalized);
    $normalized = str_replace(['ì', 'í', 'î', 'ï'], 'i', $normalized);
    $normalized = str_replace(['ò', 'ó', 'ô', 'ö'], 'o', $normalized);
    $normalized = str_replace(['ù', 'ú', 'û', 'ü'], 'u', $normalized);

    $map = [
        'diabete' => 'diabete',
        'diabetes' => 'diabete',
        'bpco' => 'bpco',
        'bpc o' => 'bpco',
        'copd' => 'bpco',
        'ipertensione' => 'ipertensione',
        'pressione alta' => 'ipertensione',
        'dislipidemia' => 'dislipidemia'
    ];

    return $map[$normalized] ?? $normalized;
}

function getChecklistTemplates()
{
    return [
        'diabete' => [
            [
                'key' => 'misura_glicemia',
                'label' => 'Misura regolarmente la glicemia?',
                'type' => 'select',
                'options' => ['Tutti i giorni', 'Qualche volta', 'Raramente']
            ],
            [
                'key' => 'ultimo_valore_glicemia',
                'label' => 'Ultimo valore glicemico (mg/dL)',
                'type' => 'text'
            ],
            [
                'key' => 'hba1c_6_mesi',
                'label' => 'Ha eseguito l’emoglobina glicata (HbA1c) negli ultimi 6 mesi?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'segue_dieta',
                'label' => 'Segue una dieta specifica per il diabete?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'attivita_fisica',
                'label' => 'Svolge attività fisica regolare?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'aderenza_farmaci',
                'label' => 'Assume regolarmente i farmaci prescritti?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'ipoglicemie',
                'label' => 'Ha avuto episodi di ipoglicemia?',
                'type' => 'select',
                'options' => ['Mai', 'Talvolta', 'Spesso']
            ],
            [
                'key' => 'controllo_pre_pasto',
                'label' => 'Controlla la glicemia prima dei pasti o a orari fissi?',
                'type' => 'select',
                'options' => ['Sempre', 'A volte', 'Mai']
            ],
            [
                'key' => 'conservazione_insulina',
                'label' => 'Conserva correttamente l’insulina?',
                'type' => 'select',
                'options' => ['Sì', 'No', 'Non uso insulina']
            ],
            [
                'key' => 'controlli_specialisti',
                'label' => 'Effettua controlli periodici da diabetologo / oculista / podologo?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'difficolta_aderenza',
                'label' => 'Ha difficoltà a rispettare orari e dosaggi della terapia?',
                'type' => 'select',
                'options' => ['No', 'A volte', 'Sì']
            ],
            [
                'key' => 'formicolii_ferite',
                'label' => 'Ha formicolii o ferite che guariscono lentamente?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ]
        ],
        'bpco' => [
            [
                'key' => 'fiato_sforzo',
                'label' => 'Le manca il fiato quando cammina in piano o sale una rampa di scale?',
                'type' => 'select',
                'options' => ['Mai', 'A volte', 'Spesso']
            ],
            [
                'key' => 'tosse_muco',
                'label' => 'Tossisce o espelle muco durante la giornata?',
                'type' => 'select',
                'options' => ['No', 'Saltuariamente', 'Frequentemente']
            ],
            [
                'key' => 'limitazioni_attivita',
                'label' => 'Ha limitazioni nelle attività quotidiane a causa del respiro?',
                'type' => 'select',
                'options' => ['No', 'Moderate', 'Gravi']
            ],
            [
                'key' => 'riacutizzazioni',
                'label' => 'Ha avuto riacutizzazioni o ricoveri negli ultimi 12 mesi?',
                'type' => 'select',
                'options' => ['No', '1-2', 'Più di 2']
            ],
            [
                'key' => 'inhaler_corretto',
                'label' => 'Utilizza correttamente l’inalatore?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'fumo',
                'label' => 'Fuma attualmente?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'ossigenoterapia',
                'label' => 'Segue ossigenoterapia?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'vaccinazioni',
                'label' => 'È in regola con le vaccinazioni (influenza/pneumococco)?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'riabilitazione',
                'label' => 'Ha svolto riabilitazione respiratoria?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'farmaci_regolari',
                'label' => 'Assume regolarmente i farmaci prescritti?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ]
        ],
        'ipertensione' => [
            [
                'key' => 'pressione_controllo',
                'label' => 'Misura regolarmente la pressione arteriosa?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'ultimo_valore_pressione',
                'label' => 'Ultimo valore della pressione (mmHg)',
                'type' => 'text'
            ],
            [
                'key' => 'aderenza_terapia',
                'label' => 'Assume regolarmente i farmaci antipertensivi?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'stile_vita',
                'label' => 'Segue uno stile di vita sano (dieta povera di sale, attività fisica)?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'controlli_medici',
                'label' => 'Effettua controlli periodici dal medico?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'sintomi',
                'label' => 'Ha avuto sintomi come mal di testa o vertigini?',
                'type' => 'select',
                'options' => ['Mai', 'Talvolta', 'Spesso']
            ],
            [
                'key' => 'fumo',
                'label' => 'Fuma attualmente?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'alcool',
                'label' => 'Consuma alcolici regolarmente?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'peso',
                'label' => 'Il peso corporeo è nella norma?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ]
        ],
        'dislipidemia' => [
            [
                'key' => 'controllo_colesterolo',
                'label' => 'Ha controllato colesterolo e trigliceridi negli ultimi 6 mesi?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'ultimo_valore_colesterolo',
                'label' => 'Ultimo valore di colesterolo totale (mg/dL)',
                'type' => 'text'
            ],
            [
                'key' => 'aderenza_farmaci',
                'label' => 'Assume regolarmente le statine o altri farmaci?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'dieta',
                'label' => 'Segue una dieta povera di grassi?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'attivita_fisica',
                'label' => 'Svolge attività fisica regolare?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'controlli_medici',
                'label' => 'Effettua controlli periodici dal medico?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'fumo',
                'label' => 'Fuma attualmente?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ],
            [
                'key' => 'peso',
                'label' => 'Il peso corporeo è nella norma?',
                'type' => 'select',
                'options' => ['Sì', 'No']
            ]
        ]
    ];
}

function getChecklistTemplateForCondition($condition)
{
    $templates = getChecklistTemplates();
    $key = normalizeConditionKey($condition);
    return $key && isset($templates[$key]) ? $templates[$key] : [];
}

function ensureTherapyChecklist(PDO $pdo, $therapy_id, $pharmacy_id, $condition)
{
    $stmt = $pdo->prepare("SELECT id FROM jta_therapy_checklist_questions WHERE therapy_id = ? LIMIT 1");
    $stmt->execute([$therapy_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        return [];
    }

    $normalizedCondition = normalizeConditionKey($condition);
    $questions = getChecklistTemplateForCondition($normalizedCondition);
    if (!$questions) {
        return [];
    }

    $sort = 1;
    $insertStmt = $pdo->prepare(
        "INSERT INTO jta_therapy_checklist_questions (therapy_id, pharmacy_id, condition_key, question_key, question_text, input_type, options_json, sort_order, is_active)
         VALUES (?,?,?,?,?,?,?,?,1)"
    );
    foreach ($questions as $question) {
        $insertStmt->execute([
            $therapy_id,
            $pharmacy_id,
            $normalizedCondition,
            $question['key'] ?? null,
            $question['label'] ?? ($question['key'] ?? ''),
            $question['type'] ?? 'text',
            isset($question['options']) ? json_encode($question['options']) : null,
            $sort
        ]);
        $sort++;
    }

    return $questions;
}
