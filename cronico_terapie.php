<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth_middleware.php';

requirePharmacistOrAdmin();

$current_page = 'cronico_terapie';
$page_title = 'Gestione Terapie Paziente Cronico';

$additional_css = [
    'assets/css/cronico_terapie_tweaks.css',
];

$additional_js = [
    'assets/js/cronico_terapie.js',
    'adesione-terapie/assets/js/signature_pad.js',
    'adesione-terapie/assets/js/cronico_wizard.js'
];


include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="jt-adesione-terapie">
                <div class="jt-adesione-terapie__header pt-3 pb-2 mb-3 border-bottom">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div>
                            <h1 class="h2 mb-0">Gestione Terapie</h1>
                            <p class="text-muted mb-0">Presa in carico, monitoraggio e follow-up pazienti cronici</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap justify-content-end jt-actions">
                            <button class="btn btn-primary" id="btnNewTherapy">
                                <i class="fas fa-plus me-2"></i>Nuova terapia
                            </button>
                            <button class="btn btn-outline-secondary" id="btnReminder">
                                <i class="fas fa-bell me-2"></i>Imposta promemoria
                            </button>
                            <button class="btn btn-outline-secondary" id="btnReport">
                                <i class="fas fa-file-alt me-2"></i>Genera report
                            </button>
                            <button class="btn btn-outline-secondary" id="btnFollowup">
                                <i class="fas fa-stethoscope me-2"></i>Check periodico
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 jt-adesione-terapie__filters">
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="therapySearch" class="form-label">Ricerca</label>
                                <input type="text" id="therapySearch" class="form-control" placeholder="Nome, cognome, CF o titolo terapia">
                            </div>
                            <div class="col-md-3">
                                <label for="therapyStatus" class="form-label">Stato terapia</label>
                                <select id="therapyStatus" class="form-select">
                                    <option value="">Tutte</option>
                                    <option value="active">Attive</option>
                                    <option value="planned">Programmate</option>
                                    <option value="completed">Completate</option>
                                    <option value="suspended">Sospese</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-grid">
                                <button class="btn btn-outline-primary" id="btnApplyFilters">
                                    <i class="fas fa-search me-1"></i>Applica
                                </button>
                            </div>
                            <div class="col-md-3 text-md-end">
                                <small class="text-muted" id="therapyResultsInfo"></small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card jt-adesione-terapie__table">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="therapiesTable">
                                <thead>
                                    <tr>
                                        <th>Paziente</th>
                                        <th>Codice fiscale</th>
                                        <th>Patologia</th>
                                        <th>Titolo terapia</th>
                                        <th>Stato</th>
                                        <th>Inizio</th>
                                        <th>Fine</th>
                                        <th class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody id="therapiesTableBody">
                                    <tr>
                                        <td colspan="8" class="text-center">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Caricamento...</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <nav class="jt-adesione-terapie__pagination" aria-label="Paginazione terapie">
                            <ul class="pagination justify-content-center" id="therapyPagination"></ul>
                        </nav>
                    </div>
                </div>

                <div id="therapyWizardModal"></div>
                <div id="reminderModal"></div>
                <div id="reportModal"></div>
                <div id="followupModal"></div>
            </div>
        </main>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
