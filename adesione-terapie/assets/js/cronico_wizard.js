// Wizard Terapia Cronico - logica completa
let therapyWizardState = {
    patient: {},
    primary_condition: null,
    initial_notes: null,
    general_anamnesis: {},
    detailed_intake: {},
    doctor_info: {},
    biometric_info: {},
    therapy_assistants: [],
    adherence_base: {},
    condition_survey: {},
    risk_score: null,
    flags: {},
    notes_initial: null,
    follow_up_date: null,
    consent: {}
};

let currentWizardStep = 1;
const totalWizardSteps = 6;
let currentTherapyId = null;
let wizardModalInstance = null;
const wizardDraftStoragePrefix = 'therapyWizardDraft';
const wizardDraftDebounceMs = 350;
const wizardDraftTtlMs = 7 * 24 * 60 * 60 * 1000;
let wizardDraftTimeout = null;
let skipDraftOnHide = false;

function getWizardDraftStorageKey(therapyId = currentTherapyId) {
    return `${wizardDraftStoragePrefix}:${therapyId || 'new'}`;
}

function normalizeWizardStep(step) {
    const parsed = Number(step);
    if (!Number.isFinite(parsed)) return 1;
    return Math.min(Math.max(parsed, 1), totalWizardSteps);
}

function mergeWizardState(defaultState, draftState = {}) {
    return {
        ...defaultState,
        ...draftState,
        patient: { ...defaultState.patient, ...draftState.patient },
        general_anamnesis: { ...defaultState.general_anamnesis, ...draftState.general_anamnesis },
        detailed_intake: { ...defaultState.detailed_intake, ...draftState.detailed_intake },
        doctor_info: { ...defaultState.doctor_info, ...draftState.doctor_info },
        biometric_info: { ...defaultState.biometric_info, ...draftState.biometric_info },
        adherence_base: { ...defaultState.adherence_base, ...draftState.adherence_base },
        condition_survey: { ...defaultState.condition_survey, ...draftState.condition_survey },
        flags: { ...defaultState.flags, ...draftState.flags },
        consent: {
            ...defaultState.consent,
            ...draftState.consent,
            scopes: { ...(defaultState.consent?.scopes || {}), ...(draftState.consent?.scopes || {}) },
            signatures: { ...(defaultState.consent?.signatures || {}), ...(draftState.consent?.signatures || {}) }
        }
    };
}

function loadWizardDraft(therapyId = currentTherapyId) {
    try {
        const raw = localStorage.getItem(getWizardDraftStorageKey(therapyId));
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        const updatedAt = parsed?.updated_at ? Date.parse(parsed.updated_at) : null;
        if (updatedAt && Date.now() - updatedAt > wizardDraftTtlMs) {
            clearWizardDraft(therapyId);
            return null;
        }
        return parsed;
    } catch (error) {
        console.warn('Impossibile leggere la bozza wizard', error);
        return null;
    }
}

function saveWizardDraftNow() {
    try {
        const payload = {
            state: therapyWizardState,
            step: currentWizardStep,
            updated_at: new Date().toISOString()
        };
        localStorage.setItem(getWizardDraftStorageKey(), JSON.stringify(payload));
    } catch (error) {
        console.warn('Impossibile salvare la bozza wizard', error);
    }
}

function scheduleWizardDraftSave() {
    window.clearTimeout(wizardDraftTimeout);
    wizardDraftTimeout = window.setTimeout(saveWizardDraftNow, wizardDraftDebounceMs);
}

function clearWizardDraft(therapyId = currentTherapyId) {
    window.clearTimeout(wizardDraftTimeout);
    wizardDraftTimeout = null;
    try {
        localStorage.removeItem(getWizardDraftStorageKey(therapyId));
    } catch (error) {
        console.warn('Impossibile cancellare la bozza wizard', error);
    }
}

function collectCurrentStepData() {
    switch (currentWizardStep) {
        case 1:
            collectStep1Data();
            break;
        case 3:
            collectStep3Data();
            break;
        case 4:
            collectStep4Data();
            break;
        case 5:
            collectStep5Data();
            break;
        case 6:
            collectStep6Data();
            break;
        default:
            break;
    }
}

function persistWizardDraft({ immediate = false, collectCurrent = true } = {}) {
    if (collectCurrent) {
        collectCurrentStepData();
    }
    if (immediate) {
        saveWizardDraftNow();
    } else {
        scheduleWizardDraftSave();
    }
}

window.SURVEY_TEMPLATES = {
   Diabete: [
        // QUESTIONARIO BASE
        {
            key: 'misura_glicemia',
            label: 'Misura regolarmente la glicemia?',
            type: 'select',
            options: ['Tutti i giorni', 'Qualche volta', 'Raramente']
        },
        {
            key: 'ultimo_valore_glicemia',
            label: 'Ultimo valore glicemico (mg/dL)',
            type: 'text'
        },
        {
            key: 'hba1c_6_mesi',
            label: 'Ha eseguito l’emoglobina glicata (HbA1c) negli ultimi 6 mesi?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'segue_dieta',
            label: 'Segue una dieta specifica per il diabete?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'attivita_fisica',
            label: 'Svolge attività fisica regolare?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'aderenza_farmaci',
            label: 'Assume regolarmente i farmaci prescritti?',
            type: 'select',
            options: ['Sì', 'No']
        },

        // QUESTIONARIO APPROFONDITO
        {
            key: 'ipoglicemie',
            label: 'Ha avuto episodi di ipoglicemia?',
            type: 'select',
            options: ['Mai', 'Talvolta', 'Spesso']
        },
        {
            key: 'controllo_pre_pasto',
            label: 'Controlla la glicemia prima dei pasti o a orari fissi?',
            type: 'select',
            options: ['Sempre', 'A volte', 'Mai']
        },
        {
            key: 'conservazione_insulina',
            label: 'Conserva correttamente l’insulina?',
            type: 'select',
            options: ['Sì', 'No', 'Non uso insulina']
        },
        {
            key: 'controlli_specialisti',
            label: 'Effettua controlli periodici da diabetologo / oculista / podologo?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'difficolta_aderenza',
            label: 'Ha difficoltà a rispettare orari e dosaggi della terapia?',
            type: 'select',
            options: ['No', 'A volte', 'Sì']
        },
        {
            key: 'formicolii_ferite',
            label: 'Ha formicolii o ferite che guariscono lentamente?',
            type: 'select',
            options: ['Sì', 'No']
        }
    ],

    BPCO: [
        // QUESTIONARIO BASE
        {
            key: 'fiato_sforzo',
            label: 'Le manca il fiato quando cammina in piano o sale una rampa di scale?',
            type: 'select',
            options: ['Mai', 'A volte', 'Spesso']
        },
        {
            key: 'tosse_muco',
            label: 'Tossisce o espelle muco durante la giornata?',
            type: 'select',
            options: ['No', 'Saltuariamente', 'Frequentemente']
        },
        {
            key: 'limitazioni_attivita',
            label: 'Ha limitazioni nelle attività quotidiane a causa del respiro?',
            type: 'select',
            options: ['No', 'Qualche volta', 'Spesso']
        },
        {
            key: 'qualita_sonno',
            label: 'Dorme bene la notte?',
            type: 'select',
            options: ['Sì', 'No (a causa del respiro)']
        },
        {
            key: 'stanchezza_generale',
            label: 'Si sente spesso stanco o senza energia?',
            type: 'select',
            options: ['Mai', 'A volte', 'Spesso']
        },
        {
            key: 'uso_inalatore_regolare',
            label: 'Utilizza regolarmente il suo inalatore?',
            type: 'select',
            options: ['Sempre', 'A volte', 'Raramente']
        },

        // QUESTIONARIO APPROFONDITO
        {
            key: 'dispnea_mmrc',
            label: 'Dispnea (scala mMRC)',
            type: 'select',
            options: ['0', '1', '2', '3', '4']
        },
        {
            key: 'riacutizzazioni_annuali',
            label: 'Quante riacutizzazioni ha avuto nell’ultimo anno?',
            type: 'select',
            options: ['Nessuna', '1', '≥2', 'Ricovero ospedaliero']
        },
        {
            key: 'uso_correttamente_dispositivo',
            label: 'È in grado di usare correttamente il dispositivo inalatorio?',
            type: 'select',
            options: ['Sì', 'No', 'Da verificare']
        },
        {
            key: 'effetti_collaterali',
            label: 'Ha effetti collaterali (tosse post-inalazione, secchezza, tremori)?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'spirometria_recentemente',
            label: 'Ha fatto una spirometria negli ultimi 12 mesi?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'fev1_percentuale',
            label: 'FEV1 (% se disponibile)',
            type: 'text'
        }
    ],

    Ipertensione: [
        // QUESTIONARIO BASE
        {
            key: 'storia_pressione_alta',
            label: 'Ha mai avuto la pressione alta?',
            type: 'select',
            options: ['No', 'Sì', 'Non so']
        },
        {
            key: 'misura_pressione_regolare',
            label: 'Misura regolarmente la pressione arteriosa?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'assunzione_farmaci_antipertensivi',
            label: 'Assume farmaci antipertensivi?',
            type: 'select',
            options: ['Sì', 'No', 'Non regolarmente']
        },
        {
            key: 'riduzione_sale',
            label: 'Riduce il consumo di sale e cibi salati?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'attivita_fisica_moderata',
            label: 'Svolge attività fisica moderata?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'sintomi_cardiaci',
            label: 'Ha mai avuto dolore al petto, palpitazioni o svenimenti?',
            type: 'select',
            options: ['No', 'Sì']
        },

        // QUESTIONARIO APPROFONDITO
        {
            key: 'valori_pressori_medi',
            label: 'Ultimi valori pressori medi (es. 130/80 mmHg)',
            type: 'text'
        },
        {
            key: 'gonfiore_gambe',
            label: 'Ha episodi di gonfiore a gambe o caviglie?',
            type: 'select',
            options: ['No', 'Sì']
        },
        {
            key: 'familiarita_cardiovascolare',
            label: 'Ha familiarità per ictus o infarto precoce?',
            type: 'select',
            options: ['No', 'Sì']
        },
        {
            key: 'aderenza_terapia_antipertensiva',
            label: 'Assume regolarmente la terapia antipertensiva?',
            type: 'select',
            options: ['Sempre', 'A volte', 'No']
        },
        {
            key: 'ecg_o_telecardiologia',
            label: 'Ha effettuato ECG o telecardiologia negli ultimi 12 mesi?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'effetti_collaterali_terapia',
            label: 'Presenta effetti collaterali dai farmaci (capogiri, tosse, stanchezza)?',
            type: 'select',
            options: ['Sì', 'No']
        }
    ],

    Dislipidemia: [
        // QUESTIONARIO BASE
        {
            key: 'controllo_lipidico_precedente',
            label: 'Ha mai controllato il colesterolo o i trigliceridi?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'terapia_statine_ezetimibe_omega3',
            label: 'È in terapia con statine, ezetimibe o omega 3?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'dieta_povera_grassi',
            label: 'Segue una dieta povera di grassi saturi e zuccheri?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'familiarita_cardiovascolare',
            label: 'Ha casi in famiglia di colesterolo alto, infarto o ictus precoce?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'attivita_fisica_regolare',
            label: 'Fa attività fisica regolare?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'controllo_lipidico_12_mesi',
            label: 'Ha eseguito un controllo lipidico negli ultimi 12 mesi?',
            type: 'select',
            options: ['Sì', 'No']
        },

        // QUESTIONARIO APPROFONDITO
        {
            key: 'colesterolo_totale',
            label: 'Colesterolo Totale (mg/dL)',
            type: 'text'
        },
        {
            key: 'ldl',
            label: 'LDL (mg/dL)',
            type: 'text'
        },
        {
            key: 'hdl',
            label: 'HDL (mg/dL)',
            type: 'text'
        },
        {
            key: 'trigliceridi',
            label: 'Trigliceridi (mg/dL)',
            type: 'text'
        },
        {
            key: 'ipercolesterolemia_familiare',
            label: 'Diagnosi di ipercolesterolemia familiare?',
            type: 'select',
            options: ['Sì', 'No', 'In valutazione']
        },
        {
            key: 'segni_fisici_displipidemia',
            label: 'Presenza di xantomi, arco corneale o xantelasmi?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'aderenza_farmaco',
            label: 'Assunzione regolare del farmaco?',
            type: 'select',
            options: ['Sempre', 'A volte', 'No']
        },
        {
            key: 'effetti_collaterali_statine',
            label: 'Effetti collaterali da statine (dolori muscolari, stanchezza)?',
            type: 'select',
            options: ['Sì', 'No']
        },
        {
            key: 'ultimo_controllo_followup',
            label: 'Ultimo controllo medico e follow-up (data)',
            type: 'text'
        }
    ]

};

const SURVEY_TEMPLATES = window.SURVEY_TEMPLATES;

function getDefaultWizardState() {
    return {
        patient: {},
        primary_condition: null,
        initial_notes: null,
        general_anamnesis: {},
        detailed_intake: {},
        doctor_info: {},
        biometric_info: {},
        therapy_assistants: [],
        adherence_base: {},
        condition_survey: {
            condition_type: null,
            level: 'base',
            answers: {},
            compiled_at: null
        },
        risk_score: null,
        flags: {},
        notes_initial: null,
        follow_up_date: null,
        consent: {
            signer_name: '',
            signer_relation: 'patient',
            signed_at: '',
            pharmacist_name: '',
            place: '',
            scopes: {
                care_followup: false,
                contact_for_reminders: false,
                anonymous_stats: false,
                contact_channel_preference: ''
            },
            signer_role: null
        }
    };
}

function openTherapyWizard(therapyId = null) {
    initWizard(therapyId);
}

async function initWizard(therapyId = null) {
    currentTherapyId = therapyId;
    const defaultState = getDefaultWizardState();
    const draft = loadWizardDraft(therapyId);
    if (draft?.state) {
        therapyWizardState = mergeWizardState(defaultState, draft.state);
        currentWizardStep = normalizeWizardStep(draft.step);
    } else {
        therapyWizardState = defaultState;
        currentWizardStep = 1;
    }

    buildWizardModalShell();
    showWizardModal();

    if (therapyId && !draft?.state) {
        await loadTherapyForEdit(therapyId);
    }

    renderCurrentStep();
}

function buildWizardModalShell() {
    const container = document.getElementById('therapyWizardModal');
    if (!container) return;

    container.className = 'modal fade';
    container.tabIndex = -1;
    container.setAttribute('aria-hidden', 'true');

    container.innerHTML = `
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-md me-2"></i>Wizard Terapia Cronico</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="wizardStepIndicator" class="mb-3 small text-muted d-none"></div>
                    <div id="wizardContent"></div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <div>
                        <button type="button" class="btn btn-outline-secondary" id="wizardPrevBtn"><i class="fas fa-arrow-left me-2"></i>Indietro</button>
                        <button type="button" class="btn btn-outline-danger ms-2" id="wizardResetBtn">Reset/Azzera tutto</button>
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline-primary" id="wizardNextBtn">Avanti<i class="fas fa-arrow-right ms-2"></i></button>
                        <button type="button" class="btn btn-primary d-none" id="wizardSubmitBtn"><i class="fas fa-save me-2"></i>Conferma e salva</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    if (!container.dataset.draftListeners) {
        container.addEventListener('hide.bs.modal', () => {
            if (skipDraftOnHide) {
                skipDraftOnHide = false;
                return;
            }
            persistWizardDraft({ immediate: true });
        });

        container.addEventListener('hidden.bs.modal', () => {
            container.innerHTML = '';
        });

        container.addEventListener('input', handleWizardFieldChange);
        container.addEventListener('change', handleWizardFieldChange);
        container.dataset.draftListeners = 'true';
    }

    const prevBtn = container.querySelector('#wizardPrevBtn');
    const nextBtn = container.querySelector('#wizardNextBtn');
    const submitBtn = container.querySelector('#wizardSubmitBtn');
    const resetBtn = container.querySelector('#wizardResetBtn');

    if (prevBtn) prevBtn.addEventListener('click', prevStep);
    if (nextBtn) nextBtn.addEventListener('click', nextStep);
    if (submitBtn) submitBtn.addEventListener('click', submitTherapy);
    if (resetBtn) resetBtn.addEventListener('click', resetWizardDraft);
}

function handleWizardFieldChange(event) {
    if (!event.target.closest('#wizardContent')) return;
    persistWizardDraft();
}

function resetWizardDraft() {
    const confirmed = confirm('Vuoi azzerare tutti i dati del wizard?');
    if (!confirmed) return;
    clearWizardDraft();
    therapyWizardState = getDefaultWizardState();
    currentWizardStep = 1;
    renderCurrentStep();
}

function showWizardModal() {
    const container = document.getElementById('therapyWizardModal');
    if (!container) return;
    wizardModalInstance = new bootstrap.Modal(container);
    wizardModalInstance.show();
}

async function loadTherapyForEdit(therapyId) {
    try {
        const response = await fetch(`api/therapies.php?id=${therapyId}`);
        const result = await response.json();
        if (result.success && Array.isArray(result.data?.items) && result.data.items.length) {
            const t = result.data.items[0];
            const defaultConsent = getDefaultWizardState().consent || {};
            const defaultSurvey = getDefaultWizardState().condition_survey || {};
            const existingPatient = therapyWizardState.patient || {};
            therapyWizardState.patient = {
                id: t.patient_id,
                first_name: t.first_name,
                last_name: t.last_name,
                birth_date: t.birth_date ?? existingPatient.birth_date ?? '',
                codice_fiscale: t.codice_fiscale || '',
                phone: t.phone ?? existingPatient.phone ?? '',
                email: t.email ?? existingPatient.email ?? '',
                notes: t.notes ?? existingPatient.notes ?? ''
            };
            if (t.primary_condition) {
                therapyWizardState.primary_condition = t.primary_condition;
            }
            therapyWizardState.initial_notes = t.therapy_description || null;
            therapyWizardState.notes_initial = t.notes_initial || therapyWizardState.notes_initial;
            therapyWizardState.follow_up_date = t.follow_up_date || null;
            therapyWizardState.risk_score = t.risk_score ?? therapyWizardState.risk_score;
            if (t.flags !== null && t.flags !== undefined) {
                therapyWizardState.flags = t.flags;
            }
            if (t.general_anamnesis !== null && t.general_anamnesis !== undefined) {
                therapyWizardState.general_anamnesis = t.general_anamnesis;
            }
            if (t.detailed_intake !== null && t.detailed_intake !== undefined) {
                therapyWizardState.detailed_intake = t.detailed_intake;
            }
            if (t.adherence_base !== null && t.adherence_base !== undefined) {
                therapyWizardState.adherence_base = t.adherence_base;
            }
            if (t.consent !== null && t.consent !== undefined) {
                therapyWizardState.consent = {
                    ...defaultConsent,
                    ...t.consent,
                    scopes: {
                        ...(defaultConsent.scopes || {}),
                        ...(t.consent?.scopes || {})
                    },
                    signatures: {
                        ...(defaultConsent.signatures || {}),
                        ...(t.consent?.signatures || {})
                    }
                };
            }
            if (t.condition_survey) {
                therapyWizardState.condition_survey = {
                    ...defaultSurvey,
                    ...t.condition_survey,
                    answers: {
                        ...(defaultSurvey.answers || {}),
                        ...(t.condition_survey.answers || {})
                    }
                };
            }
            if (!therapyWizardState.condition_survey?.condition_type && therapyWizardState.primary_condition) {
                therapyWizardState.condition_survey.condition_type = therapyWizardState.primary_condition;
            }
            if (t.doctor_info !== null && t.doctor_info !== undefined) {
                therapyWizardState.doctor_info = t.doctor_info;
            }
            if (t.biometric_info !== null && t.biometric_info !== undefined) {
                therapyWizardState.biometric_info = t.biometric_info;
            }
            if (Array.isArray(t.therapy_assistants)) {
                therapyWizardState.therapy_assistants = t.therapy_assistants;
            }
            persistWizardDraft({ immediate: true, collectCurrent: false });
        }
    } catch (error) {
        console.error('Errore caricamento terapia', error);
    }
}

function renderCurrentStep() {
    updateStepIndicator();
    switch (currentWizardStep) {
        case 1:
            renderStep1();
            break;
        case 2:
            renderStep2();
            break;
        case 3:
            renderStep3();
            break;
        case 4:
            renderStep4();
            break;
        case 5:
            renderStep5();
            break;
        case 6:
            renderStep6();
            break;
        default:
            renderStep1();
    }
    updateNavigationButtons();
}

function updateStepIndicator() {
    const indicator = document.getElementById('wizardStepIndicator');
    if (!indicator) return;
    indicator.textContent = `Step ${currentWizardStep} di ${totalWizardSteps}`;
}

function updateNavigationButtons() {
    const prevBtn = document.getElementById('wizardPrevBtn');
    const nextBtn = document.getElementById('wizardNextBtn');
    const submitBtn = document.getElementById('wizardSubmitBtn');

    if (prevBtn) prevBtn.disabled = currentWizardStep === 1;
    if (nextBtn) nextBtn.classList.toggle('d-none', currentWizardStep === totalWizardSteps);
    if (submitBtn) submitBtn.classList.toggle('d-none', currentWizardStep !== totalWizardSteps);
}

function nextStep() {
    if (!validateStep(currentWizardStep)) return;

    // Persist data of the step we are leaving before rendering the next one
    switch (currentWizardStep) {
        case 1:
            collectStep1Data();
            break;
        case 3:
            collectStep3Data();
            break;
        case 4:
            collectStep4Data();
            break;
        case 5:
            collectStep5Data();
            break;
        case 6:
            collectStep6Data();
            break;
        default:
            break;
    }

    if (currentWizardStep < totalWizardSteps) {
        currentWizardStep += 1;
        renderCurrentStep();
    }
    persistWizardDraft({ immediate: true, collectCurrent: false });
}

function prevStep() {
    // Persist data before going back so user input is not lost
    switch (currentWizardStep) {
        case 3:
            collectStep3Data();
            break;
        case 4:
            collectStep4Data();
            break;
        case 5:
            collectStep5Data();
            break;
        case 6:
            collectStep6Data();
            break;
        default:
            break;
    }

    if (currentWizardStep > 1) {
        currentWizardStep -= 1;
        renderCurrentStep();
    }
    persistWizardDraft({ immediate: true, collectCurrent: false });
}

function validateStep(step) {
    if (step === 1) {
        collectStep1Data();
        const patientValid = therapyWizardState.patient && (therapyWizardState.patient.id || (therapyWizardState.patient.first_name && therapyWizardState.patient.last_name));
        const conditionValid = !!therapyWizardState.primary_condition;
        if (!patientValid) {
            alert('Seleziona o inserisci un paziente.');
            return false;
        }
        if (!conditionValid) {
            alert('Seleziona la patologia principale.');
            return false;
        }
    }
    if (step === 6) {
        const gdprCareFollowup = document.getElementById('consentCareFollowup')?.checked ?? false;
        const gdprContact = document.getElementById('consentContact')?.checked ?? false;
        const gdprAnonymous = document.getElementById('consentAnonymous')?.checked ?? false;
        const signerName = document.getElementById('signerName')?.value?.trim() || '';
        const signedAt = document.getElementById('signedAt')?.value || '';

        therapyWizardState.consent = therapyWizardState.consent || {};
        therapyWizardState.consent.scopes = therapyWizardState.consent.scopes || {};
        therapyWizardState.consent.scopes.care_followup = gdprCareFollowup;
        therapyWizardState.consent.scopes.contact_for_reminders = gdprContact;
        therapyWizardState.consent.scopes.anonymous_stats = gdprAnonymous;
        therapyWizardState.consent.scopes.gdpr_accepted =
            gdprCareFollowup && gdprContact && gdprAnonymous;

        if (!gdprCareFollowup || !gdprContact || !gdprAnonymous) {
            alert('Il consenso GDPR è obbligatorio per procedere.');
            return false;
        }
        if (!signerName || !signedAt) {
            alert('Compila almeno firma paziente/caregiver e data per procedere.');
            return false;
        }
    }
    return true;
}

function renderStep1() {
    const content = document.getElementById('wizardContent');
    if (!content) return;

    const patient = therapyWizardState.patient || {};
    const general = therapyWizardState.general_anamnesis || {};
    const detailed = therapyWizardState.detailed_intake || {};
    const doctorInfo = therapyWizardState.doctor_info || {};
    const biometricInfo = therapyWizardState.biometric_info || {};
    const primary_condition = therapyWizardState.primary_condition || '';

    const age = patient.birth_date ? calculateAgeFromBirthDate(patient.birth_date) : '';
    const ageLabel = age === '' ? '-' : age;

    content.innerHTML = `
        <div class="mb-4">
            <h5 class="mb-3">Dati paziente</h5>
            <div class="mb-3">
                <label class="form-label">Cerca paziente</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="patientSearchInput" placeholder="Nome, cognome o codice fiscale">
                    <button class="btn btn-outline-secondary" type="button" id="patientSearchBtn">Cerca</button>
                </div>
                <div class="mt-2" id="patientSearchResults"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nome *</label>
                    <input type="text" class="form-control" id="patientFirstName" value="${escapeHtml(patient.first_name || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Cognome *</label>
                    <input type="text" class="form-control" id="patientLastName" value="${escapeHtml(patient.last_name || '')}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Data di nascita</label>
                    <input type="date" class="form-control" id="patientBirthDate" value="${escapeHtml(patient.birth_date || '')}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Codice fiscale</label>
                    <input type="text" class="form-control" id="patientCF" value="${escapeHtml(patient.codice_fiscale || '')}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sesso</label>
                    <select class="form-select" id="patientGender">
                        <option value="">Seleziona</option>
                        <option value="M" ${patient.gender === 'M' ? 'selected' : ''}>M</option>
                        <option value="F" ${patient.gender === 'F' ? 'selected' : ''}>F</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Telefono</label>
                    <input type="text" class="form-control" id="patientPhone" value="${escapeHtml(patient.phone || '')}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" id="patientEmail" value="${escapeHtml(patient.email || '')}">
                </div>
                <div class="col-12">
                    <label class="form-label">Note iniziali</label>
                    <textarea class="form-control" id="patientNotes" rows="2">${escapeHtml(therapyWizardState.initial_notes || '')}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Patologia seguita *</label>
                    <select class="form-select" id="primaryCondition">
                        <option value="">Seleziona</option>
                        <option value="Diabete" ${primary_condition === 'Diabete' ? 'selected' : ''}>Diabete</option>
                        <option value="BPCO" ${primary_condition === 'BPCO' ? 'selected' : ''}>BPCO</option>
                        <option value="Ipertensione" ${primary_condition === 'Ipertensione' ? 'selected' : ''}>Ipertensione</option>
                        <option value="Dislipidemia" ${primary_condition === 'Dislipidemia' ? 'selected' : ''}>Dislipidemia</option>
                        <option value="Altro" ${primary_condition === 'Altro' ? 'selected' : ''}>Altro</option>
                    </select>
                    <input type="text" class="form-control mt-2" id="primaryConditionOther" placeholder="Specificare se Altro" value="${primary_condition && !['Diabete','BPCO','Ipertensione','Dislipidemia','Altro'].includes(primary_condition) ? escapeHtml(primary_condition) : ''}">
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h5 class="mb-3">Contesto familiare / sociale</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Membri famiglia</label>
                    <input type="number" class="form-control" id="familyMembers" value="${escapeHtml(general.family_members_count || '')}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Supporto esterno</label>
                    <select class="form-select" id="externalSupport">
                        <option value="">Seleziona</option>
                        <option value="true" ${general.has_external_support ? 'selected' : ''}>Sì</option>
                        <option value="false" ${general.has_external_support === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Titolo di studio</label>
                    <input type="text" class="form-control" id="educationLevel" value="${escapeHtml(general.education_level || '')}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Presenza caregiver</label>
                    <select class="form-select" id="hasCaregiver">
                        <option value="">Seleziona</option>
                        <option value="true" ${general.has_caregiver ? 'selected' : ''}>Sì</option>
                        <option value="false" ${general.has_caregiver === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Riferimenti del medico curante</label>
                    <textarea class="form-control" id="gpReference" rows="2">${escapeHtml(doctorInfo.gp_reference || '')}</textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Riferimenti dello specialista</label>
                    <textarea class="form-control" id="specialistReference" rows="2">${escapeHtml(doctorInfo.specialist_reference || '')}</textarea>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h5 class="mb-3">Profilo clinico e stile di vita</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Età</label>
                    <div class="form-control-plaintext" id="patientAgeValue">${escapeHtml(ageLabel)}</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Stato ormonale donna</label>
                    <select class="form-select" id="femaleStatus">
                        <option value=""></option>
                        <option value="fertile" ${general.female_status === 'fertile' ? 'selected' : ''}>Età fertile</option>
                        <option value="menopausa" ${general.female_status === 'menopausa' ? 'selected' : ''}>Menopausa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Stato di salute percepito</label>
                    <select class="form-select" id="selfRatedHealth">
                        <option value="">Seleziona</option>
                        <option value="buono" ${general.self_rated_health === 'buono' ? 'selected' : ''}>Buono</option>
                        <option value="discreto" ${general.self_rated_health === 'discreto' ? 'selected' : ''}>Discreto</option>
                        <option value="scarso" ${general.self_rated_health === 'scarso' ? 'selected' : ''}>Scarso</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fumo</label>
                    <select class="form-select" id="smokingStatus">
                        <option value="">Seleziona</option>
                        <option value="no" ${general.smoking_status === 'no' ? 'selected' : ''}>No</option>
                        <option value="ex" ${general.smoking_status === 'ex' ? 'selected' : ''}>Ex fumatore</option>
                        <option value="si" ${general.smoking_status === 'si' ? 'selected' : ''}>Sì</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sigarette/giorno</label>
                    <input type="number" class="form-control" id="cigarettesPerDay" value="${escapeHtml(general.cigarettes_per_day || '')}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Attività fisica regolare</label>
                    <select class="form-select" id="physicalActivity">
                        <option value="">Seleziona</option>
                        <option value="true" ${general.physical_activity_regular ? 'selected' : ''}>Sì</option>
                        <option value="false" ${general.physical_activity_regular === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Dieta particolare / controllo</label>
                    <select class="form-select" id="dietControl">
                        <option value="">Seleziona</option>
                        <option value="si" ${general.diet_control === 'si' ? 'selected' : ''}>Sì</option>
                        <option value="no" ${general.diet_control === 'no' ? 'selected' : ''}>No</option>
                        <option value="a_volte" ${general.diet_control === 'a_volte' ? 'selected' : ''}>A volte</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Farmaci continuativi</label>
                    <select class="form-select" id="chronicTherapy">
                        <option value="">Seleziona</option>
                        <option value="no" ${general.chronic_therapy_regimen === 'no' ? 'selected' : ''}>No</option>
                        <option value="1-2" ${general.chronic_therapy_regimen === '1-2' ? 'selected' : ''}>1–2</option>
                        <option value="3-5" ${general.chronic_therapy_regimen === '3-5' ? 'selected' : ''}>3–5</option>
                        <option value=">5" ${general.chronic_therapy_regimen === '>5' ? 'selected' : ''}>>5</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Difficoltà a gestire la terapia</label>
                    <select class="form-select" id="therapyDifficulty">
                        <option value="">Seleziona</option>
                        <option value="no" ${general.therapy_management_difficulty === 'no' ? 'selected' : ''}>No</option>
                        <option value="talvolta" ${general.therapy_management_difficulty === 'talvolta' ? 'selected' : ''}>Talvolta</option>
                        <option value="spesso" ${general.therapy_management_difficulty === 'spesso' ? 'selected' : ''}>Spesso</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Patologie diagnosticate</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="condDiabete" ${general.diagnosed_conditions?.diabete ? 'checked' : ''}>
                        <label class="form-check-label" for="condDiabete">Diabete</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="condIpertensione" ${general.diagnosed_conditions?.ipertensione ? 'checked' : ''}>
                        <label class="form-check-label" for="condIpertensione">Ipertensione</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="condBpco" ${general.diagnosed_conditions?.bpco ? 'checked' : ''}>
                        <label class="form-check-label" for="condBpco">BPCO</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="condDislipidemia" ${general.diagnosed_conditions?.dislipidemia ? 'checked' : ''}>
                        <label class="form-check-label" for="condDislipidemia">Dislipidemia</label>
                    </div>
                    <div class="mt-2">
                        <input type="text" class="form-control" id="condAltro" placeholder="Altro" value="${escapeHtml(general.diagnosed_conditions?.altro || '')}">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Familiarità infarto/ictus/diabete/colesterolo</label>
                    <select class="form-select" id="familyHistoryCvd">
                        <option value="">Seleziona</option>
                        <option value="si" ${general.family_history_cvd === 'si' ? 'selected' : ''}>Sì</option>
                        <option value="no" ${general.family_history_cvd === 'no' ? 'selected' : ''}>No</option>
                        <option value="non_so" ${general.family_history_cvd === 'non_so' ? 'selected' : ''}>Non so</option>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <h6 class="mb-2">Parametri biometrici</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Peso (kg)</label>
                        <input type="number" step="0.1" class="form-control" id="bioWeight" value="${escapeHtml(biometricInfo.weight_kg || '')}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Altezza (cm)</label>
                        <input type="number" step="0.1" class="form-control" id="bioHeight" value="${escapeHtml(biometricInfo.height_cm || '')}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">BMI</label>
                        <input type="number" step="0.1" class="form-control" id="bioBmi" value="${escapeHtml(biometricInfo.bmi || '')}">
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h5 class="mb-3">Anamnesi terapeutica e comportamentale</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Allergie (farmaci/lattice/alimenti/vaccini)</label>
                    <textarea class="form-control" id="anaAllergies" rows="2">${escapeHtml(general.allergies || '')}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Accessi PS</label>
                    <input type="text" class="form-control" id="anaErAccess" value="${escapeHtml(general.er_access || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Esami eseguiti (ECG/Holter)</label>
                    <input type="text" class="form-control" id="anaExams" value="${escapeHtml(general.exams || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Storico vaccini/reazioni</label>
                    <input type="text" class="form-control" id="anaVaccines" value="${escapeHtml(general.vaccines || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Aiuto nella gestione terapia</label>
                    <select class="form-select" id="intakeHelper">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.has_helper_for_medication ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.has_helper_for_medication === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Modifiche dosaggio ultimo mese</label>
                    <select class="form-select" id="doseChanges">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.dose_changes_last_month ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.dose_changes_last_month === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Modifiche di propria iniziativa</label>
                    <select class="form-select" id="doseChangesSelf">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.dose_changes_self_initiated ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.dose_changes_self_initiated === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipologia farmaci assunti</label>
                    <input type="text" class="form-control" id="drugTypes" value="${escapeHtml(detailed.drug_types || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Dimenticanze</label>
                    <input type="text" class="form-control" id="forgetsMeds" value="${escapeHtml(detailed.forgets_medications || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Comportamento quando dimentica</label>
                    <input type="text" class="form-control" id="behaviourWhenForgets" value="${escapeHtml(detailed.behaviour_when_forgets || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Interruzioni intenzionali</label>
                    <select class="form-select" id="intentionalSkips">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.intentional_skips ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.intentional_skips === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Motivo interruzione</label>
                    <input type="text" class="form-control" id="intentionalStopReason" value="${escapeHtml(detailed.intentional_stop_reason || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Uso integratori/omeopatici/fitoterapici</label>
                    <select class="form-select" id="usesSupplements">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.uses_supplements ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.uses_supplements === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Dettagli integratori</label>
                    <input type="text" class="form-control" id="supplementsDetails" value="${escapeHtml(detailed.supplements_details || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Frequenza integratori</label>
                    <input type="text" class="form-control" id="supplementsFrequency" value="${escapeHtml(detailed.supplements_frequency || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Uso device BPCO</label>
                    <select class="form-select" id="usesBPCODevice">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.uses_bpcop_device ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.uses_bpcop_device === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Sa usare il device</label>
                    <select class="form-select" id="knowsDevice">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.knows_how_to_use_device ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.knows_how_to_use_device === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Problemi device</label>
                    <input type="text" class="form-control" id="deviceProblems" value="${escapeHtml(detailed.device_problems || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Uso automisurazione pressione</label>
                    <select class="form-select" id="selfMeasureBP">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.uses_self_measure_bp ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.uses_self_measure_bp === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Frequenza in farmacia (pressione)</label>
                    <input type="text" class="form-control" id="pharmacyBPFrequency" value="${escapeHtml(detailed.pharmacy_bp_frequency || '')}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Ha misurato la glicemia</label>
                    <select class="form-select" id="everMeasuredGlycemia">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.ever_measured_glycemia ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.ever_measured_glycemia === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Reazioni allergiche farmaci/integratori</label>
                    <select class="form-select" id="drugAllergicReactions">
                        <option value="">Seleziona</option>
                        <option value="true" ${detailed.drug_or_supplement_allergic_reactions ? 'selected' : ''}>Sì</option>
                        <option value="false" ${detailed.drug_or_supplement_allergic_reactions === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
            </div>
        </div>
    `;

    bindStep1Events();
}

function bindStep1Events() {
    const searchBtn = document.getElementById('patientSearchBtn');
    const searchInput = document.getElementById('patientSearchInput');
    if (searchBtn) searchBtn.addEventListener('click', searchPatients);
    if (searchInput) searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchPatients();
        }
    });

    const weightInput = document.getElementById('bioWeight');
    const heightInput = document.getElementById('bioHeight');
    if (weightInput) weightInput.addEventListener('input', updateBmiFromInputs);
    if (heightInput) heightInput.addEventListener('input', updateBmiFromInputs);

    const birthDateInput = document.getElementById('patientBirthDate');
    const ageValue = document.getElementById('patientAgeValue');
    if (birthDateInput && ageValue) {
        const updateAgeLabel = () => {
            const birthDate = birthDateInput.value || '';
            const calculated = birthDate ? calculateAgeFromBirthDate(birthDate) : '';
            ageValue.textContent = calculated === '' ? '-' : calculated;
        };
        birthDateInput.addEventListener('input', updateAgeLabel);
        birthDateInput.addEventListener('change', updateAgeLabel);
    }
}

async function searchPatients() {
    const query = document.getElementById('patientSearchInput')?.value || '';
    const resultsBox = document.getElementById('patientSearchResults');
    if (!resultsBox) return;
    resultsBox.innerHTML = '<div class="text-muted">Ricerca in corso...</div>';
    try {
        const resp = await fetch(`api/patients.php?q=${encodeURIComponent(query)}`);
        const data = await resp.json();
        if (!data.success) {
            resultsBox.innerHTML = `<div class="text-danger">${escapeHtml(data.error || 'Errore ricerca pazienti')}</div>`;
            return;
        }
        const items = data.data?.items || [];
        if (!items.length) {
            resultsBox.innerHTML = '<div class="text-muted">Nessun paziente trovato</div>';
            return;
        }
        resultsBox.innerHTML = items.map((p) => `
            <div class="form-check">
                <input class="form-check-input" type="radio" name="patientSelect" value="${p.id}" data-patient='${JSON.stringify(p)}'>
                <label class="form-check-label">${escapeHtml(p.first_name)} ${escapeHtml(p.last_name)} - ${escapeHtml(p.codice_fiscale || '')}</label>
            </div>
        `).join('');
        resultsBox.querySelectorAll('input[name="patientSelect"]').forEach((radio) => {
            radio.addEventListener('change', (e) => {
                const payload = JSON.parse(e.target.getAttribute('data-patient'));
                therapyWizardState.patient = payload;
                therapyWizardState.primary_condition = therapyWizardState.primary_condition || null;
                renderStep1();
                persistWizardDraft({ immediate: true, collectCurrent: false });
            });
        });
    } catch (err) {
        resultsBox.innerHTML = '<div class="text-danger">Errore di rete</div>';
    }
}

function collectStep1Data() {
    const patient = therapyWizardState.patient || {};
    patient.first_name = document.getElementById('patientFirstName')?.value?.trim() || '';
    patient.last_name = document.getElementById('patientLastName')?.value?.trim() || '';
    patient.birth_date = document.getElementById('patientBirthDate')?.value || '';
    patient.codice_fiscale = document.getElementById('patientCF')?.value?.trim() || '';
    patient.phone = document.getElementById('patientPhone')?.value?.trim() || '';
    patient.email = document.getElementById('patientEmail')?.value?.trim() || '';
    patient.notes = document.getElementById('patientNotes')?.value || '';
    patient.gender = document.getElementById('patientGender')?.value || '';
    therapyWizardState.patient = patient;

    therapyWizardState.initial_notes = document.getElementById('patientNotes')?.value || '';
    const primary = document.getElementById('primaryCondition')?.value || '';
    const other = document.getElementById('primaryConditionOther')?.value || '';
    therapyWizardState.primary_condition = primary === 'Altro' && other ? other : (primary || other || null);

    therapyWizardState.general_anamnesis = {
        family_members_count: toNumberOrNull(document.getElementById('familyMembers')?.value),
        has_external_support: toBool(document.getElementById('externalSupport')?.value),
        education_level: document.getElementById('educationLevel')?.value || '',
        has_caregiver: toBool(document.getElementById('hasCaregiver')?.value),
        allergies: document.getElementById('anaAllergies')?.value || '',
        er_access: document.getElementById('anaErAccess')?.value || '',
        exams: document.getElementById('anaExams')?.value || '',
        vaccines: document.getElementById('anaVaccines')?.value || '',
        female_status: document.getElementById('femaleStatus')?.value || '',
        self_rated_health: document.getElementById('selfRatedHealth')?.value || '',
        smoking_status: document.getElementById('smokingStatus')?.value || '',
        cigarettes_per_day: toNumberOrNull(document.getElementById('cigarettesPerDay')?.value),
        physical_activity_regular: toBool(document.getElementById('physicalActivity')?.value),
        diet_control: document.getElementById('dietControl')?.value || '',
        chronic_therapy_regimen: document.getElementById('chronicTherapy')?.value || '',
        therapy_management_difficulty: document.getElementById('therapyDifficulty')?.value || '',
        diagnosed_conditions: {
            diabete: !!document.getElementById('condDiabete')?.checked,
            ipertensione: !!document.getElementById('condIpertensione')?.checked,
            bpco: !!document.getElementById('condBpco')?.checked,
            dislipidemia: !!document.getElementById('condDislipidemia')?.checked,
            altro: document.getElementById('condAltro')?.value || ''
        },
        family_history_cvd: document.getElementById('familyHistoryCvd')?.value || ''
    };

    therapyWizardState.detailed_intake = {
        has_helper_for_medication: toBool(document.getElementById('intakeHelper')?.value),
        dose_changes_last_month: toBool(document.getElementById('doseChanges')?.value),
        dose_changes_self_initiated: toBool(document.getElementById('doseChangesSelf')?.value),
        drug_types: document.getElementById('drugTypes')?.value || '',
        forgets_medications: document.getElementById('forgetsMeds')?.value || '',
        behaviour_when_forgets: document.getElementById('behaviourWhenForgets')?.value || '',
        intentional_skips: toBool(document.getElementById('intentionalSkips')?.value),
        intentional_stop_reason: document.getElementById('intentionalStopReason')?.value || '',
        uses_supplements: toBool(document.getElementById('usesSupplements')?.value),
        supplements_details: document.getElementById('supplementsDetails')?.value || '',
        supplements_frequency: document.getElementById('supplementsFrequency')?.value || '',
        uses_bpcop_device: toBool(document.getElementById('usesBPCODevice')?.value),
        knows_how_to_use_device: toBool(document.getElementById('knowsDevice')?.value),
        device_problems: document.getElementById('deviceProblems')?.value || '',
        uses_self_measure_bp: toBool(document.getElementById('selfMeasureBP')?.value),
        pharmacy_bp_frequency: document.getElementById('pharmacyBPFrequency')?.value || '',
        ever_measured_glycemia: toBool(document.getElementById('everMeasuredGlycemia')?.value),
        drug_or_supplement_allergic_reactions: toBool(document.getElementById('drugAllergicReactions')?.value)
    };

    therapyWizardState.doctor_info = {
        gp_reference: document.getElementById('gpReference')?.value || '',
        specialist_reference: document.getElementById('specialistReference')?.value || ''
    };

    therapyWizardState.biometric_info = {
        weight_kg: toNumberOrNull(document.getElementById('bioWeight')?.value),
        height_cm: toNumberOrNull(document.getElementById('bioHeight')?.value),
        bmi: toNumberOrNull(document.getElementById('bioBmi')?.value)
    };
}

function renderStep2() {
    collectStep1Data();
    const content = document.getElementById('wizardContent');
    if (!content) return;

    const assistants = therapyWizardState.therapy_assistants || [];

    content.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Caregiver e familiari</h5>
            <button class="btn btn-sm btn-outline-primary" id="btnAddAssistant">Aggiungi nuovo assistente</button>
        </div>

        <div id="assistantsList" class="mb-4"></div>
        <div id="assistantForms" class="mb-4"></div>

        <div id="newAssistantForm" class="card mb-4 d-none">
            <div class="card-body">
                <h6 class="mb-3">Nuovo assistente</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome *</label>
                        <input type="text" class="form-control" id="assistantFirstName">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cognome</label>
                        <input type="text" class="form-control" id="assistantLastName">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefono</label>
                        <input type="text" class="form-control" id="assistantPhone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" id="assistantEmail">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" id="assistantType">
                            <option value="familiare">Familiare</option>
                            <option value="caregiver">Caregiver</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Relazione con il paziente</label>
                        <input type="text" class="form-control" id="assistantRelation">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Canale di contatto preferito</label>
                        <select class="form-select" id="assistantPreferredContact">
                            <option value="">Seleziona</option>
                            <option value="phone">Telefono</option>
                            <option value="email">Email</option>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Note</label>
                        <textarea class="form-control" id="assistantNotes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" id="cancelAssistantBtn">Annulla</button>
                <button type="button" class="btn btn-primary" id="saveAssistantBtn">Salva</button>
            </div>
        </div>
    `;

    fetchAssistants();

    const addBtn = document.getElementById('btnAddAssistant');
    if (addBtn) {
        addBtn.addEventListener('click', () => {
            const formCard = document.getElementById('newAssistantForm');
            if (formCard) {
                formCard.classList.remove('d-none');
            }
        });
    }

    const cancelBtn = document.getElementById('cancelAssistantBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            hideAndResetAssistantForm();
        });
    }

    const saveBtn = document.getElementById('saveAssistantBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', saveNewAssistant);
    }

        renderAssistantForms(assistants);
    }

function renderAssistantForms(list) {
    const container = document.getElementById('assistantForms');
    if (!container) return;
    if (!list.length) {
        container.innerHTML = '<div class="text-muted">Nessun assistente selezionato</div>';
        return;
    }
    container.innerHTML = list.map((a, idx) => `
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <strong>${escapeHtml(a.full_name || `${a.first_name || ''} ${a.last_name || ''}`)}</strong>
                    <span class="badge bg-light text-dark">${escapeHtml(a.role || a.type || '')}</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Ruolo</label>
                        <select class="form-select" data-assistant-index="${idx}" data-field="role">
                            <option value="caregiver" ${a.role === 'caregiver' ? 'selected' : ''}>Caregiver</option>
                            <option value="familiare" ${a.role === 'familiare' ? 'selected' : ''}>Familiare</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Canale contatto</label>
                        <select class="form-select" data-assistant-index="${idx}" data-field="contact_channel">
                            <option value="">Seleziona</option>
                            <option value="phone" ${a.contact_channel === 'phone' ? 'selected' : ''}>Telefono</option>
                            <option value="email" ${a.contact_channel === 'email' ? 'selected' : ''}>Email</option>
                            <option value="whatsapp" ${a.contact_channel === 'whatsapp' ? 'selected' : ''}>WhatsApp</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    `).join('');

    container.querySelectorAll('select, input').forEach((el) => {
        el.addEventListener('change', (e) => {
            const idx = parseInt(e.target.getAttribute('data-assistant-index'), 10);
            const field = e.target.getAttribute('data-field');
            if (Number.isNaN(idx) || !field) return;
            const val = e.target.value;
            const assistant = therapyWizardState.therapy_assistants[idx] || {};
            if (['role', 'contact_channel'].includes(field)) {
                assistant[field] = val || null;
            } else {
                assistant.consents_json = assistant.consents_json || {};
                assistant.consents_json[field] = val || '';
            }
            therapyWizardState.therapy_assistants[idx] = assistant;
            persistWizardDraft();
        });
    });
}

async function fetchAssistants() {
    const listContainer = document.getElementById('assistantsList');
    if (!listContainer) return;
    listContainer.innerHTML = '<div class="text-muted">Caricamento assistenti...</div>';
    try {
        const resp = await fetch('api/assistants.php');
        const data = await resp.json();
        if (!data.success) {
            listContainer.innerHTML = `<div class="text-danger">${escapeHtml(data.error || 'Errore caricamento assistenti')}</div>`;
            return;
        }
        const items = data.data?.items || data.data || [];
        if (!items.length) {
            listContainer.innerHTML = '<div class="text-muted">Nessun assistente registrato</div>';
            return;
        }
        listContainer.innerHTML = items.map((a) => `
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" value="${a.id}" id="assistant_${a.id}">
                <label class="form-check-label" for="assistant_${a.id}">${escapeHtml(a.first_name)} ${escapeHtml(a.last_name || '')} (${escapeHtml(a.type)})</label>
            </div>
        `).join('');

        // Preselect existing
        therapyWizardState.therapy_assistants.forEach((a) => {
            const cb = document.getElementById(`assistant_${a.assistant_id}`);
            if (cb) cb.checked = true;
        });

        listContainer.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            cb.addEventListener('change', (e) => {
                const id = parseInt(e.target.value, 10);
                const found = therapyWizardState.therapy_assistants.find((x) => x.assistant_id === id);
                if (e.target.checked && !found) {
                    const assistantData = items.find((x) => x.id === id) || {};
                    therapyWizardState.therapy_assistants.push({
                        assistant_id: id,
                        first_name: assistantData.first_name,
                        last_name: assistantData.last_name,
                        role: assistantData.type || 'familiare',
                        contact_channel: assistantData.preferred_contact || null,
                        preferences_json: {},
                        consents_json: {}
                    });
                }
                if (!e.target.checked && found) {
                    therapyWizardState.therapy_assistants = therapyWizardState.therapy_assistants.filter((x) => x.assistant_id !== id);
                }
                renderAssistantForms(therapyWizardState.therapy_assistants);
                persistWizardDraft({ immediate: true, collectCurrent: false });
            });
        });
    } catch (err) {
        listContainer.innerHTML = '<div class="text-danger">Errore di rete</div>';
    }
}

async function saveNewAssistant(event) {
   if (event) event.preventDefault();
    const payload = {
        first_name: document.getElementById('assistantFirstName')?.value?.trim() || '',
        last_name: document.getElementById('assistantLastName')?.value?.trim() || '',
        phone: document.getElementById('assistantPhone')?.value?.trim() || '',
        email: document.getElementById('assistantEmail')?.value?.trim() || '',
        type: document.getElementById('assistantType')?.value || 'familiare',
        relation_to_patient: document.getElementById('assistantRelation')?.value || '',
        preferred_contact: document.getElementById('assistantPreferredContact')?.value || '',
        notes: document.getElementById('assistantNotes')?.value || ''
    };

    if (!payload.first_name) {
        alert('Il nome assistente è obbligatorio');
        return;
    }

    try {
        const resp = await fetch('api/assistants.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (data.success) {
            hideAndResetAssistantForm();
            fetchAssistants();
        } else {
            alert(data.error || 'Errore salvataggio assistente');
        }
    } catch (err) {
        alert('Errore di rete');
    }
}

function hideAndResetAssistantForm() {
  const formCard = document.getElementById('newAssistantForm');
  if (formCard) {
      formCard.classList.add('d-none');
  }
  const fields = [
      'assistantFirstName',
      'assistantLastName',
      'assistantPhone',
      'assistantEmail',
      'assistantType',
      'assistantRelation',
      'assistantPreferredContact',
      'assistantNotes',
  ];
  fields.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === 'SELECT') el.value = '';
      else if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') el.value = '';
  });
}

function renderStep3() {
    const content = document.getElementById('wizardContent');
    if (!content) return;
    const a = therapyWizardState.adherence_base || {};
    content.innerHTML = `
        <h5 class="mb-3">Questionario di aderenza base</h5>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Terapie in corso (principi attivi)</label>
                <input type="text" class="form-control" id="adCurrentTherapies" value="${escapeHtml(a.current_therapies || '')}">
            </div>
            <div class="col-md-6">
                <label class="form-label">Dispositivo utilizzato</label>
                <input type="text" class="form-control" id="adDevicesUsed" value="${escapeHtml(a.devices_used || '')}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Dimentica di assumere le dosi?</label>
                <select class="form-select" id="adForgets">
                    <option value="">Seleziona</option>
                    <option value="Mai" ${a.forgets_doses === 'Mai' ? 'selected' : ''}>Mai</option>
                    <option value="Talvolta" ${a.forgets_doses === 'Talvolta' ? 'selected' : ''}>Talvolta</option>
                    <option value="Spesso" ${a.forgets_doses === 'Spesso' ? 'selected' : ''}>Spesso</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Interrompe terapia quando si sente meglio?</label>
                <select class="form-select" id="adStopsBetter">
                    <option value="">Seleziona</option>
                    <option value="true" ${a.stops_when_better ? 'selected' : ''}>Sì</option>
                    <option value="false" ${a.stops_when_better === false ? 'selected' : ''}>No</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Riduce dosi senza consulto?</label>
                <select class="form-select" id="adReduces">
                    <option value="">Seleziona</option>
                    <option value="true" ${a.reduces_doses_without_consult ? 'selected' : ''}>Sì</option>
                    <option value="false" ${a.reduces_doses_without_consult === false ? 'selected' : ''}>No</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Sa usare correttamente i dispositivi?</label>
                <select class="form-select" id="adKnowDevices">
                    <option value="">Seleziona</option>
                    <option value="true" ${a.knows_how_to_use_devices ? 'selected' : ''}>Sì</option>
                    <option value="false" ${a.knows_how_to_use_devices === false ? 'selected' : ''}>No</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Esegue automisurazioni periodiche?</label>
                <select class="form-select" id="adSelfMonitoring">
                    <option value="">Seleziona</option>
                    <option value="true" ${a.does_self_monitoring ? 'selected' : ''}>Sì</option>
                    <option value="false" ${a.does_self_monitoring === false ? 'selected' : ''}>No</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Ultimo controllo</label>
                <input type="date" class="form-control" id="adLastCheck" value="${escapeHtml(a.last_check_date || '')}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Accessi PS/ricoveri ultimo anno</label>
                <select class="form-select" id="adErVisits">
                    <option value="">Seleziona</option>
                    <option value="Nessuno" ${a.er_visits_last_year === 'Nessuno' ? 'selected' : ''}>Nessuno</option>
                    <option value="1" ${a.er_visits_last_year === '1' ? 'selected' : ''}>1</option>
                    <option value=">1" ${a.er_visits_last_year === '>1' ? 'selected' : ''}>>1</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Reazioni avverse note?</label>
                <select class="form-select" id="adAdverse">
                    <option value="">Seleziona</option>
                    <option value="true" ${a.known_adverse_reactions ? 'selected' : ''}>Sì</option>
                    <option value="false" ${a.known_adverse_reactions === false ? 'selected' : ''}>No</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Note aggiuntive</label>
                <textarea class="form-control" id="adNotes" rows="2">${escapeHtml(a.extra_notes || '')}</textarea>
            </div>
        </div>
    `;
}

function collectStep3Data() {
    therapyWizardState.adherence_base = {
        current_therapies: document.getElementById('adCurrentTherapies')?.value || '',
        devices_used: document.getElementById('adDevicesUsed')?.value || '',
        forgets_doses: document.getElementById('adForgets')?.value || '',
        stops_when_better: toBool(document.getElementById('adStopsBetter')?.value),
        reduces_doses_without_consult: toBool(document.getElementById('adReduces')?.value),
        knows_how_to_use_devices: toBool(document.getElementById('adKnowDevices')?.value),
        does_self_monitoring: toBool(document.getElementById('adSelfMonitoring')?.value),
        last_check_date: document.getElementById('adLastCheck')?.value || '',
        er_visits_last_year: document.getElementById('adErVisits')?.value || '',
        known_adverse_reactions: toBool(document.getElementById('adAdverse')?.value),
        extra_notes: document.getElementById('adNotes')?.value || ''
    };
}

function renderStep4() {
    collectStep3Data();
    const content = document.getElementById('wizardContent');
    if (!content) return;
    const survey = therapyWizardState.condition_survey || {};
    const condition = therapyWizardState.primary_condition || survey.condition_type || '';
    const answers = survey.answers || {};
    const compiledAt = survey.compiled_at || new Date().toISOString().slice(0, 19).replace('T', ' ');

    const template = SURVEY_TEMPLATES[condition] || [];

    const templateControls = template.map((q) => {
        const existingVal = answers[q.key] ?? '';
        if (q.type === 'select') {
            const options = q.options.map(opt => `<option value="${escapeHtml(opt)}" ${existingVal === opt ? 'selected' : ''}>${escapeHtml(opt)}</option>`).join('');
            return `
                <div class="col-md-6">
                    <label class="form-label">${escapeHtml(q.label)}</label>
                    <select class="form-select survey-input" data-key="${escapeHtml(q.key)}">
                        <option value="">Seleziona</option>
                        ${options}
                    </select>
                </div>
            `;
        }
        return `
            <div class="col-md-6">
                <label class="form-label">${escapeHtml(q.label)}</label>
                <input type="text" class="form-control survey-input" data-key="${escapeHtml(q.key)}" value="${escapeHtml(existingVal)}">
            </div>
        `;
    }).join('');

    content.innerHTML = `
        <h5 class="mb-3">Questionario specifico patologia</h5>
        <div class="mb-3">
            <label class="form-label">Patologia</label>
            <select class="form-select" id="surveyCondition">
                <option value="Diabete" ${condition === 'Diabete' ? 'selected' : ''}>Diabete</option>
                <option value="BPCO" ${condition === 'BPCO' ? 'selected' : ''}>BPCO</option>
                <option value="Ipertensione" ${condition === 'Ipertensione' ? 'selected' : ''}>Ipertensione</option>
                <option value="Dislipidemia" ${condition === 'Dislipidemia' ? 'selected' : ''}>Dislipidemia</option>
                <option value="Altro" ${condition === 'Altro' ? 'selected' : ''}>Altro</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Domande specifiche</label>
            <div class="row g-3" id="surveyTemplateQuestions">
                ${templateControls || '<div class="col-12 text-muted">Nessuna domanda specifica per la patologia selezionata.</div>'}
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Compiled at</label>
            <input type="text" class="form-control" id="compiledAt" value="${escapeHtml(compiledAt)}">
        </div>
    `;

    const conditionSelect = document.getElementById('surveyCondition');
    if (conditionSelect) {
        conditionSelect.addEventListener('change', () => {
            // Re-render to load the appropriate template while keeping current answers
            collectStep4Data();
            renderStep4();
        });
    }
}

function collectStep4Data() {
    const answers = {};
    document.querySelectorAll('.survey-input').forEach((input) => {
        const key = input.getAttribute('data-key');
        if (!key) return;
        answers[key] = input.value;
    });
    therapyWizardState.condition_survey = {
        condition_type: document.getElementById('surveyCondition')?.value || therapyWizardState.primary_condition,
        level: 'base',
        answers,
        compiled_at: document.getElementById('compiledAt')?.value || new Date().toISOString().slice(0, 19).replace('T', ' ')
    };
}

function renderStep5() {
    collectStep4Data();
    const content = document.getElementById('wizardContent');
    if (!content) return;
    const flags = therapyWizardState.flags || {};
    const feedback = flags.caregiver_feedback || {};
    const wantsReport = feedback.wants_monthly_report === true
        || feedback.wants_monthly_report === 'true'
        || feedback.wants_monthly_report === 'email'
        || feedback.wants_monthly_report === 'whatsapp';
    const reportChannel = feedback.wants_monthly_report === 'email' || feedback.wants_monthly_report === 'whatsapp'
        ? feedback.wants_monthly_report
        : (wantsReport ? 'email' : '');
    content.innerHTML = `
        <h5 class="mb-3">Sezione 5 – Note farmacista e follow-up</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Punteggio di rischio / aderenza</label>
                <input type="number" class="form-control" id="riskScore" value="${escapeHtml(therapyWizardState.risk_score || '')}">
            </div>
            <div class="col-12">
                <label class="form-label">Criticità rilevate</label>
                <textarea class="form-control" id="criticalIssues" rows="3">${escapeHtml(therapyWizardState.flags?.critical_issues || '')}</textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Educazione sanitaria / interventi proposti</label>
                <textarea class="form-control" id="educationNotes" rows="3">${escapeHtml(therapyWizardState.flags?.education_notes || '')}</textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Note iniziali</label>
                <textarea class="form-control" id="notesInitial" rows="3">${escapeHtml(therapyWizardState.notes_initial || '')}</textarea>
            </div>
        </div>
        <div class="mt-4">
            <h6 class="mb-2">Per il Caregiver</h6>
            <div class="row g-3 mt-2">
                <div class="col-md-6">
                    <label class="form-label">È interessato a ricevere un REPORT mensile sullo stato di salute del suo familiare?</label>
                    <select class="form-select" id="caregiverWantsReport">
                        <option value="" ${feedback.wants_monthly_report === null || feedback.wants_monthly_report === undefined || feedback.wants_monthly_report === '' ? 'selected' : ''}>Non specificato</option>
                        <option value="true" ${wantsReport ? 'selected' : ''}>Sì</option>
                        <option value="false" ${feedback.wants_monthly_report === 'none' || feedback.wants_monthly_report === false || feedback.wants_monthly_report === 'false' ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6 ${wantsReport ? '' : 'd-none'}" id="caregiverReportChannelWrap">
                    <label class="form-label">Canale report</label>
                    <select class="form-select" id="caregiverReportChannel">
                        <option value="" ${!reportChannel ? 'selected' : ''}>Seleziona</option>
                        <option value="email" ${reportChannel === 'email' ? 'selected' : ''}>Email</option>
                        <option value="whatsapp" ${reportChannel === 'whatsapp' ? 'selected' : ''}>WhatsApp</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">È favorevole ad autorizzare la farmacia a interagire con il medico curante del suo familiare?</label>
                    <select class="form-select" id="caregiverAllowDoctor">
                        <option value="" ${feedback.allow_doctor_interaction === null || feedback.allow_doctor_interaction === undefined ? 'selected' : ''}>Non specificato</option>
                        <option value="true" ${feedback.allow_doctor_interaction === true ? 'selected' : ''}>Sì</option>
                        <option value="false" ${feedback.allow_doctor_interaction === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">È favorevole ad autorizzare questa farmacia al ritiro di ricette e referti presso lo studio medico del familiare?</label>
                    <select class="form-select" id="caregiverAllowPickup">
                        <option value="" ${feedback.allow_prescription_pickup === null || feedback.allow_prescription_pickup === undefined ? 'selected' : ''}>Non specificato</option>
                        <option value="true" ${feedback.allow_prescription_pickup === true ? 'selected' : ''}>Sì</option>
                        <option value="false" ${feedback.allow_prescription_pickup === false ? 'selected' : ''}>No</option>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <label class="form-label">Note dal caregiver / informazioni aggiuntive</label>
                <textarea class="form-control" id="caregiverNotes" rows="3">${escapeHtml(flags.caregiver_extra_notes || '')}</textarea>
            </div>
        </div>
    `;

    const wantsReportSelect = document.getElementById('caregiverWantsReport');
    const reportChannelWrap = document.getElementById('caregiverReportChannelWrap');
    if (wantsReportSelect && reportChannelWrap) {
        wantsReportSelect.addEventListener('change', () => {
            reportChannelWrap.classList.toggle('d-none', wantsReportSelect.value !== 'true');
        });
    }
}

function collectStep5Data() {
    therapyWizardState.risk_score = toNumberOrNull(document.getElementById('riskScore')?.value);
    therapyWizardState.notes_initial = document.getElementById('notesInitial')?.value || '';
    const existingFlags = therapyWizardState.flags || {};
    const existingFeedback = existingFlags.caregiver_feedback || {};
    const wantsReportValue = document.getElementById('caregiverWantsReport')?.value || '';
    const reportChannelValue = document.getElementById('caregiverReportChannel')?.value || '';
    const wantsReport = wantsReportValue === 'true';
    let wantsMonthlyReport = existingFeedback.wants_monthly_report || '';
    if (wantsReportValue === 'false') {
        wantsMonthlyReport = 'none';
    } else if (wantsReport) {
        wantsMonthlyReport = reportChannelValue || wantsMonthlyReport || 'email';
    }
    const caregiverNotesValue = document.getElementById('caregiverNotes')?.value;
    therapyWizardState.flags = {
        ...existingFlags,
        critical_issues: document.getElementById('criticalIssues')?.value || '',
        education_notes: document.getElementById('educationNotes')?.value || '',
        caregiver_extra_notes: caregiverNotesValue ?? existingFlags.caregiver_extra_notes ?? '',
        caregiver_feedback: {
            ...existingFeedback,
            wants_monthly_report: wantsMonthlyReport,
            allow_doctor_interaction: toBool(document.getElementById('caregiverAllowDoctor')?.value),
            allow_prescription_pickup: toBool(document.getElementById('caregiverAllowPickup')?.value)
        }
    };
}

function renderStep6() {
    collectStep5Data();
    const content = document.getElementById('wizardContent');
    if (!content) return;
    const consent = therapyWizardState.consent || {};
    const scopes = consent.scopes || {};
    const signatures = consent.signatures || {};
    content.innerHTML = `
        <h5 class="mb-3">Consenso informato e firme</h5>
        <div class="mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consentCareFollowup" ${scopes.care_followup ? 'checked' : ''}>
                <label class="form-check-label">Acconsento al trattamento dati personali (GDPR) per finalità di cura, monitoraggio e follow-up</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consentContact" ${scopes.contact_for_reminders ? 'checked' : ''}>
                <label class="form-check-label">Acconsento a essere contattato dalla farmacia per promemoria e aggiornamenti terapeutici</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consentAnonymous" ${scopes.anonymous_stats ? 'checked' : ''}>
                <label class="form-check-label">Acconsento all'uso anonimo dei miei dati per fini statistici e miglioramento servizio</label>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Firma paziente/caregiver</label>
                <input type="text" class="form-control" id="signerName" value="${escapeHtml(consent.signer_name || '')}">
            </div>
            <div class="col-md-6">
                <label class="form-label">Data</label>
                <input type="date" class="form-control" id="signedAt" value="${escapeHtml(consent.signed_at || '')}">
            </div>
            <div class="col-md-6">
                <label class="form-label">Firma farmacista</label>
                <input type="text" class="form-control" id="pharmacistName" value="${escapeHtml(consent.pharmacist_name || '')}">
            </div>
            <div class="col-md-6">
                <label class="form-label">Luogo</label>
                <input type="text" class="form-control" id="consentPlace" value="${escapeHtml(consent.place || '')}">
            </div>
        </div>
        <div class="mt-4">
            <h6 class="mb-2">Firme digitali</h6>
            <div class="row g-3">
                <div class="col-md-6 mt-2">
                    <label class="form-label">Firma digitale paziente/caregiver</label>
                    <canvas id="patientSignatureCanvas" class="signature-canvas" style="border: 1px solid #ccc; width: 100%; height: 150px;"></canvas>
                    <button type="button" id="clearPatientSignature" class="btn btn-sm btn-outline-secondary mt-1">Pulisci firma</button>
                </div>
                <div class="col-md-6 mt-2">
                    <label class="form-label">Firma digitale farmacista</label>
                    <canvas id="pharmacistSignatureCanvas" class="signature-canvas" style="border: 1px solid #ccc; width: 100%; height: 150px;"></canvas>
                    <button type="button" id="clearPharmacistSignature" class="btn btn-sm btn-outline-secondary mt-1">Pulisci firma</button>
                </div>
            </div>
        </div>
    `;

    const patientCanvas = document.getElementById('patientSignatureCanvas');
    const pharmacistCanvas = document.getElementById('pharmacistSignatureCanvas');
    if (patientCanvas && signatures.patient_data_url) {
        patientCanvas.dataset.existingImage = signatures.patient_data_url;
    }
    if (pharmacistCanvas && signatures.pharmacist_data_url) {
        pharmacistCanvas.dataset.existingImage = signatures.pharmacist_data_url;
    }

    initSignaturePad('patientSignatureCanvas', 'clearPatientSignature');
    initSignaturePad('pharmacistSignatureCanvas', 'clearPharmacistSignature');
}

function collectStep6Data() {
    const gdprCareFollowup = document.getElementById('consentCareFollowup')?.checked || false;
    const gdprContact = document.getElementById('consentContact')?.checked || false;
    const gdprAnonymous = document.getElementById('consentAnonymous')?.checked || false;
    const signedAtRaw = document.getElementById('signedAt')?.value || '';

    const previousConsent = therapyWizardState.consent || {};
    const previousScopes = previousConsent.scopes || {};

    therapyWizardState.consent = {
        ...previousConsent,
        signer_name: document.getElementById('signerName')?.value || '',
        signer_relation: 'patient',
        signed_at: signedAtRaw || '',
        pharmacist_name: document.getElementById('pharmacistName')?.value || '',
        place: document.getElementById('consentPlace')?.value || '',
        scopes: {
            ...previousScopes,
            care_followup: gdprCareFollowup,
            contact_for_reminders: gdprContact,
            anonymous_stats: gdprAnonymous,
            gdpr_accepted: gdprCareFollowup && gdprContact && gdprAnonymous
        },
        signatures: {
            patient_data_url: getSignatureDataUrl('patientSignatureCanvas'),
            pharmacist_data_url: getSignatureDataUrl('pharmacistSignatureCanvas')
        },
        signer_role: ''
    };
}

function assemblePayload() {
    collectStep6Data();

    // Fallback: if primary condition missing, reuse condition survey selection
    if (!therapyWizardState.primary_condition && therapyWizardState.condition_survey?.condition_type) {
        therapyWizardState.primary_condition = therapyWizardState.condition_survey.condition_type;
    }

    return {
        patient: therapyWizardState.patient,
        primary_condition: therapyWizardState.primary_condition,
        initial_notes: therapyWizardState.initial_notes,
        general_anamnesis: therapyWizardState.general_anamnesis,
        detailed_intake: therapyWizardState.detailed_intake,
        doctor_info: therapyWizardState.doctor_info,
        biometric_info: therapyWizardState.biometric_info,
        therapy_assistants: therapyWizardState.therapy_assistants,
        adherence_base: therapyWizardState.adherence_base,
        condition_survey: therapyWizardState.condition_survey,
        risk_score: therapyWizardState.risk_score,
        flags: therapyWizardState.flags,
        notes_initial: therapyWizardState.notes_initial,
        follow_up_date: therapyWizardState.follow_up_date,
        consent: therapyWizardState.consent
    };
}

function calculateAgeFromBirthDate(birthDate) {
    const birth = new Date(birthDate);
    if (Number.isNaN(birth.getTime())) return '';
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--;
    }
    return age >= 0 ? age : '';
}

function updateBmiFromInputs() {
    const weight = toNumberOrNull(document.getElementById('bioWeight')?.value);
    const height = toNumberOrNull(document.getElementById('bioHeight')?.value);
    const bmiInput = document.getElementById('bioBmi');
    if (!bmiInput) return;
    if (weight && height) {
        const heightMeters = height / 100;
        const bmi = heightMeters > 0 ? weight / (heightMeters * heightMeters) : null;
        bmiInput.value = bmi ? bmi.toFixed(1) : '';
    } else {
        bmiInput.value = '';
    }
}

async function submitTherapy() {
    if (!validateStep(6)) return;
    const payload = assemblePayload();
    const isEdit = !!currentTherapyId;
    const url = isEdit ? `api/therapies.php?id=${currentTherapyId}` : 'api/therapies.php';
    const method = isEdit ? 'PUT' : 'POST';
    try {
        const resp = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (data.success) {
            alert('Terapia salvata con successo');
            clearWizardDraft();
            skipDraftOnHide = true;
            wizardModalInstance?.hide();
            if (typeof loadTherapies === 'function') {
                loadTherapies();
            }
        } else {
            alert(data.error || 'Errore salvataggio terapia');
        }
    } catch (err) {
        alert('Errore di rete');
    }
}

function toBool(val) {
    if (val === 'true' || val === true) return true;
    if (val === 'false' || val === false) return false;
    return null;
}

function toNumberOrNull(val) {
    if (val === undefined || val === null || val === '') return null;
    const n = Number(val);
    return Number.isNaN(n) ? null : n;
}

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function sanitizeNullish(obj) {
    return obj || {};
}

window.openTherapyWizard = openTherapyWizard;
