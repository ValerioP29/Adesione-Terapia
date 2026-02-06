# Modulo “Adesione Terapie” – Schema & coerenza (review)

## 1. Schema ricostruito da `QUERY.sql` (tabelle del modulo)

> **Nota**: le tabelle `jta_pharmas` e `jta_pharma_patient` sono referenziate da FK/query applicative ma non sono definite in `QUERY.sql` (sono fuori dal perimetro di questo file).

### jta_patients
- **Scopo**: anagrafica pazienti.
- **PK**: `id`.
- **Campi chiave**: `pharmacy_id`, `first_name`, `last_name`, `birth_date`, `codice_fiscale`, `gender`, `phone`, `email`, `notes`.
- **FK**: `pharmacy_id` → `jta_pharmas.id` (ON DELETE SET NULL).
- **Indici**: `idx_patients_pharmacy`, `idx_patients_name`, `idx_patients_cf`.

### jta_assistants
- **Scopo**: caregiver/assistenti collegati alla farmacia.
- **PK**: `id`.
- **Campi chiave**: `pharma_id`, `first_name`, `last_name`, `phone`, `email`, `type`, `relation_to_patient`, `preferred_contact`.
- **FK**: `pharma_id` → `jta_pharmas.id` (ON DELETE CASCADE).
- **Indici**: `idx_assistants_pharma`, `idx_assistants_name`.

### jta_therapies
- **Scopo**: terapia con stato/periodo.
- **PK**: `id`.
- **Campi chiave**: `pharmacy_id`, `patient_id`, `therapy_title`, `status`, `start_date`, `end_date`.
- **FK**: `patient_id` → `jta_patients.id`, `pharmacy_id` → `jta_pharmas.id` (entrambi ON DELETE CASCADE).
- **Indici**: `idx_therapy_pharma`, `idx_therapy_patient`, `idx_therapy_status`.

### jta_therapy_assistant
- **Scopo**: associazione terapia↔assistente (ruolo + consensi/preferenze).
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `assistant_id`, `role`, `contact_channel`.
- **FK**: `assistant_id` → `jta_assistants.id`, `therapy_id` → `jta_therapies.id` (ON DELETE CASCADE).
- **Indici**: `uq_ta_unique (therapy_id, assistant_id)`, `fk_ta_assistant (assistant_id)`.

### jta_therapy_chronic_care
- **Scopo**: scheda iniziale/cronica della terapia.
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `primary_condition`, `general_anamnesis`, `detailed_intake`, `adherence_base`, `risk_score`, `follow_up_date`, `consent`, `doctor_info`, `biometric_info`, `care_context`.
- **FK**: `therapy_id` → `jta_therapies.id` (ON DELETE CASCADE).
- **Indici**: `idx_tcc_therapy`, `idx_tcc_condition`.

### jta_therapy_condition_surveys
- **Scopo**: questionari condition-specific (base/approfondito).
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `condition_type`, `level`, `answers`, `compiled_at`.
- **FK**: `therapy_id` → `jta_therapies.id` (ON DELETE CASCADE).
- **Indici**: `idx_tcs_therapy`, `idx_tcs_condition (condition_type, level)`.

### jta_therapy_followups
- **Scopo**: follow-up/check periodici, metadati e snapshot JSON.
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `pharmacy_id`, `created_by`, `entry_type`, `check_type`, `risk_score`, `pharmacist_notes`, `follow_up_date`, `snapshot`.
- **FK**: `therapy_id` → `jta_therapies.id` (ON DELETE CASCADE), `pharmacy_id` → `jta_pharmas.id` (ON DELETE SET NULL).
- **Indici**: `idx_tf_therapy`, `idx_tf_followup`, `idx_tf_entry_type`, `idx_tf_therapy_pharmacy`.

### jta_therapy_reminders
- **Scopo**: promemoria per terapia.
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `title`, `message`, `type`, `scheduled_at`, `channel`, `status`.
- **FK**: `therapy_id` → `jta_therapies.id` (ON DELETE CASCADE).
- **Indici**: `idx_tr_therapy`, `idx_tr_schedule`.

### jta_therapy_reports
- **Scopo**: report PDF/JSON generati per una terapia.
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `pharmacy_id`, `content`, `share_token`, `pin_code`, `valid_until`, `recipients`.
- **FK**: `therapy_id` → `jta_therapies.id`, `pharmacy_id` → `jta_pharmas.id` (ON DELETE CASCADE).
- **Indici**: `idx_r_therapy`, `idx_r_token`, `fk_r_pharma`.

### jta_therapy_consents
- **Scopo**: consensi firmati.
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `signer_name`, `signer_relation`, `consent_text`, `signed_at`, `ip_address`, `signature_image`, `scopes_json`, `signer_role`.
- **FK**: `therapy_id` → `jta_therapies.id` (ON DELETE CASCADE).
- **Indici**: `idx_tc_therapy`, `idx_tc_signer`.

### jta_therapy_checklist_questions
- **Scopo**: domande checklist (template + custom) per terapia.
- **PK**: `id`.
- **Campi chiave**: `therapy_id`, `pharmacy_id`, `condition_key`, `question_key`, `question_text`, `input_type`, `options_json`, `sort_order`, `is_active`.
- **FK**: `therapy_id` → `jta_therapies.id`, `pharmacy_id` → `jta_pharmas.id` (ON DELETE CASCADE).
- **Indici**: `uniq_tcq_therapy_key (therapy_id, question_key)`, `idx_tcq_therapy`, `idx_tcq_pharmacy`.

### jta_therapy_checklist_answers
- **Scopo**: **tabella dove ogni domanda/risposta è salvata come una riga**.
- **PK**: `id`.
- **Campi chiave**: `followup_id`, `question_id`, `answer_value`.
- **FK**: `followup_id` → `jta_therapy_followups.id`, `question_id` → `jta_therapy_checklist_questions.id` (ON DELETE CASCADE).
- **Indici**: `uniq_check_answer (followup_id, question_id)`, `idx_tca_followup`, `idx_tca_question`.

## 2. Coerenza codice ↔ schema (query/insert/update)

### Terapie / anagrafiche
- **Coerente**: CRUD su `jta_patients`, `jta_assistants`, `jta_therapies`, `jta_therapy_assistant`, `jta_therapy_chronic_care`, `jta_therapy_condition_surveys`, `jta_therapy_consents` usa le colonne presenti nello schema.
- **Attenzione**: il mapping paziente↔farmacia usa `jta_pharma_patient` (non definita in `QUERY.sql`), quindi la coerenza completa dipende da uno schema esterno.

### Follow-up/checklist
- **Coerente**: `jta_therapy_followups` viene popolata con `therapy_id`, `pharmacy_id`, `created_by`, `entry_type`, `check_type`, `risk_score`, `pharmacist_notes`, `follow_up_date`; le colonne esistono.
- **Coerente**: `jta_therapy_checklist_questions` e `jta_therapy_checklist_answers` sono usate con colonne coerenti (`question_key`, `options_json`, `answer_value`, ecc.).
- **Attenzione**: `upsertInitialFollowupChecklist` fa merge delle risposte basandosi su `question_key`; se `question_key` è `NULL` (domande custom), le risposte non possono essere riconciliate in modo stabile e vengono rimpiazzate quando si rigenera il follow-up iniziale.

### Promemoria
- **Coerente**: `jta_therapy_reminders` usa `scheduled_at`, `type`, `status`, `channel` come da schema.
- **Timezone**: `api/reminders.php` forza `Europe/Rome`, ma `api/notifications/check-new.php` usa `NOW()`/`DateTime` senza forzare timezone. Questo può spostare le finestre di scadenza in ambienti con timezone diversa.

### Report
- **Coerente**: `jta_therapy_reports` insert/update con `content`, `share_token`, `pin_code`, `valid_until`, `recipients` coerente con schema.

## 3. Tabella Q/A (domande/risposte) e valutazione design

**Tabella identificata:** `jta_therapy_checklist_answers` (1 riga per follow-up + domanda).

**Valutazione design (stabilità/duplicati/indici/vincoli)**
- ✅ **Duplicati**: prevenuti con `UNIQUE (followup_id, question_id)`.
- ⚠️ **Chiave non stabile per merge**: il merge delle risposte iniziali usa `question_key`. Le domande custom hanno `question_key = NULL`, quindi le risposte non sono riagganciabili se si rigenera l’iniziale (rischio perdita risposta o mismatch).
- ⚠️ **Assenza di vincolo cross-therapy**: FK separati garantiscono esistenza di follow-up e domanda, ma non impediscono che una risposta leghi un `question_id` di un’altra terapia (eventuale bug applicativo). Non è un problema oggi ma è un rischio di integrità.
- ✅ **Indici**: `idx_tca_followup` e `idx_tca_question` coprono le query principali; ok per lookup per follow-up.

## 4. Punti critici (BUG / data-loss / performance)

### BUG / data-loss
1. **Timezone non uniforme per promemoria**: le scadenze vengono valutate con `NOW()`/`DateTime` senza timezone in `check-new.php`. Se il server non è in `Europe/Rome`, i promemoria possono apparire in anticipo/ritardo.
2. **Risposte custom non riconciliate**: `question_key = NULL` rende instabile il merge delle risposte nel follow-up iniziale; rigenerazioni possono sovrascrivere risposte a domande custom.

### Performance
1. **Follow-up “initial”**: query per `therapy_id + pharmacy_id + check_type` non ha indice dedicato → scansioni più lente.
2. **Checklist per terapia**: query frequenti con `therapy_id + pharmacy_id + is_active` e ordinamento per `sort_order` senza indice composito.
3. **Promemoria in scadenza**: filtro per `status` e `scheduled_at` con indice solo su `scheduled_at` (manca composito su `status, scheduled_at`).

## 5. Quick wins (patch consigliate, senza perdita dati)

### 5.1 Indici aggiuntivi (SQL)
```sql
-- Follow-up iniziali/periodici: accelera lookup per terapia/farmacia/tipo
CREATE INDEX idx_tf_therapy_pharmacy_checktype
  ON jta_therapy_followups (therapy_id, pharmacy_id, check_type);

-- Checklist attiva per terapia: filtri e sorting più veloci
CREATE INDEX idx_tcq_therapy_pharmacy_active_sort
  ON jta_therapy_checklist_questions (therapy_id, pharmacy_id, is_active, sort_order);

-- Promemoria: selezione in scadenza
CREATE INDEX idx_tr_status_schedule
  ON jta_therapy_reminders (status, scheduled_at);
```

### 5.2 Vincoli aggiuntivi (opzionali, non distruttivi)
```sql
-- (Opzionale) evita doppioni accidentali in reports con stesso token
-- Se il token è usato come identificatore pubblico, imporre unicità può evitare collisioni.
CREATE UNIQUE INDEX uq_tr_share_token
  ON jta_therapy_reports (share_token);
```

### 5.3 Patch lato codice (punti precisi)
1. **Timezone promemoria**: uniformare la timezone in `api/notifications/check-new.php` aggiungendo `date_default_timezone_set('Europe/Rome')` o usando `new DateTime('now', new DateTimeZone('Europe/Rome'))`. (Compatibile e senza migrazione dati.)
2. **Stabilità risposte custom**: se si desidera mantenere risposte per domande custom nel merge iniziale, valorizzare `question_key` anche per custom (es. UUID lato API), evitando `NULL` e consentendo un merge stabile. (Richiede solo patch applicativa, nessuna perdita dati se valorizzate progressivamente.)

---

## 6. Elenco tabelle coinvolte (riepilogo)
`jta_patients`, `jta_assistants`, `jta_therapies`, `jta_therapy_assistant`, `jta_therapy_chronic_care`, `jta_therapy_condition_surveys`, `jta_therapy_followups`, `jta_therapy_reminders`, `jta_therapy_reports`, `jta_therapy_consents`, `jta_therapy_checklist_questions`, `jta_therapy_checklist_answers`.
