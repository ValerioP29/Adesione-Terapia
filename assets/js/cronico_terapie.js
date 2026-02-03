let currentTherapyPage = 1;
const therapiesPerPage = 20;

const therapySearchInput = document.getElementById('therapySearch');
const therapyStatusSelect = document.getElementById('therapyStatus');
const therapyTableBody = document.getElementById('therapiesTableBody');
const therapyPagination = document.getElementById('therapyPagination');
const therapyResultsInfo = document.getElementById('therapyResultsInfo');
const reminderModalContainer = document.getElementById('reminderModal');
const reportModalContainer = document.getElementById('reportModal');
const followupModalContainer = document.getElementById('followupModal');
let activeFollowupId = null;
let activeFollowupData = null;
let activeFollowups = [];
let activeChecklistQuestions = [];
let activeChecklistAnswers = {};
let surveyTemplatesPromise = null;

function ensureSurveyTemplatesLoaded() {
    if (typeof window !== 'undefined' && window.SURVEY_TEMPLATES) {
        return Promise.resolve(window.SURVEY_TEMPLATES);
    }

    if (surveyTemplatesPromise) return surveyTemplatesPromise;

    surveyTemplatesPromise = new Promise((resolve) => {
        const startTime = Date.now();
        const maxWaitMs = 1000;

        const checkTemplates = () => {
            if (window.SURVEY_TEMPLATES || Date.now() - startTime >= maxWaitMs) {
                clearInterval(intervalId);
                resolve(window.SURVEY_TEMPLATES);
            }
        };

        const intervalId = setInterval(checkTemplates, 50);
    });

    return surveyTemplatesPromise;
}

function humanizeQuestionKey(key) {
    if (!key) return '';
    const spaced = key.replace(/_/g, ' ');
    const capitalized = spaced.charAt(0).toUpperCase() + spaced.slice(1);
    return capitalized.endsWith('?') ? capitalized : `${capitalized}?`;
}

function getConditionQuestionLabel(condition, key) {
    if (!key) return '';
    try {
        const templates = window.SURVEY_TEMPLATES;
        if (templates && condition && templates[condition]) {
            const found = templates[condition].find((q) => q.key === key);
            if (found?.label) return found.label;
        }
    } catch (error) {
        console.error('Errore mappatura label', error);
    }
    return humanizeQuestionKey(key);
}

function formatAnswerValue(value) {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'boolean') return value ? 'Sì' : 'No';
    if (value === 'true' || value === '1' || value === 1) return 'Sì';
    if (value === 'false' || value === '0' || value === 0) return 'No';
    return value;
}

async function initCronicoTerapiePage() {
    await ensureSurveyTemplatesLoaded();
    attachToolbarActions();
    attachFilterActions();
    loadTherapies();
}

document.addEventListener('DOMContentLoaded', initCronicoTerapiePage);

function attachToolbarActions() {
    const btnNewTherapy = document.getElementById('btnNewTherapy');
    const btnReminder = document.getElementById('btnReminder');
    const btnReport = document.getElementById('btnReport');
    const btnFollowup = document.getElementById('btnFollowup');

    if (btnNewTherapy) btnNewTherapy.addEventListener('click', () => openTherapyWizard());
    if (btnReminder) btnReminder.addEventListener('click', () => openRemindersModal());
    if (btnReport) btnReport.addEventListener('click', () => openReportsModal());
    if (btnFollowup) btnFollowup.addEventListener('click', () => openCheckPeriodicoModal());
}

function attachFilterActions() {
    const btnApplyFilters = document.getElementById('btnApplyFilters');
    if (btnApplyFilters) {
        btnApplyFilters.addEventListener('click', () => {
            currentTherapyPage = 1;
            loadTherapies();
        });
    }

    if (therapySearchInput) {
        therapySearchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                currentTherapyPage = 1;
                loadTherapies();
            }
        });
    }
}

async function loadTherapies(page = 1) {
    currentTherapyPage = page;
    setLoadingState();

    const params = new URLSearchParams({
        page: currentTherapyPage,
        per_page: therapiesPerPage
    });

    if (therapyStatusSelect && therapyStatusSelect.value) {
        params.append('status', therapyStatusSelect.value);
    }
    if (therapySearchInput && therapySearchInput.value) {
        params.append('q', therapySearchInput.value.trim());
    }

    try {
        const response = await fetch(`api/therapies.php?${params.toString()}`);
        const result = await response.json();

        if (!result.success) {
            renderErrorRow(result.error || 'Errore nel caricamento delle terapie');
            return;
        }

        renderTherapyRows(result.data?.items || []);
        renderPagination(result.data?.items || []);
    } catch (error) {
        console.error(error);
        renderErrorRow('Errore di rete durante il caricamento delle terapie');
    }
}

function setLoadingState() {
    if (!therapyTableBody) return;
    therapyTableBody.innerHTML = `<tr><td colspan="8" class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Caricamento...</span></div></td></tr>`;
    if (therapyResultsInfo) therapyResultsInfo.textContent = '';
}

function renderTherapyRows(items) {
    if (!therapyTableBody) return;

    if (!items.length) {
        therapyTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Nessuna terapia trovata</td></tr>';
        if (therapyResultsInfo) therapyResultsInfo.textContent = '0 risultati';
        return;
    }

    const rows = items.map((item) => {
        const patientName = `${item.first_name || ''} ${item.last_name || ''}`.trim();
        const statusBadge = renderStatusBadge(item.status);

        return `<tr>
            <td>${sanitizeHtml(patientName)}</td>
            <td>${sanitizeHtml(item.codice_fiscale || '-')}</td>
            <td>${sanitizeHtml(item.primary_condition || item.therapy_title || '-')}</td>
            <td>${sanitizeHtml(item.therapy_title || '-')}</td>
            <td>${statusBadge}</td>
            <td>${sanitizeHtml(item.start_date || '-')}</td>
            <td>${sanitizeHtml(item.end_date || '-')}</td>
            <td class="text-end">
                <div class="btn-group" role="group">
                    <button class="btn btn-sm btn-outline-primary" data-therapy-id="${item.id}" title="Modifica" onclick="openTherapyWizard(${item.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-therapy-id="${item.id}" title="Promemoria" onclick="openRemindersModal(${item.id})">
                        <i class="fas fa-bell"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-therapy-id="${item.id}" title="Report" onclick="openReportsModal(${item.id})">
                        <i class="fas fa-file-alt"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-therapy-id="${item.id}" title="Check periodico" onclick="openFollowupModal(${item.id})">
                        <i class="fas fa-stethoscope"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" data-therapy-id="${item.id}" title="Sospendi" onclick="suspendTherapy(${item.id})">
                        <i class="fas fa-ban"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" data-therapy-id="${item.id}" title="Elimina" onclick="deleteTherapy(${item.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    });

    therapyTableBody.innerHTML = rows.join('');
    if (therapyResultsInfo) therapyResultsInfo.textContent = `${items.length} risultati`;
}

function renderErrorRow(message) {
    if (!therapyTableBody) return;
    therapyTableBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">${sanitizeHtml(message)}</td></tr>`;
    if (therapyResultsInfo) therapyResultsInfo.textContent = '';
}

function renderStatusBadge(status) {
    const map = {
        active: 'bg-success',
        planned: 'bg-info',
        completed: 'bg-secondary',
        suspended: 'bg-warning text-dark'
    };
    const label = status || '-';
    const cls = map[status] || 'bg-light text-dark';
    return `<span class="badge ${cls}">${sanitizeHtml(label)}</span>`;
}

function renderPagination(items) {
    if (!therapyPagination) return;

    const hasPrev = currentTherapyPage > 1;
    const hasNext = items.length === therapiesPerPage;

    const prevItem = `<li class="page-item ${hasPrev ? '' : 'disabled'}"><button class="page-link" ${hasPrev ? `onclick="loadTherapies(${currentTherapyPage - 1})"` : ''}>Precedente</button></li>`;
    const currentItem = `<li class="page-item active"><span class="page-link">${currentTherapyPage}</span></li>`;
    const nextItem = `<li class="page-item ${hasNext ? '' : 'disabled'}"><button class="page-link" ${hasNext ? `onclick="loadTherapies(${currentTherapyPage + 1})"` : ''}>Successiva</button></li>`;

    therapyPagination.innerHTML = `${prevItem}${currentItem}${nextItem}`;
}

function attachReminderForm(therapySelect) {
    const form = document.getElementById('reminderForm');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const therapyId = therapySelect?.value;
        if (!therapyId) {
            showReminderToast('Seleziona una terapia.', 'error');
            return;
        }
        const payload = {
            therapy_id: therapyId,
            title: form.title.value.trim(),
            message: form.message.value.trim(),
            type: form.type.value,
            scheduled_at: form.scheduled_at.value,
            channel: form.channel.value
        };

        try {
            const response = await fetch('api/reminders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => null);
            if (!response.ok) {
                showReminderToast(result?.message || result?.error || 'Errore nel salvataggio del promemoria', 'error');
                return;
            }
            if (result?.success) {
                form.reset();
                showReminderToast('Promemoria salvato correttamente', 'success');
                loadReminders(therapyId);
            } else {
                showReminderToast(result?.message || result?.error || 'Errore nel salvataggio del promemoria', 'error');
            }
        } catch (error) {
            console.error(error);
            showReminderToast('Errore di rete nel salvataggio del promemoria', 'error');
        }
    });
}

async function loadReminders(therapyId) {
    const list = document.getElementById('reminderList');
    if (!list) return;
    if (!therapyId) {
        list.innerHTML = '<div class="text-muted">Seleziona una terapia per visualizzare i promemoria</div>';
        return;
    }
    list.innerHTML = '<div class="text-center text-muted">Caricamento...</div>';
    try {
        const response = await fetch(`api/reminders.php?therapy_id=${therapyId}`);
        const result = await response.json();
        if (!result.success) {
            list.innerHTML = `<div class="text-danger">${sanitizeHtml(result.error || 'Errore nel caricamento')}</div>`;
            return;
        }
        const items = result.data?.items || result.data || [];
        if (!items.length) {
            list.innerHTML = '<div class="text-muted">Nessun promemoria programmato</div>';
            return;
        }
        const rows = items.map((r) => {
            const statusBadge = renderReminderStatusBadge(r.status);
            const typeBadge = `<span class="badge bg-light text-dark">${sanitizeHtml(r.type || '')}</span>`;
            const cancelButton = r.status === 'scheduled'
                ? `<button class="btn btn-sm btn-outline-danger" onclick="cancelReminder(${r.id}, ${therapyId})">Cancella</button>`
                : '';

            return `
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <strong>${sanitizeHtml(r.title || '-')}</strong>
                            <div class="small text-muted">${sanitizeHtml(r.channel || '')}</div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            ${typeBadge}
                            ${statusBadge}
                        </div>
                    </div>
                    <div class="small text-muted">${sanitizeHtml(r.scheduled_at || '')}</div>
                    <div>${sanitizeHtml(r.message || '')}</div>
                    ${cancelButton ? `<div class="text-end mt-2">${cancelButton}</div>` : ''}
                </div>
            `;
        });
        list.innerHTML = rows.join('');
    } catch (error) {
        console.error(error);
        list.innerHTML = '<div class="text-danger">Errore di rete</div>';
    }
}

async function cancelReminder(reminderId, therapyId = null) {
    if (!reminderId) return;
    const targetTherapyId = therapyId || document.getElementById('reminderTherapySelect')?.value;

    try {
        const response = await fetch(`api/reminders.php?action=cancel&id=${reminderId}`, { method: 'POST' });
        const result = await response.json().catch(() => null);
        if (!response.ok) {
            showReminderToast(result?.message || result?.error || 'Errore nella cancellazione del promemoria', 'error');
            return;
        }
        if (result?.success) {
            showReminderToast('Promemoria cancellato', 'success');
            loadReminders(targetTherapyId);
        } else {
            showReminderToast(result?.message || result?.error || 'Errore nella cancellazione del promemoria', 'error');
        }
    } catch (error) {
        console.error(error);
        showReminderToast('Errore di rete nella cancellazione del promemoria', 'error');
    }
}

function renderReminderStatusBadge(status) {
    const clsMap = {
        scheduled: 'bg-info text-dark',
        shown: 'bg-success',
        sent: 'bg-success',
        canceled: 'bg-secondary',
        failed: 'bg-danger'
    };
    const label = status || '-';
    const cls = clsMap[status] || 'bg-light text-dark';
    return `<span class="badge ${cls}">${sanitizeHtml(label)}</span>`;
}

function showReminderToast(message, type = 'success') {
    const containerId = 'reminderToastContainer';
    let container = document.getElementById(containerId);
    if (!container) {
        container = document.createElement('div');
        container.id = containerId;
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        document.body.appendChild(container);
    }

    const bgClass = type === 'success' ? 'bg-success' : 'bg-danger';
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white ${bgClass} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${sanitizeHtml(message)}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;

    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
        if (!container.children.length) {
            container.remove();
        }
    });
    bsToast.show();
}

function attachReportForm(therapySelect) {
    const form = document.getElementById('reportForm');
    const followupSelectWrapper = document.getElementById('reportFollowupWrapper');
    const followupSelect = document.getElementById('reportFollowupSelect');
    const modeInputs = form?.querySelectorAll('input[name="reportMode"]');
    const previewContainer = document.getElementById('reportPreview');
    const generatePdfBtn = document.getElementById('reportGeneratePdf');
    if (!form) return;

    const toggleFollowupField = () => {
        const mode = form.reportMode.value;
        if (mode === 'single') {
            followupSelectWrapper?.classList.remove('d-none');
        } else {
            followupSelectWrapper?.classList.add('d-none');
        }
    };

    modeInputs?.forEach((input) => input.addEventListener('change', toggleFollowupField));

    toggleFollowupField();
    if (generatePdfBtn) {
        generatePdfBtn.addEventListener('click', () => {
            handleReportDownload(therapySelect, followupSelect);
        });
    }

    const refreshFollowups = async () => {
        const therapyId = therapySelect?.value;
        if (!therapyId || !followupSelect) return;
        followupSelect.innerHTML = '<option value="">Seleziona check o follow-up</option>';
        const followups = await fetchFollowupsOptions(therapyId);
        followups.forEach((f) => {
            const opt = document.createElement('option');
            opt.value = f.id;
            const typeLabel = f.entry_type === 'check' ? 'Check' : 'Follow-up';
            opt.textContent = `${typeLabel} ${f.follow_up_date || f.created_at || ''} - Rischio ${f.risk_score ?? '-'}`;
            followupSelect.appendChild(opt);
        });
        handleReportPreview(therapySelect, followupSelect, previewContainer, generatePdfBtn);
    };

    therapySelect?.addEventListener('change', refreshFollowups);
    therapySelect?.addEventListener('change', () => handleReportPreview(therapySelect, followupSelect, previewContainer, generatePdfBtn));
    followupSelect?.addEventListener('change', () => handleReportPreview(therapySelect, followupSelect, previewContainer, generatePdfBtn));
    modeInputs?.forEach((input) => input.addEventListener('change', () => handleReportPreview(therapySelect, followupSelect, previewContainer, generatePdfBtn)));
    refreshFollowups();
}

async function loadReports(therapyId) {
    const list = document.getElementById('reportList');
    if (!list) return;
    if (!therapyId) {
        list.innerHTML = '<div class="text-muted">Seleziona una terapia per visualizzare i report</div>';
        return;
    }
    list.innerHTML = '<div class="text-center text-muted">Caricamento...</div>';
    try {
        const response = await fetch(`api/reports.php?therapy_id=${therapyId}`);
        const result = await response.json();
        if (!result.success) {
            list.innerHTML = `<div class="text-danger">${sanitizeHtml(result.error || 'Errore nel caricamento')}</div>`;
            return;
        }
        const items = result.data?.items || [];
        if (!items.length) {
            list.innerHTML = '<div class="text-muted">Nessun report</div>';
            return;
        }
        const rows = items.map((r) => {
            const previewHtml = buildReportPreviewHtml(r.content);
            return `
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>Report #${sanitizeHtml(r.id)}</strong>
                            <div class="small text-muted">${sanitizeHtml(r.created_at || '')}</div>
                        </div>
                        <div class="text-end small">
                            <div>Token: ${sanitizeHtml(r.share_token || '-')}</div>
                            <div>PIN: ${sanitizeHtml(r.pin_code || '-')}</div>
                            <div>PDF: ${r.pdf_url ? `<a href="${sanitizeHtml(r.pdf_url)}" target="_blank">Scarica</a>` : 'Non disponibile'}</div>
                        </div>
                    </div>
                    <div class="mt-2">${previewHtml}</div>
                </div>
            `;
        });
        list.innerHTML = rows.join('');
    } catch (error) {
        console.error(error);
        list.innerHTML = '<div class="text-danger">Errore di rete</div>';
    }
}

function attachFollowupForm(therapySelect) {
    const form = document.getElementById('followupForm');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const therapyId = therapySelect?.value;
        if (!therapyId) {
            alert('Seleziona una terapia.');
            return;
        }
        if (!form.follow_up_date.value) {
            alert('Seleziona la data di follow-up.');
            return;
        }
        if (!form.risk_score.value) {
            alert('Inserisci il rischio aggiornato.');
            return;
        }
        const payload = {
            therapy_id: therapyId,
            risk_score: form.risk_score.value,
            pharmacist_notes: form.pharmacist_notes.value.trim() || null,
            follow_up_date: form.follow_up_date.value
        };

        try {
            const response = await fetch('api/followups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                form.reset();
                showFollowupToast('Follow-up salvato', 'success');
                loadFollowups(therapyId);
            } else {
                alert(result.error || 'Errore nel salvataggio del follow-up');
            }
        } catch (error) {
            console.error(error);
            alert('Errore di rete nel salvataggio del follow-up');
        }
    });
}

async function loadFollowups(therapyId) {
    const list = document.getElementById('followupList');
    if (!list) return;
    if (!therapyId) {
        list.innerHTML = '<div class="text-muted">Seleziona una terapia per visualizzare i follow-up</div>';
        return;
    }
    list.innerHTML = '<div class="text-center text-muted">Caricamento...</div>';
    try {
        const response = await fetch(`api/followups.php?therapy_id=${therapyId}&entry_type=followup`);
        const result = await response.json();
        if (!result.success) {
            list.innerHTML = `<div class="text-danger">${sanitizeHtml(result.error || 'Errore nel caricamento')}</div>`;
            return;
        }
        const items = result.data?.items || [];
        if (!items.length) {
            list.innerHTML = '<div class="text-muted">Nessun follow-up</div>';
            return;
        }
        const rows = items.map((r) => {
            const statusBadge = r.status === 'canceled'
                ? '<span class="badge bg-secondary">Annullato</span>'
                : '<span class="badge bg-success">Programmato</span>';
            const cancelButton = r.status === 'scheduled'
                ? `<button class="btn btn-sm btn-outline-danger" onclick="cancelFollowup(${r.id}, ${therapyId})">Cancella</button>`
                : '';
            return `
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold">Rischio: ${sanitizeHtml(r.risk_score || 'N/A')}</div>
                            <div class="small text-muted">${sanitizeHtml(r.follow_up_date || '')}</div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            ${statusBadge}
                            ${cancelButton ? cancelButton : ''}
                        </div>
                    </div>
                    <div class="mt-2">${sanitizeHtml(r.pharmacist_notes || '')}</div>
                </div>
            `;
        });
        list.innerHTML = rows.join('');
    } catch (error) {
        console.error(error);
        list.innerHTML = '<div class="text-danger">Errore di rete</div>';
    }
}

async function cancelFollowup(followupId, therapyId = null) {
    if (!followupId) return;
    const targetTherapyId = therapyId || document.getElementById('followupTherapySelect')?.value;
    try {
        const response = await fetch(`api/followups.php?action=cancel&id=${followupId}`, { method: 'POST' });
        const result = await response.json();
        if (result.success) {
            showFollowupToast('Follow-up cancellato', 'success');
            loadFollowups(targetTherapyId);
        } else {
            alert(result.error || 'Errore nella cancellazione del follow-up');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nella cancellazione del follow-up');
    }
}

async function openCheckPeriodicoModal(therapyId = null) {
    if (!followupModalContainer) return;

    followupModalContainer.innerHTML = `
        <div class="modal fade" id="checkPeriodicoModalDialog" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Check periodico</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Terapia</label>
                                <select class="form-select" id="checkTherapySelect"></select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-primary" id="btnNewCheck">Nuovo check periodico</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                            </div>
                        </div>
                        <div class="mt-3" id="checkQuestionsSection">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Domande</h6>
                                <div class="input-group" style="max-width: 420px;">
                                    <input type="text" class="form-control" id="customQuestionText" placeholder="Nuova domanda">
                                    <select class="form-select" id="customQuestionType">
                                        <option value="text">Testo</option>
                                        <option value="boolean">Sì/No</option>
                                    </select>
                                    <button class="btn btn-outline-primary" type="button" onclick="addCustomQuestion()">Aggiungi</button>
                                </div>
                            </div>
                            <div id="checkQuestionList" class="mb-3"></div>
                            <div class="text-end">
                                <button class="btn btn-primary" type="button" onclick="saveAnswers()">Salva risposte</button>
                            </div>
                        </div>
                        <div class="mt-4 border rounded p-3" id="checkMetaSection">
                            <h6>Dati check periodico</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Risk score</label>
                                    <input type="number" class="form-control" id="checkRiskScore" placeholder="0">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Data follow-up</label>
                                    <input type="date" class="form-control" id="checkFollowUpDate">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Note farmacista</label>
                                    <textarea class="form-control" id="checkPharmacistNotes" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="text-end mt-3">
                                <button class="btn btn-primary" type="button" id="btnSaveCheckMeta">Salva dati check</button>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h6>Storico check periodici</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead>
                                        <tr>
                                            <th>Data creazione</th>
                                            <th>Data follow-up</th>
                                            <th>Rischio</th>
                                            <th>Stato</th>
                                            <th>Risposte</th>
                                        </tr>
                                    </thead>
                                    <tbody id="checkHistoryBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    const therapySelect = document.getElementById('checkTherapySelect');
    await populateTherapySelect(therapySelect, therapyId);
    therapySelect?.addEventListener('change', () => {
        loadCheckFollowups(therapySelect.value);
    });

    const btnNewCheck = document.getElementById('btnNewCheck');
    if (btnNewCheck) {
        btnNewCheck.addEventListener('click', () => createCheckPeriodico());
    }
    const btnSaveCheckMeta = document.getElementById('btnSaveCheckMeta');
    if (btnSaveCheckMeta) {
        btnSaveCheckMeta.addEventListener('click', () => saveCheckMeta());
    }

    const modal = new bootstrap.Modal(document.getElementById('checkPeriodicoModalDialog'));
    loadCheckFollowups(therapySelect?.value);
    modal.show();
}

async function loadCheckFollowups(therapyId) {
    const historyBody = document.getElementById('checkHistoryBody');
    const questionList = document.getElementById('checkQuestionList');
    if (!historyBody || !questionList) return;
    activeFollowupId = null;
    activeFollowupData = null;
    activeFollowups = [];
    activeChecklistQuestions = [];
    activeChecklistAnswers = {};
    renderCheckMetaForm(null);

    if (!therapyId) {
        historyBody.innerHTML = '<tr><td colspan="5" class="text-muted">Seleziona una terapia per proseguire</td></tr>';
        questionList.innerHTML = '<div class="text-muted">Nessun check disponibile</div>';
        return;
    }

    historyBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Caricamento...</td></tr>';
    questionList.innerHTML = '<div class="text-center text-muted">Caricamento...</div>';

    try {
        const response = await fetch(`api/followups.php?therapy_id=${therapyId}&entry_type=check`);
        const result = await response.json();
        if (!result.success) {
            historyBody.innerHTML = `<tr><td colspan="5" class="text-danger">${sanitizeHtml(result.error || 'Errore nel caricamento')}</td></tr>`;
            questionList.innerHTML = '<div class="text-danger">Errore nel caricamento</div>';
            return;
        }

        const items = result.data?.items || [];
        activeFollowups = items;
        renderCheckHistory(items);
        await loadChecklistQuestions(therapyId);
        const active = items.find((f) => f.status !== 'canceled') || items[0];
        if (active) {
            await setActiveFollowup(active);
        } else {
            questionList.innerHTML = '<div class="text-muted">Nessun check periodico. Creane uno nuovo.</div>';
        }
    } catch (error) {
        console.error(error);
        historyBody.innerHTML = '<tr><td colspan="5" class="text-danger">Errore di rete</td></tr>';
        questionList.innerHTML = '<div class="text-danger">Errore di rete</div>';
    }
}

async function loadChecklistQuestions(therapyId) {
    try {
        const response = await fetch(`api/followups.php?action=checklist&therapy_id=${therapyId}`);
        const result = await response.json();
        if (!result.success) {
            activeChecklistQuestions = [];
            return;
        }
        activeChecklistQuestions = result.data?.questions || [];
    } catch (error) {
        console.error(error);
        activeChecklistQuestions = [];
    }
}

async function loadChecklistAnswers(followupId) {
    if (!followupId) {
        activeChecklistAnswers = {};
        return;
    }
    try {
        const response = await fetch(`api/followups.php?action=check-answers&id=${followupId}`);
        const result = await response.json();
        if (!result.success) {
            activeChecklistAnswers = {};
            return;
        }
        activeChecklistAnswers = result.data?.answers || {};
    } catch (error) {
        console.error(error);
        activeChecklistAnswers = {};
    }
}

async function setActiveFollowup(followup) {
    activeFollowupId = followup?.id || null;
    activeFollowupData = followup || null;
    await loadChecklistAnswers(activeFollowupId);
    renderCheckQuestions();
    renderCheckMetaForm(activeFollowupData);
}

function renderQuestionInput(question, answerValue, disabled = false) {
    const currentValue = answerValue;
    const inputName = `question-${question.id}`;
    const type = question.input_type || 'text';
    const disabledAttr = disabled ? 'disabled' : '';
    if (type === 'boolean') {
        const value = currentValue === true || currentValue === 'true' ? 'true' : currentValue === false || currentValue === 'false' ? 'false' : '';
        return `
            <select class="form-select form-select-sm check-question-input" data-question-id="${question.id}" name="${inputName}" ${disabledAttr}>
                <option value="">Seleziona...</option>
                <option value="true" ${value === 'true' ? 'selected' : ''}>Sì</option>
                <option value="false" ${value === 'false' ? 'selected' : ''}>No</option>
            </select>
        `;
    }

    if (type === 'select') {
        const options = Array.isArray(question.options) ? question.options : [];
        const optionRows = options.map((opt) => {
            const selected = String(currentValue) === String(opt) ? 'selected' : '';
            return `<option value="${sanitizeHtml(opt)}" ${selected}>${sanitizeHtml(opt)}</option>`;
        });
        return `
            <select class="form-select form-select-sm check-question-input" data-question-id="${question.id}" name="${inputName}" ${disabledAttr}>
                <option value="">Seleziona...</option>
                ${optionRows.join('')}
            </select>
        `;
    }

    return `<input type="text" class="form-control form-control-sm check-question-input" data-question-id="${question.id}" name="${inputName}" value="${currentValue !== null && currentValue !== undefined ? sanitizeHtml(String(currentValue)) : ''}" ${disabledAttr}>`;
}

function renderCheckQuestions() {
    const questionList = document.getElementById('checkQuestionList');
    if (!questionList) return;
    if (!activeChecklistQuestions.length) {
        questionList.innerHTML = '<div class="text-muted">Nessuna domanda in checklist. Aggiungine una.</div>';
        return;
    }

    const inputsDisabled = !activeFollowupId;
    const hint = inputsDisabled
        ? '<div class="text-muted small mb-2">Crea o seleziona un check per inserire le risposte.</div>'
        : '';
    const rows = activeChecklistQuestions.map((q, idx) => {
        const label = q.question_text || q.question_key || `Domanda ${idx + 1}`;
        const answerValue = activeChecklistAnswers[q.id] ?? null;
        const upDisabled = idx === 0 ? 'disabled' : '';
        const downDisabled = idx === activeChecklistQuestions.length - 1 ? 'disabled' : '';
        return `
        <div class="border rounded p-2 mb-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold">${sanitizeHtml(label)}</div>
                    <div class="small text-muted">${sanitizeHtml(q.input_type || 'text')}</div>
                </div>
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-secondary" type="button" onclick="moveChecklistQuestion(${idx}, -1)" ${upDisabled}>Su</button>
                    <button class="btn btn-outline-secondary" type="button" onclick="moveChecklistQuestion(${idx}, 1)" ${downDisabled}>Giù</button>
                    <button class="btn btn-outline-danger" type="button" onclick="removeChecklistQuestion(${q.id})">Rimuovi</button>
                </div>
            </div>
            <div class="mt-2">${renderQuestionInput(q, answerValue, inputsDisabled)}</div>
        </div>
    `;
    });

    questionList.innerHTML = hint + (rows.join('') || '<div class="text-muted">Nessuna domanda presente</div>');
}

function renderCheckMetaForm(followup) {
    const riskInput = document.getElementById('checkRiskScore');
    const dateInput = document.getElementById('checkFollowUpDate');
    const notesInput = document.getElementById('checkPharmacistNotes');
    if (!riskInput || !dateInput || !notesInput) return;
    const disabled = !followup?.id;
    riskInput.disabled = disabled;
    dateInput.disabled = disabled;
    notesInput.disabled = disabled;
    riskInput.value = followup?.risk_score ?? '';
    dateInput.value = followup?.follow_up_date ?? '';
    notesInput.value = followup?.pharmacist_notes ?? '';
}

function renderCheckHistory(items) {
    const historyBody = document.getElementById('checkHistoryBody');
    if (!historyBody) return;
    if (!items.length) {
        historyBody.innerHTML = '<tr><td colspan="5" class="text-muted">Nessun check registrato</td></tr>';
        return;
    }

    historyBody.innerHTML = items.map((item) => {
        const answersCount = item.answer_count ?? 0;
        const checkTypeLabel = item.check_type === 'initial' ? 'Iniziale' : 'Periodico';
        const statusBadge = item.status === 'canceled'
            ? '<span class="badge bg-secondary">Annullato</span>'
            : '<span class="badge bg-success">Attivo</span>';
        return `
            <tr>
                <td>${sanitizeHtml(item.created_at || '')}</td>
                <td>${sanitizeHtml(item.follow_up_date || '-')}</td>
                <td>${sanitizeHtml(item.risk_score ?? '-')}</td>
                <td>${statusBadge}</td>
                <td>${sanitizeHtml(checkTypeLabel)} · ${answersCount}</td>
            </tr>
        `;
    }).join('');
}

async function createCheckPeriodico() {
    const therapySelect = document.getElementById('checkTherapySelect');
    const therapyId = therapySelect?.value;
    if (!therapyId) {
        alert('Seleziona una terapia prima di creare un check.');
        return;
    }
    try {
        const response = await fetch('api/followups.php?action=init', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ therapy_id: therapyId })
        });
        const result = await response.json();
        if (result.success) {
            activeFollowupId = result.data?.followup?.id || null;
            activeFollowupData = result.data?.followup || null;
            activeChecklistQuestions = result.data?.questions || activeChecklistQuestions;
            activeChecklistAnswers = result.data?.answers || {};
            showFollowupToast('Check periodico creato', 'success');
            loadCheckFollowups(therapyId);
        } else {
            alert(result.error || 'Errore nella creazione del check');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nella creazione del check');
    }
}

async function addCustomQuestion() {
    const therapyId = document.getElementById('checkTherapySelect')?.value;
    if (!therapyId) {
        alert('Seleziona una terapia.');
        return;
    }
    const textInput = document.getElementById('customQuestionText');
    const typeInput = document.getElementById('customQuestionType');
    const text = textInput?.value?.trim();
    const type = typeInput?.value || 'text';
    if (!text) {
        alert('Inserisci il testo della domanda.');
        return;
    }

    try {
        const response = await fetch('api/followups.php?action=checklist-add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ therapy_id: therapyId, text, type })
        });
        const result = await response.json();
        if (result.success) {
            await loadChecklistQuestions(therapyId);
            renderCheckQuestions();
            if (textInput) textInput.value = '';
            showFollowupToast('Domanda aggiunta', 'success');
        } else {
            alert(result.error || 'Errore aggiunta domanda');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nell\'aggiunta della domanda');
    }
}

async function removeChecklistQuestion(questionId) {
    if (!questionId) return;
    try {
        const therapyId = document.getElementById('checkTherapySelect')?.value;
        if (!therapyId) {
            alert('Seleziona una terapia.');
            return;
        }
        const response = await fetch('api/followups.php?action=checklist-remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question_id: questionId, therapy_id: therapyId })
        });
        const result = await response.json();
        if (result.success) {
            await loadChecklistQuestions(therapyId);
            renderCheckQuestions();
            showFollowupToast('Domanda rimossa', 'success');
        } else {
            alert(result.error || 'Errore nella rimozione della domanda');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nella rimozione della domanda');
    }
}

async function moveChecklistQuestion(index, direction) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= activeChecklistQuestions.length) return;
    const therapyId = document.getElementById('checkTherapySelect')?.value;
    if (!therapyId) {
        alert('Seleziona una terapia.');
        return;
    }
    const reordered = [...activeChecklistQuestions];
    const [moved] = reordered.splice(index, 1);
    reordered.splice(newIndex, 0, moved);
    const orderIds = reordered.map((q) => q.id);
    try {
        const response = await fetch('api/followups.php?action=checklist-reorder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order: orderIds, therapy_id: therapyId })
        });
        const result = await response.json();
        if (result.success) {
            activeChecklistQuestions = reordered;
            renderCheckQuestions();
        } else {
            alert(result.error || 'Errore nel riordino');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nel riordino');
    }
}

async function saveAnswers() {
    if (!activeFollowupId) {
        alert('Nessun check selezionato.');
        return;
    }
    const inputs = document.querySelectorAll('.check-question-input');
    const answers = [];

    inputs.forEach((input) => {
        const questionId = input.dataset.questionId;
        let value = input.value;
        if (input.type === 'select-one' && input.value === '') {
            value = null;
        }
        if (input.type === 'select-one' && input.value !== '') {
            value = input.value === 'true' ? true : input.value === 'false' ? false : input.value;
        }
        if (input.type === 'text' && input.value === '') {
            value = null;
        }
        answers.push({ question_id: Number(questionId), answer: value });
    });

    try {
        const response = await fetch(`api/followups.php?action=check-answers&id=${activeFollowupId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ answers })
        });
        const result = await response.json();
        if (result.success) {
            activeChecklistAnswers = result.data?.answers || activeChecklistAnswers;
            renderCheckQuestions();
            const therapyId = document.getElementById('checkTherapySelect')?.value;
            loadCheckFollowups(therapyId);
            showFollowupToast('Risposte salvate', 'success');
        } else {
            alert(result.error || 'Errore nel salvataggio delle risposte');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nel salvataggio delle risposte');
    }
}

async function saveCheckMeta() {
    if (!activeFollowupId) {
        alert('Nessun check selezionato.');
        return;
    }
    const riskInput = document.getElementById('checkRiskScore');
    const dateInput = document.getElementById('checkFollowUpDate');
    const notesInput = document.getElementById('checkPharmacistNotes');
    if (!riskInput || !dateInput || !notesInput) return;

    const payload = {};
    if (riskInput.value !== '') {
        payload.risk_score = Number(riskInput.value);
    }
    if (dateInput.value) {
        payload.follow_up_date = dateInput.value;
    }
    if (notesInput.value) {
        payload.pharmacist_notes = notesInput.value;
    }
    if (!Object.keys(payload).length) {
        alert('Inserisci almeno un dato da aggiornare.');
        return;
    }

    try {
        const response = await fetch(`api/followups.php?action=check-meta&id=${activeFollowupId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (result.success) {
            activeFollowupData = result.data?.followup || activeFollowupData;
            renderCheckMetaForm(activeFollowupData);
            const therapyId = document.getElementById('checkTherapySelect')?.value;
            loadCheckFollowups(therapyId);
            showFollowupToast('Dati check aggiornati', 'success');
        } else {
            alert(result.error || 'Errore nel salvataggio dati check');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nel salvataggio dati check');
    }
}

function showFollowupToast(message, type = 'success') {
    const containerId = 'followupToastContainer';
    let container = document.getElementById(containerId);
    if (!container) {
        container = document.createElement('div');
        container.id = containerId;
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        document.body.appendChild(container);
    }

    const bgClass = type === 'success' ? 'bg-success' : 'bg-danger';
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white ${bgClass} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${sanitizeHtml(message)}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
        if (!container.children.length) {
            container.remove();
        }
    });
    bsToast.show();
}

function safeJsonString(value) {
    try {
        return JSON.stringify(JSON.parse(value));
    } catch (e) {
        return value;
    }
}

function formatJson(value) {
    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch (e) {
            return value;
        }
    }
    try {
        return JSON.stringify(value || {}, null, 2);
    } catch (e) {
        return '';
    }
}

function sanitizeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function populateTherapySelect(selectEl, selectedId = null, placeholder = 'Seleziona una terapia') {
    if (!selectEl) return [];
    selectEl.innerHTML = `<option value="">${sanitizeHtml(placeholder)}</option>`;
    try {
        const response = await fetch('api/therapies.php?status=active');
        const result = await response.json();
        if (!result.success) return [];
        const items = result.data?.items || result.data || [];
        items.forEach((therapy) => {
            const option = document.createElement('option');
            option.value = therapy.id;
            option.textContent = formatTherapyOption(therapy);
            if (selectedId && String(selectedId) === String(therapy.id)) {
                option.selected = true;
            }
            selectEl.appendChild(option);
        });
        return items;
    } catch (error) {
        console.error('Errore caricamento terapie', error);
        return [];
    }
}

function formatTherapyOption(therapy) {
    const patientName = `${therapy.first_name || ''} ${therapy.last_name || ''}`.trim() || 'Paziente';
    const condition = therapy.primary_condition || therapy.condition || therapy.therapy_title || '-';
    const title = therapy.therapy_title || '-';
    return `${patientName} – ${condition} – ${title}`;
}

function openTherapyWizard(therapyId = null) {
    console.log('openTherapyWizard', therapyId);
    const modalEl = document.getElementById('therapyWizardModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

async function openRemindersModal(therapyId = null) {
    if (!reminderModalContainer) return;

    reminderModalContainer.innerHTML = `
        <div class="modal fade" id="reminderModalDialog" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Promemoria terapia</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Terapia</label>
                            <select class="form-select" id="reminderTherapySelect"></select>
                        </div>
                        <div id="reminderList" class="mb-3"></div>
                        <form id="reminderForm" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Titolo</label>
                                <input type="text" class="form-control" name="title" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Canale</label>
                                <select class="form-select" name="channel" required>
                                    <option value="email">Email</option>
                                    <option value="sms">SMS</option>
                                    <option value="whatsapp">Whatsapp</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Messaggio</label>
                                <textarea class="form-control" name="message" rows="3" required></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" name="type" required>
                                    <option value="one-shot">One-shot</option>
                                    <option value="daily">Giornaliero</option>
                                    <option value="weekly">Settimanale</option>
                                    <option value="monthly">Mensile</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data/Ora</label>
                                <input type="datetime-local" class="form-control" name="scheduled_at" required>
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                                <button type="submit" class="btn btn-primary">Salva promemoria</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    `;

    const therapySelect = document.getElementById('reminderTherapySelect');
    await populateTherapySelect(therapySelect, therapyId);
    therapySelect?.addEventListener('change', () => {
        loadReminders(therapySelect.value);
    });

    const modal = new bootstrap.Modal(document.getElementById('reminderModalDialog'));
    attachReminderForm(therapySelect);
    loadReminders(therapySelect?.value);
    modal.show();
}

async function openReportsModal(therapyId = null) {
    if (!reportModalContainer) return;

    reportModalContainer.innerHTML = `
        <div class="modal fade" id="reportModalDialog" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Report terapia</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Terapia</label>
                            <select class="form-select" id="reportTherapySelect"></select>
                        </div>
                        <div id="reportList" class="mb-3"></div>
                        <form id="reportForm" class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Modalità</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="reportMode" id="reportModeAll" value="all" checked>
                                    <label class="form-check-label" for="reportModeAll">Tutti i check periodici</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="reportMode" id="reportModeSingle" value="single">
                                    <label class="form-check-label" for="reportModeSingle">Solo uno</label>
                                </div>
                            </div>
                            <div class="col-md-6 d-none" id="reportFollowupWrapper">
                                <label class="form-label">Seleziona check o follow-up</label>
                                <select class="form-select" id="reportFollowupSelect"></select>
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                                <button type="button" class="btn btn-success d-none" id="reportGeneratePdf">Scarica report</button>
                            </div>
                        </form>
                        <div id="reportPreview" class="report-preview bg-light p-2 rounded border"></div>
                    </div>
                </div>
            </div>
        </div>
    `;

    const therapySelect = document.getElementById('reportTherapySelect');
    await populateTherapySelect(therapySelect, therapyId);
    therapySelect?.addEventListener('change', () => {
        loadReports(therapySelect.value);
    });

    const modal = new bootstrap.Modal(document.getElementById('reportModalDialog'));
    attachReportForm(therapySelect);
    loadReports(therapySelect?.value);
    handleReportPreview(
        therapySelect,
        document.getElementById('reportFollowupSelect'),
        document.getElementById('reportPreview'),
        document.getElementById('reportGeneratePdf')
    );
    modal.show();
}

async function fetchFollowupsOptions(therapyId) {
    if (!therapyId) return [];
    try {
        const response = await fetch(`api/followups.php?therapy_id=${therapyId}`);
        const result = await response.json();
        if (!result.success) return [];
        return result.data?.items || [];
    } catch (error) {
        console.error(error);
        return [];
    }
}

function buildQuestionListHtml(questions = [], condition = null) {
    if (!questions.length) {
        return '<div class="text-muted">Nessuna domanda disponibile</div>';
    }
    const rows = questions.map((q) => {
        const baseLabel = q.text || q.question_text || q.label || '';
        const label = baseLabel
            ? baseLabel
            : q.custom || !q.key
                ? q.key || 'Domanda'
                : getConditionQuestionLabel(condition, q.key);
        return `<li class="list-group-item d-flex justify-content-between align-items-start">
            <div class="me-3">${sanitizeHtml(label || '')}</div>
            <span class="fw-semibold">${sanitizeHtml(formatAnswerValue(q.answer))}</span>
        </li>`;
    });
    return `<ul class="list-group list-group-flush">${rows.join('')}</ul>`;
}

function buildSurveyHtml(survey = {}, condition = null) {
    const answers = survey.answers || {};
    const entries = Object.entries(answers);
    if (!entries.length) {
        return '<div class="text-muted">Nessuna risposta disponibile</div>';
    }
    const rows = entries.map(([key, value]) => {
        const label = getConditionQuestionLabel(condition, key);
        return `<li class="list-group-item d-flex justify-content-between align-items-start">
            <div class="me-3">${sanitizeHtml(label)}</div>
            <span class="fw-semibold">${sanitizeHtml(formatAnswerValue(value))}</span>
        </li>`;
    });
    return `<ul class="list-group list-group-flush">${rows.join('')}</ul>`;
}

function buildFollowupHtml(followup, condition = null) {
    const snapshot = followup.snapshot || {};
    const checklistQuestions = (followup.questions || []).map((q) => ({
        ...q,
        question_text: q.text || q.question_text,
        custom: false
    }));
    const questions = (snapshot.questions || []).map((q) => ({ ...q, custom: false }));
    const custom = (snapshot.custom_questions || []).map((q) => ({ ...q, custom: true }));
    const checkTypeLabel = followup.check_type === 'initial' ? 'Iniziale' : 'Periodico';
    const headingLabel = followup.entry_type === 'check' ? `Check ${checkTypeLabel}` : 'Follow-up';
    const listHtml = checklistQuestions.length
        ? `<h6>Domande checklist</h6>${buildQuestionListHtml(checklistQuestions, condition)}`
        : `
            <div class="mt-3">
                <h6>Domande base</h6>
                ${buildQuestionListHtml(questions, condition)}
            </div>
            <div class="mt-3">
                <h6>Domande personalizzate</h6>
                ${buildQuestionListHtml(custom, condition)}
            </div>
        `;
    return `
        <div class="mb-3 border rounded p-2">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">${headingLabel} del ${sanitizeHtml(followup.follow_up_date || followup.created_at || '-')}</div>
                    <div class="small text-muted">Rischio: ${sanitizeHtml(followup.risk_score ?? '-')}</div>
                </div>
                <div class="small text-muted">ID #${sanitizeHtml(followup.id)}</div>
            </div>
            <div class="mt-2"><strong>Note farmacista:</strong> ${sanitizeHtml(followup.pharmacist_notes || '-')}</div>
            <div class="mt-3">${listHtml}</div>
        </div>
    `;
}

function buildReportPreviewHtml(content) {
    if (!content) {
        return '<div class="text-danger">Anteprima non disponibile</div>';
    }

    // Normalizza testo: elimina <br>, newline, spazi doppi, trim.
    // IMPORTANT: prima normalizzi, poi sanitizzi (così non ti porti dietro i \n).
    const cleanRaw = (v) =>
        String(v ?? '')
            .replace(/<br\s*\/?>/gi, ' ')
            .replace(/\r?\n/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

    const clean = (v, fallback = '-') => sanitizeHtml(cleanRaw(v) || fallback);

    const condition =
        cleanRaw(content.chronic_care?.condition) ||
        cleanRaw(content.therapy?.primary_condition) ||
        '-';

    const pharmacyName = clean(content.pharmacy?.name);
    const pharmacyAddress = [cleanRaw(content.pharmacy?.address), cleanRaw(content.pharmacy?.city)]
        .filter(Boolean)
        .join(', ');
    const pharmacyEmail = clean(content.pharmacy?.email);
    const pharmacyPhone = clean(content.pharmacy?.phone);

    const pharmacistName = clean(content.pharmacist?.name);
    const pharmacistEmail = clean(content.pharmacist?.email);

    const patientFullName = clean(
        `${cleanRaw(content.patient?.first_name)} ${cleanRaw(content.patient?.last_name)}`.trim(),
        '-'
    );
    const patientCf = clean(content.patient?.codice_fiscale);
    const patientBirth = clean(content.patient?.birth_date);
    const patientEmail = clean(content.patient?.email);
    const patientPhone = clean(content.patient?.phone);
    const caregivers = Array.isArray(content.caregivers) ? content.caregivers : [];

    const therapyTitle = clean(content.therapy?.title);
    const therapyDesc = clean(content.therapy?.description);
    const therapyStart = clean(content.therapy?.start_date);
    const therapyEnd = clean(content.therapy?.end_date);
    const conditionSafe = clean(condition);

    const checkFollowups = Array.isArray(content.check_followups) ? content.check_followups : [];
    const manualFollowups = Array.isArray(content.manual_followups) ? content.manual_followups : [];

    const checkHtml = checkFollowups.length
        ? checkFollowups.map((f) => buildFollowupHtml(f, condition)).join('')
        : '<div class="text-muted small">Nessun check periodico</div>';

    const followupHtml = manualFollowups.length
        ? manualFollowups.map((f) => buildFollowupHtml(f, condition)).join('')
        : '<div class="text-muted small">Nessun follow-up</div>';

    const caregiverHtml = caregivers.length
        ? caregivers
              .map((c) => {
                  const name = clean(`${cleanRaw(c.first_name)} ${cleanRaw(c.last_name)}`.trim(), '-');
                  const relation = clean(c.relation_to_patient || c.type || '-');
                  const contact = clean(`${cleanRaw(c.email || '-')}`) + ' | ' + clean(`${cleanRaw(c.phone || '-')}`);
                  return `<li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="me-3" style="min-width:0;">
                            <div class="fw-semibold">${name}</div>
                            <div class="small text-muted">${relation}</div>
                        </div>
                        <div class="small text-end" style="max-width:45%; min-width:0; overflow-wrap:anywhere; word-break:break-word; white-space:normal;">
                            ${contact}
                        </div>
                    </li>`;
              })
              .join('')
        : '<div class="text-muted small">Nessun caregiver registrato</div>';

    return `
        <div class="border rounded p-3">

            <!-- FARMACIA / FARMACISTA -->
            <div class="row g-3 report-section">
                <div class="col-md-6">
                    <h6 class="mb-2">Farmacia</h6>
                    <div class="fw-semibold">${pharmacyName}</div>
                    <div class="small text-muted">${sanitizeHtml(pharmacyAddress || '-')}</div>
                    <div class="small text-muted mt-4">Email: ${pharmacyEmail} | Tel: ${pharmacyPhone}</div>
                </div>

                <div class="col-md-6">
                    <h6 class="mb-2">Farmacista</h6>
                    <div class="fw-semibold">${pharmacistName}</div>
                    <div class="small text-muted">${pharmacistEmail}</div>
                </div>
            </div>

            <!-- PAZIENTE / TERAPIA -->
            <div class="row g-3 report-section">
                <div class="col-md-6">
                    <h6 class="mb-2">Paziente</h6>
                    <div class="fw-semibold">${patientFullName}</div>
                    <div class="small text-muted">CF: ${patientCf}</div>
                    <div class="small text-muted">Nascita: ${patientBirth}</div>
                    <div class="small text-muted">Contatti: ${patientEmail} | ${patientPhone}</div>
                </div>

                <div class="col-md-6">
                    <h6 class="mb-2">Terapia</h6>
                    <div class="fw-semibold">${therapyTitle}</div>
                    <div class="small text-muted">${therapyDesc}</div>
                    <div class="small text-muted">Periodo: ${therapyStart} - ${therapyEnd}</div>
                    <div class="small text-muted">Condizione: ${conditionSafe}</div>
                </div>
            </div>

            <!-- CAREGIVER -->
            <div class="report-section">
                <h6 class="mb-2">Caregiver</h6>
                ${caregivers.length ? `<ul class="list-group list-group-flush">${caregiverHtml}</ul>` : caregiverHtml}
            </div>

            <!-- CHECK PERIODICI -->
            <div>
                <h6 class="mb-2">Check periodici</h6>
                ${checkHtml}
            </div>

            <!-- FOLLOW-UP -->
            <div class="mt-4">
                <h6 class="mb-2">Follow-up</h6>
                ${followupHtml}
            </div>

        </div>
    `;
}


async function handleReportPreview(therapySelect, followupSelect, previewContainer, generatePdfBtn) {
    if (!therapySelect || !previewContainer) return;
    const therapyId = therapySelect.value;
    if (!therapyId) {
        previewContainer.innerHTML = '<div class="text-muted">Seleziona una terapia per generare il report</div>';
        if (generatePdfBtn) generatePdfBtn.classList.add('d-none');
        return;
    }
    const mode = document.querySelector('input[name="reportMode"]:checked')?.value || 'all';
    const followupId = followupSelect?.value;
    if (mode === 'single' && !followupId) {
        previewContainer.innerHTML = '<div class="text-muted">Seleziona check o follow-up</div>';
        if (generatePdfBtn) generatePdfBtn.classList.add('d-none');
        return;
    }

    previewContainer.innerHTML = '<div class="text-center text-muted">Generazione anteprima...</div>';
    const query = new URLSearchParams({
        action: 'preview',
        therapy_id: therapyId,
        mode,
        followup_id: followupId || ''
    });
    try {
        const response = await fetch(`api/reports.php?${query.toString()}`);
        const result = await response.json();
        if (!result.success) {
            previewContainer.innerHTML = `<div class="text-danger">${sanitizeHtml(result.error || 'Errore anteprima')}</div>`;
            if (generatePdfBtn) generatePdfBtn.classList.add('d-none');
            return;
        }
        previewContainer.innerHTML = buildReportPreviewHtml(result.data?.content);
        if (generatePdfBtn) {
            if (result.data?.pdf_available) {
                generatePdfBtn.classList.remove('d-none');
            } else {
                generatePdfBtn.classList.add('d-none');
            }
        }
    } catch (error) {
        console.error(error);
        previewContainer.innerHTML = '<div class="text-danger">Errore di rete nella creazione anteprima</div>';
        if (generatePdfBtn) generatePdfBtn.classList.add('d-none');
    }
}

function handleReportDownload(therapySelect, followupSelect) {
    const therapyId = therapySelect?.value;
    if (!therapyId) {
        alert('Seleziona una terapia.');
        return;
    }
    const mode = document.querySelector('input[name="reportMode"]:checked')?.value || 'all';
    const followupId = followupSelect?.value;
    if (mode === 'single' && !followupId) {
        alert('Seleziona check o follow-up.');
        return;
    }
    const query = new URLSearchParams({
        action: 'pdf',
        therapy_id: therapyId,
        mode,
        followup_id: followupId || ''
    });
    window.open(`api/reports.php?${query.toString()}`, '_blank');
}

async function openFollowupModal(therapyId = null) {
    if (!followupModalContainer) return;

    followupModalContainer.innerHTML = `
        <div class="modal fade" id="followupModalDialog" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Follow-up terapia</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Terapia</label>
                            <select class="form-select" id="followupTherapySelect"></select>
                        </div>
                        <div id="followupList" class="mb-3"></div>
                        <form id="followupForm" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Rischio aggiornato</label>
                                <input type="number" class="form-control" name="risk_score">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Note farmacista</label>
                                <textarea class="form-control" name="pharmacist_notes" rows="2"></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data follow-up</label>
                                <input type="date" class="form-control" name="follow_up_date">
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                                <button type="submit" class="btn btn-primary">Salva follow-up</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    `;

    const therapySelect = document.getElementById('followupTherapySelect');
    await populateTherapySelect(therapySelect, therapyId);
    therapySelect?.addEventListener('change', () => {
        loadFollowups(therapySelect.value);
    });

    const modal = new bootstrap.Modal(document.getElementById('followupModalDialog'));
    attachFollowupForm(therapySelect);
    loadFollowups(therapySelect?.value);
    modal.show();
}

async function suspendTherapy(therapyId) {
    if (!therapyId) return;
    const confirmed = confirm('Sei sicuro di voler sospendere questa terapia?');
    if (!confirmed) return;

    try {
        const response = await fetch(`api/therapies.php?id=${therapyId}`, {
            method: 'DELETE'
        });
        const result = await response.json();
        if (result.success) {
            loadTherapies(currentTherapyPage);
        } else {
            alert(result.error || 'Errore nella sospensione della terapia');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nella sospensione della terapia');
    }
}

async function deleteTherapy(therapyId) {
    if (!therapyId) return;
    const confirmed = confirm('Eliminare la terapia? Verranno rimossi anche promemoria/check/report collegati.');
    if (!confirmed) return;

    try {
        const response = await fetch('api/therapies.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ therapy_id: therapyId })
        });
        const result = await response.json();
        if (result.success) {
            closeTherapyModals(therapyId);
            loadTherapies(currentTherapyPage);
        } else {
            alert(result.error || 'Errore nell\'eliminazione della terapia');
        }
    } catch (error) {
        console.error(error);
        alert('Errore di rete nell\'eliminazione della terapia');
    }
}

function closeTherapyModals(therapyId) {
    const reminderSelect = document.getElementById('reminderTherapySelect');
    if (reminderSelect && `${therapyId}` === `${reminderSelect.value}`) {
        const modalEl = document.getElementById('reminderModalDialog');
        const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
        modal?.hide();
    }

    const reportSelect = document.getElementById('reportTherapySelect');
    if (reportSelect && `${therapyId}` === `${reportSelect.value}`) {
        const modalEl = document.getElementById('reportModalDialog');
        const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
        modal?.hide();
    }

    const followupSelect = document.getElementById('followupTherapySelect');
    if (followupSelect && `${therapyId}` === `${followupSelect.value}`) {
        const modalEl = document.getElementById('followupModalDialog');
        const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
        modal?.hide();
    }
}
