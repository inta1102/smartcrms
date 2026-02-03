<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoanImportController;
use App\Http\Controllers\NplCaseController;
use App\Http\Controllers\AoPerformanceController;
use App\Http\Controllers\AoAgendaController;
use App\Http\Controllers\ActionScheduleController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\WarningLetterController;
use App\Http\Controllers\CaseActionProofController;
use App\Http\Controllers\LegacySpProofController;
use App\Http\Controllers\NonLitigationActionController;
use App\Http\Controllers\CaseSpLegacyController;
use App\Http\Controllers\CaseLegacyProofController;
use App\Http\Controllers\HtMonitoringController;

use App\Http\Controllers\Legal\NplLegalActionController;
use App\Http\Controllers\LegalActionController;
use App\Http\Controllers\Legal\LegalActionDocumentController;
use App\Http\Controllers\Legal\LegalEventController;
use App\Http\Controllers\Legal\SomasiController;
use App\Http\Controllers\LegalActionEventController;
use App\Http\Controllers\LegalActionSomasiController;
use App\Http\Controllers\Legal\HtExecutionController;
use App\Http\Controllers\Legal\LegalChecklistController;
use App\Http\Controllers\Legal\LegalEscalationController;

use App\Http\Controllers\NplCaseResolutionTargetController;
use App\Http\Controllers\ResolutionTargetApprovalController;

use App\Http\Controllers\Supervision\TlDashboardController;
use App\Http\Controllers\Supervision\KasiDashboardController;
use App\Http\Controllers\Supervision\TargetApprovalTlController;
use App\Http\Controllers\Supervision\TargetApprovalKasiController;
use App\Http\Controllers\AoScheduleDashboardController;
use App\Http\Controllers\ExecutiveDashboardController;

use App\Http\Controllers\EwsSummaryController;
use App\Http\Controllers\Ews\EwsMacetController;

// ✅ TAMBAH INI (controller org assignment kamu)
use App\Http\Controllers\Supervision\OrgAssignmentController;

use App\Http\Controllers\RestructureDashboardController;    
use App\Http\Controllers\Admin\JobMonitorController;
// use App\Http\Controllers\Legal\LegalDashboardController;
use App\Http\Controllers\Legal\LegalActionProposalController;
use App\Http\Controllers\Legal\LegalActionProposalApprovalController;
use App\Http\Controllers\Executive\KomTargetDashboardController;

use App\Http\Controllers\Kti\KtiResolutionTargetController;

use App\Models\LegalAction;

use App\Http\Controllers\Lending\LendingPerformanceController;
use App\Http\Controllers\Lending\LendingTrendController;

use App\Http\Controllers\ShmCheckRequestController;
use App\Http\Controllers\Kpi\MarketingTargetController;
use App\Http\Controllers\Kpi\MarketingTargetApprovalController;
use App\Http\Controllers\Kpi\MarketingKpiAchievementController;
use App\Http\Controllers\Kpi\MarketingKpiRankingController;
use App\Http\Controllers\Ews\EwsCkpnController;


Route::model('action', LegalAction::class);


// ROOT
Route::get('/', fn () => redirect()->route('login'));

// AUTH
Route::get('/login',  [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout',[AuthController::class, 'logout'])->name('logout');
Route::get('/forbidden', function () {
    abort(403);
})->name('forbidden');


Route::post('/cases/{case}/sync-legacy-sp', [\App\Http\Controllers\NplCaseController::class, 'syncLegacySp'])
    ->name('cases.sync-legacy-sp');

Route::post('/cases/{case}/legal/start', [\App\Http\Controllers\Legal\LegalEscalationController::class, 'start'])
    ->name('cases.legal.start');


Route::middleware(['auth', 'kti_or_ti'])->group(function () {
    Route::get('/admin/jobs', [JobMonitorController::class, 'index'])
        ->name('admin.jobs.index');

    Route::get('/admin/jobs/failed', [JobMonitorController::class, 'failed'])
        ->name('admin.jobs.failed');

    Route::post('/admin/jobs/failed/{id}/retry', [JobMonitorController::class, 'retryFailed'])
        ->name('admin.jobs.failed.retry');

    Route::delete('/admin/jobs/failed/{id}', [JobMonitorController::class, 'deleteFailed'])
        ->name('admin.jobs.failed.delete');

    Route::post('/admin/jobs/run/sync-users', [JobMonitorController::class, 'runSyncUsers'])
        ->name('admin.jobs.run.sync_users');

});


// =======================================================
// APP (AUTH)
// =======================================================
Route::middleware('auth')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/dashboard/executive', [ExecutiveDashboardController::class, 'index'])
        ->name('dashboard.executive');

    Route::get('/loans/import', [LoanImportController::class, 'showForm'])->name('loans.import.form');

    Route::post('/loans/import', [LoanImportController::class, 'import'])->name('loans.import.process');
    Route::post('/loans/legacy-sync', [LoanImportController::class, 'legacySync'])->name('loans.legacy.sync');
    Route::post('/loans/update-jadwal', [LoanImportController::class, 'updateJadwal'])->name('loans.jadwal.update');
    Route::get('/loans/legacy-sync/status', [LoanImportController::class, 'legacySyncStatus'])
        ->name('loans.legacy.status');

    Route::get('/loans/update-jadwal/status', [LoanImportController::class, 'updateJadwalStatus'])
        ->name('loans.jadwal.status');

    // EWS
    Route::get('/ews/summary', [EwsSummaryController::class, 'index'])
        ->name('ews.summary');

    Route::get('/ews/macet', [EwsMacetController::class, 'index'])
        ->name('ews.macet');

    Route::get('/ews/detail/export', [EwsMacetController::class, 'exportDetail'])
        ->name('ews.detail.export');

    Route::get('/ews/ckpn', [EwsCkpnController::class, 'index'])->name('ews.ckpn.index');

    Route::get('/ews/ckpn/export', [EwsCkpnController::class, 'export'])
        ->name('ews.ckpn.export');


    Route::get('/rs/monitoring', [RestructureDashboardController::class, 'index'])
        ->name('rs.monitoring.index');

    Route::post('/rs/monitoring/action-status', [RestructureDashboardController::class, 'updateActionStatus'])
        ->name('rs.monitoring.action-status');
    
    // NPL Cases
    Route::prefix('cases')->name('cases.')->group(function () {
        Route::get('/',         [NplCaseController::class, 'index'])->name('index');
        Route::get('/overdue',  [NplCaseController::class, 'overdue'])->name('overdue');
        Route::get('/{case}',   [NplCaseController::class, 'show'])->name('show');

        Route::post('/{case}/actions', [NplCaseController::class, 'storeAction'])->name('actions.store');

        Route::get('/{case}/visits/quick-start', [VisitController::class, 'quickStart'])->name('visits.quickStart');

        Route::get('/{case}/sp/{type}',  [NplCaseController::class, 'showSpForm'])->name('sp.form');
        Route::post('/{case}/sp/{type}', [NplCaseController::class, 'storeSp'])->name('sp.store');

        Route::get('/{case}/actions/{action}/proof',        [CaseActionProofController::class, 'show'])->name('actions.proof');
        // Route::get('/{case}/actions/{action}/legacy-proof', [CaseLegacyProofController::class, 'show'])->name('actions.legacy_proof');
        // Route::get('/{case}/actions/{action}/legacy-proof', [LegacySpProofController::class, 'show'])->name('actions.legacy_proof');
        Route::get('/{case}/actions/{caseAction}/legacy-proof', [CaseLegacyProofController::class, 'show'])
            ->name('actions.legacy_proof');

        Route::prefix('{case}/non-litigasi')->name('nonlit.')->group(function () {
            Route::get('/',       [NonLitigationActionController::class, 'index'])->name('index');
            Route::get('/create', [NonLitigationActionController::class, 'create'])->name('create');
            Route::post('/',      [NonLitigationActionController::class, 'store'])->name('store');
        });

        Route::get('/{case}/sp-legacy', [CaseSpLegacyController::class, 'index'])->name('sp_legacy.index');
        Route::post('/{case}/sp-legacy/{type}/issue',    [CaseSpLegacyController::class, 'issue'])->name('sp_legacy.issue');
        Route::post('/{case}/sp-legacy/{type}/ship',     [CaseSpLegacyController::class, 'ship'])->name('sp_legacy.ship');
        Route::post('/{case}/sp-legacy/{type}/finalize', [CaseSpLegacyController::class, 'finalize'])->name('sp_legacy.finalize');
    });

    // Non Litigasi by id
    Route::prefix('non-litigasi')->name('nonlit.')->group(function () {
        Route::get('/{nonLit}',      [NonLitigationActionController::class, 'show'])->name('show');
        Route::get('/{nonLit}/edit', [NonLitigationActionController::class, 'edit'])->name('edit');
        Route::put('/{nonLit}',      [NonLitigationActionController::class, 'update'])->name('update');

        Route::post('/{nonLit}/submit',  [NonLitigationActionController::class, 'submit'])->name('submit');
        Route::post('/{nonLit}/approve', [NonLitigationActionController::class, 'approve'])->name('approve');
        Route::post('/{nonLit}/reject',  [NonLitigationActionController::class, 'reject'])->name('reject');
    });

    // Dashboard AO
    Route::prefix('dashboard/ao')->name('dashboard.ao.')->group(function () {
        Route::get('/',                [AoPerformanceController::class, 'index'])->name('index');
        Route::get('/{aoCode}',        [AoPerformanceController::class, 'show'])->name('show');
        Route::get('/{aoCode}/export', [AoPerformanceController::class, 'export'])->name('export');
        Route::get('/{aoCode}/agenda', [AoAgendaController::class, 'index'])->name('agenda');
    });

    // Schedules & Visits
    Route::prefix('schedules')->name('schedules.')->group(function () {
        Route::patch('/{schedule}/complete', [ActionScheduleController::class, 'complete'])->name('complete');
        Route::get('/{schedule}/sp-log',  [WarningLetterController::class, 'create'])->name('sp.log');
        Route::post('/{schedule}/sp-log', [WarningLetterController::class, 'store'])->name('sp.store');
    });

    Route::prefix('visits')->name('visits.')->group(function () {
        Route::get('/{schedule}/start', [VisitController::class, 'create'])->name('start');
        Route::post('/{schedule}',      [VisitController::class, 'store'])->name('store');
    });

    // Create Legal Action from NPL Case
    Route::prefix('npl-cases/{case}/legal-actions')->name('npl.legal-actions.')->group(function () {
        Route::get('/create', [NplLegalActionController::class, 'create'])->name('create');
        Route::post('/',      [NplLegalActionController::class, 'store'])->name('store');
    });

    // Resolution target flow
    // AO propose (nested)
    Route::post('/npl-cases/{case}/resolution-targets', [NplCaseResolutionTargetController::class, 'store'])
        ->name('npl-cases.resolution-targets.store');

    // Approval (by target)
    Route::post('/resolution-targets/{target}/approve-tl', [ResolutionTargetApprovalController::class, 'approveTl'])
        ->name('resolution-targets.approve-tl');

    Route::post('/resolution-targets/{target}/approve-kasi', [ResolutionTargetApprovalController::class, 'approveKasi'])
        ->name('resolution-targets.approve-kasi');

    Route::post('/resolution-targets/{target}/reject', [ResolutionTargetApprovalController::class, 'reject'])
        ->name('resolution-targets.reject');

    Route::prefix('legal-actions')->name('legal-actions.')->group(function () {

        // ========== VIEW DASHBOARD / LIST ==========
        Route::middleware(['role:KBL,KTI,KSR,KSL,DIREKSI,DIR,KOM,BE'])
            ->get('/', [LegalActionController::class, 'index'])
            ->name('index');

        // ========== SHOW + UPDATE CONTENT ==========
        Route::middleware(['role:KBL,KTI,KSR,KSL,DIREKSI,DIR,KOM,BE'])->group(function () {

            Route::get('/{action}', [LegalActionController::class, 'show'])
                ->whereNumber('action')
                ->name('show');

            Route::put('/{action}', [LegalActionController::class, 'update'])
                ->whereNumber('action')
                ->name('update');
        
            Route::post('/{action}/documents', [LegalActionDocumentController::class, 'store'])->name('documents.store');
            Route::get('/{action}/documents/{doc}/download', [LegalActionDocumentController::class, 'download'])->name('documents.download');
            Route::delete('/{action}/documents/{doc}', [LegalActionDocumentController::class, 'destroy'])->name('documents.destroy');

            Route::post('/{action}/events/{event}/done', [LegalEventController::class, 'markDone'])->name('events.done');
            Route::post('/{action}/events/{event}/cancel', [LegalEventController::class, 'cancel'])->name('events.cancel');
            Route::post('/{action}/events/{event}/reschedule', [LegalEventController::class, 'reschedule'])->name('events.reschedule');

            // SOMASI
            Route::prefix('{action}/somasi')->name('somasi.')->group(function () {
                Route::get('/', [SomasiController::class, 'show'])->name('show');
                Route::post('/mark-sent', [SomasiController::class, 'markSent'])->name('markSent');
                Route::post('/mark-received', [SomasiController::class, 'markReceived'])->name('markReceived');
                Route::post('/mark-response', [SomasiController::class, 'markResponse'])->name('markResponse');
                Route::post('/mark-no-response', [SomasiController::class, 'markNoResponse'])->name('markNoResponse');

                Route::post('/documents', [SomasiController::class, 'uploadDocument'])->name('uploadDocument');

                Route::post('/shipping', [LegalActionSomasiController::class, 'saveShipping'])->name('shipping');
                Route::post('/receipt',  [LegalActionSomasiController::class, 'saveReceipt'])->name('receipt');
            });

            Route::post('/{action}/events/somasi/received', [LegalActionEventController::class, 'markSomasiReceived'])
                ->name('somasi.received');
        });

        // ========== UPDATE STATUS (lebih ketat, tanpa BE) ==========
        Route::middleware(['role:KBL,KTI,KSR,KSL,DIREKSI,DIR,KOM'])
            ->post('/{action}/status', [LegalActionController::class, 'updateStatus'])
            ->whereNumber('action')
            ->name('update-status');

        // ========== HT EXECUTION ==========
        Route::middleware(['role:KBL,KTI,KSR,KSL,DIR,BE'])->group(function () {
            // Route::get('/{action}/ht',  [HtExecutionController::class, 'show'])->name('ht.show');
            // Route::post('/{action}/ht', [HtExecutionController::class, 'upsertExecution'])->name('ht.upsert');
            Route::post('/{action}/checklists/save', [LegalChecklistController::class, 'save'])->name('checklists.save');
        });

        Route::middleware(['role:KBL,KTI,KSR,KSL,DIREKSI,DIR'])->group(function () {
            Route::post('/{action}/ht/status', [HtExecutionController::class, 'updateHtStatus'])
                ->whereNumber('action')
                ->name('ht.status');
            Route::post('/{action}/ht/close',  [LegalActionController::class, 'closeHt'])->name('ht.close');
            Route::get('/{action}/ht/audit_pdf', [LegalActionController::class, 'htAuditPdf'])->name('ht.audit_pdf');
        });
    });

    // =======================================================
    // Monitoring HT (auth + gate) — gate viewHtMonitoring sudah ada
    // =======================================================
    Route::middleware(['can:viewHtMonitoring'])
        ->prefix('monitoring/ht')
        ->name('monitoring.ht.')
        ->group(function () {
            Route::get('/',        [HtMonitoringController::class, 'index'])->name('index');
            Route::get('/summary', [HtMonitoringController::class, 'summary'])->name('summary');
            Route::get('/export',  [HtMonitoringController::class, 'export'])->name('export');
        });

    // =======================================================
    // AO AGENDAS (HARUS ADA)
    // =======================================================
    Route::prefix('ao-agendas')->name('ao-agendas.')->group(function () {

        Route::get('/', [AoAgendaController::class, 'index'])->name('index');

        Route::get('/case/{case}', function (\App\Models\NplCase $case) {
            return redirect()->route('ao-agendas.index', ['case_id' => $case->id]);
        })->name('by-case');

        Route::get('/{agenda}/edit', [AoAgendaController::class, 'edit'])->name('edit');
        Route::put('/{agenda}', [AoAgendaController::class, 'update'])->name('update');

        Route::post('/{agenda}/start', [AoAgendaController::class, 'start'])->name('start');
        Route::post('/{agenda}/complete', [AoAgendaController::class, 'complete'])->name('complete');
        Route::post('/{agenda}/reschedule', [AoAgendaController::class, 'reschedule'])->name('reschedule');
        Route::post('/{agenda}/cancel', [AoAgendaController::class, 'cancel'])->name('cancel');

        Route::get('/my', [AoScheduleDashboardController::class, 'my'])->name('my');
        Route::get('/ao', [AoScheduleDashboardController::class, 'pickAo'])->name('ao.pick');
        Route::get('/ao/{aoCode}', [AoScheduleDashboardController::class, 'forAo'])->name('ao');
    });

    Route::post('/cases/{case}/legal-proposals/{proposal}/plakat-report', 
        [LegalActionProposalController::class, 'reportPlakat']
    )->name('npl.legal-proposals.plakatReport');

    // =======================================================
    // Supervision (AUTH) — 1 pintu + requireRole
    // =======================================================
    Route::get('/supervision', [\App\Http\Controllers\Supervision\SupervisionHomeController::class, 'index'])
        ->name('supervision.home');

    Route::prefix('supervision')->name('supervision.')->group(function () {

        Route::get('/', [\App\Http\Controllers\Supervision\SupervisionHomeController::class, 'index'])
            ->name('home');

        Route::get('/tl', [\App\Http\Controllers\Supervision\TlDashboardController::class, 'index'])
            ->middleware('requireRole:TL,TLL,TLF,TLR')
            ->name('tl');

        Route::get('/kasi', [\App\Http\Controllers\Supervision\KasiDashboardController::class, 'index'])
            ->middleware('requireRole:KSL,KSO,KSA,KSF,KSD,KSR')
            ->name('kasi');

        Route::get('/kabag', [\App\Http\Controllers\ExecutiveDashboardController::class, 'index'])
            ->middleware('requireRole:KABAG,KBL,KBO,KTI,KBF,PE,DIREKSI,KOM,DIR')
            ->name('kabag');
        
        Route::get('/pengurus', fn() => redirect()->route('supervision.kabag'))
            ->middleware('requireRole:DIREKSI,KOM,DIR')
            ->name('pengurus');

        Route::prefix('tl/approvals')->name('tl.approvals.')
            ->middleware('requireRole:TL,TLL,TLF,TLR')
            ->group(function () {
                Route::get('/targets', [TargetApprovalTlController::class, 'index'])->name('targets.index');
                Route::post('/targets/{target}/approve', [TargetApprovalTlController::class, 'approve'])->name('targets.approve');
                Route::post('/targets/{target}/reject', [TargetApprovalTlController::class, 'reject'])->name('targets.reject');
                    // ✅ tambah: NON-LIT approvals
                Route::get('/nonlit', [\App\Http\Controllers\Supervision\NonLitApprovalTlController::class, 'index'])
                    ->name('nonlit.index');

                Route::post('/nonlit/{nonLit}/approve', [\App\Http\Controllers\Supervision\NonLitApprovalTlController::class, 'approve'])
                    ->name('nonlit.approve');

                Route::post('/nonlit/{nonLit}/reject', [\App\Http\Controllers\Supervision\NonLitApprovalTlController::class, 'reject'])
                    ->name('nonlit.reject');
            });

        Route::prefix('kasi/approvals')->name('kasi.approvals.')
            ->middleware('requireRole:KSL,KSO,KSA,KSF,KSD,KSR')
            ->group(function () {
                Route::get('/targets', [TargetApprovalKasiController::class, 'index'])->name('targets.index');
                Route::post('/targets/{target}/approve', [TargetApprovalKasiController::class, 'approve'])->name('targets.approve');
                Route::post('/targets/{target}/reject', [TargetApprovalKasiController::class, 'reject'])->name('targets.reject');
                Route::get('/targets/{target}/approve', [TargetApprovalKasiController::class, 'approveForm'])
                    ->name('targets.approveForm');

                Route::get('/nonlit', [\App\Http\Controllers\Supervision\NonLitApprovalKasiController::class, 'index'])->name('nonlit.index');
                Route::post('/nonlit/{nonLit}/approve', [\App\Http\Controllers\Supervision\NonLitApprovalKasiController::class, 'approve'])->name('nonlit.approve');
                Route::post('/nonlit/{nonLit}/reject', [\App\Http\Controllers\Supervision\NonLitApprovalKasiController::class, 'reject'])->name('nonlit.reject');
                
            });

    });

    // =======================================================
    // Org Assignment (AUTH + GATE)
    // =======================================================
    Route::prefix('supervision/org')->name('supervision.org.')
        ->middleware('can:manage-org-assignments')
        ->group(function () {
            Route::get('/assignments', [OrgAssignmentController::class, 'index'])->name('assignments.index');
            Route::get('/assignments/create', [OrgAssignmentController::class, 'create'])->name('assignments.create');
            Route::post('/assignments', [OrgAssignmentController::class, 'store'])->name('assignments.store');
            Route::get('/assignments/{assignment}/edit', [OrgAssignmentController::class, 'edit'])->name('assignments.edit');
            Route::put('/assignments/{assignment}', [OrgAssignmentController::class, 'update'])->name('assignments.update');
            Route::delete('/assignments/{assignment}', [OrgAssignmentController::class, 'destroy'])->name('assignments.destroy');

            Route::post('/assignments/{assignment}/end', [OrgAssignmentController::class, 'end'])->name('assignments.end');
            Route::post('/assignments/{assignment}/switch', [OrgAssignmentController::class, 'switchLeader'])->name('assignments.switch');
        });

    Route::get('/lending/performance', [LendingPerformanceController::class, 'index'])
        ->name('lending.performance.index');

    Route::get('/lending/performance/ao/{ao_code}', [LendingPerformanceController::class, 'showAo'])
        ->name('lending.performance.ao');
    
    Route::get('/lending/trend', [LendingTrendController::class, 'index'])
        ->name('lending.trend.index');

    Route::get('/shm-check', [ShmCheckRequestController::class, 'index'])->name('shm.index');
    Route::get('/shm-check/create', [ShmCheckRequestController::class, 'create'])->name('shm.create');
    Route::post('/shm-check', [ShmCheckRequestController::class, 'store'])->name('shm.store');

    Route::get('/shm-check/{req}', [ShmCheckRequestController::class, 'show'])->name('shm.show');

    // KSA/KBO/SAD actions (gate: sadAction)
    Route::post('/shm-check/{req}/sent-to-notary', [ShmCheckRequestController::class, 'markSentToNotary'])
        ->name('shm.sentToNotary');

    Route::post('/shm-check/{req}/upload-sp-sk', [ShmCheckRequestController::class, 'uploadSpSk'])
        ->name('shm.uploadSpSk');

    Route::post('/shm-check/{req}/sent-to-bpn', [ShmCheckRequestController::class, 'markSentToBpn'])
        ->name('shm.sentToBpn');

    Route::post('/shm-check/{req}/upload-result', [ShmCheckRequestController::class, 'uploadResult'])
        ->name('shm.uploadResult');

    // AO actions
    Route::post('/shm-check/{req}/upload-signed', [ShmCheckRequestController::class, 'uploadSigned'])
        ->name('shm.uploadSigned');

    Route::post('/shm-check/{req}/handed-to-sad', [ShmCheckRequestController::class, 'markHandedToSad'])
        ->name('shm.handedToSad');

    // download file
    Route::get('/shm-check/file/{file}', [ShmCheckRequestController::class, 'downloadFile'])
        ->name('shm.file.download');

        // ===============================
        // KPI
        // ===============================
    Route::get('/kpi/marketing/targets', [MarketingTargetController::class, 'index'])
        ->name('kpi.marketing.targets.index');

    Route::get('/kpi/marketing/targets/create', [MarketingTargetController::class, 'create'])
        ->name('kpi.marketing.targets.create');

    Route::post('/kpi/marketing/targets', [MarketingTargetController::class, 'store'])
        ->name('kpi.marketing.targets.store');

    Route::get('/kpi/marketing/targets/{target}/edit', [MarketingTargetController::class, 'edit'])
        ->name('kpi.marketing.targets.edit');

    Route::put('/kpi/marketing/targets/{target}', [MarketingTargetController::class, 'update'])
        ->name('kpi.marketing.targets.update');

    Route::post('/kpi/marketing/targets/{target}/submit', [MarketingTargetController::class, 'submit'])
        ->name('kpi.marketing.targets.submit');

    Route::get('/kpi/marketing/achievements', [\App\Http\Controllers\Kpi\MarketingAchievementController::class, 'index'])
        ->name('kpi.marketing.achievements.index');

    Route::get('/kpi/marketing/ranking', [MarketingKpiRankingController::class, 'index'])
        ->name('kpi.marketing.ranking.index');

        // routes/web.php
    Route::post('/kpi/marketing/ranking/recalc', 
        [\App\Http\Controllers\Kpi\MarketingKpiRankingController::class, 'recalcAll']
    )->name('kpi.marketing.ranking.recalc');


        // ===============================
        // Approval KPI
        // ===============================

    // inbox TL/Kasi
    Route::get('/kpi/marketing/approvals', [MarketingTargetApprovalController::class, 'index'])
        ->name('kpi.marketing.approvals.index');

    // form review (TL/Kasi boleh adjust)
    Route::get('/kpi/marketing/approvals/{target}', [MarketingTargetApprovalController::class, 'show'])
        ->name('kpi.marketing.approvals.show');

    // simpan perubahan angka final (sebelum approve)
    Route::put('/kpi/marketing/approvals/{target}', [MarketingTargetApprovalController::class, 'update'])
        ->name('kpi.marketing.approvals.update');

    // approve / reject
    Route::post('/kpi/marketing/approvals/{target}/approve', [MarketingTargetApprovalController::class, 'approve'])
        ->name('kpi.marketing.approvals.approve');

    Route::post('/kpi/marketing/approvals/{target}/reject', [MarketingTargetApprovalController::class, 'reject'])
        ->name('kpi.marketing.approvals.reject');

    Route::get('/kpi/marketing/targets/{target}/achievement', [MarketingKpiAchievementController::class, 'show'])
        ->name('kpi.marketing.targets.achievement');

    
    // routes/web.php
    Route::get('/kpi/marketing/ao/{user}', 
        [KpiMarketingAoController::class, 'show']
    )->name('kpi.marketing.ao.show');


});    

// =========================
// LEGAL PROPOSALS (USULAN)
// =========================
Route::middleware(['auth','role:AO,BE,FE,SO,RO,SA'])
    ->prefix('npl-cases/{case}')
    ->name('npl.')
    ->group(function () {
        Route::post('/legal-proposals', [LegalActionProposalController::class, 'store'])
            ->name('legal-proposals.store');
    });

// =========================
// PROPOSAL APPROVAL & LIST (TL/Kasi/BE monitor)
// =========================
Route::middleware(['auth'])
    ->prefix('legal/proposals')
    ->name('legal.proposals.')
    ->group(function () {

        Route::get('/', [LegalActionProposalController::class, 'index'])->name('index');

        Route::post('{proposal}/approve-tl',   [LegalActionProposalApprovalController::class, 'approveTl'])->name('approve-tl');
        Route::post('{proposal}/approve-kasi', [LegalActionProposalApprovalController::class, 'approveKasi'])->name('approve-kasi');

        // ✅ GANTI JADI POST + role BE
        Route::post('{proposal}/execute', [LegalActionProposalController::class, 'execute'])
            ->middleware('role:BE')
            ->name('execute');
    });

    Route::middleware(['auth', 'role:BE'])
    ->prefix('npl-cases/{case}')
    ->name('npl.')
    ->group(function () {
        Route::get('/legal-actions/create', [NplLegalActionController::class, 'create'])
            ->name('legal-actions.create');

        Route::post('/legal-actions', [NplLegalActionController::class, 'store'])
            ->name('legal-actions.store');
        
    });


// routes/web.php

Route::middleware(['auth','role:KOM,DIR,DIREKSI,KBL,KSL,KBO,PE']) // sesuaikan role executive kamu
    ->prefix('executive')
    ->as('executive.')
    ->group(function () {
        Route::get('/targets', [\App\Http\Controllers\Executive\ExecutiveTargetController::class, 'index'])
            ->name('targets.index');

        Route::get('/targets/{case}', [\App\Http\Controllers\Executive\ExecutiveTargetController::class, 'show'])
            ->name('targets.show');
    });


Route::middleware(['auth'])->prefix('kti')->name('kti.')->group(function () {
    Route::get('/targets', [KtiResolutionTargetController::class, 'index'])->name('targets.index');
    Route::get('/targets/{case}', [KtiResolutionTargetController::class, 'show'])->name('targets.show');
    Route::post('/targets/{case}', [KtiResolutionTargetController::class, 'store'])->name('targets.store');
});


Route::middleware(['auth'])
    ->prefix('legal-actions')
    ->name('legal-actions.')
    ->group(function () {

        // ===== HT EXECUTION UI (TAB) =====
        Route::get('{action}/ht', [HtExecutionController::class, 'show'])
            ->name('ht.show');

        // ===== EXECUTION FORM (Data objek & dasar HT) =====
        Route::post('{action}/ht', [HtExecutionController::class, 'upsert'])
            ->name('ht.upsert');

        // ===== DOCUMENTS =====
        Route::post('{action}/ht/documents', [HtExecutionController::class, 'storeDocument'])
            ->name('ht.documents.store');

        Route::post('{action}/ht/documents/{doc}', [HtExecutionController::class, 'updateDocumentMeta'])
            ->name('ht.documents.update');

        Route::post('{action}/ht/documents/{doc}/verify', [HtExecutionController::class, 'verifyDocument'])
            ->name('ht.documents.verify');

        Route::delete('{action}/ht/documents/{doc}', [HtExecutionController::class, 'deleteDocument'])
            ->name('ht.documents.delete');

        Route::get('{action}/ht/documents/{doc}/view', [HtExecutionController::class, 'viewDocument'])
            ->name('ht.documents.view');

        // ===== EVENTS (TIMELINE) =====
        Route::post('{action}/ht/events', [HtExecutionController::class, 'storeEvent'])
            ->name('ht.events.store');

        Route::delete('{action}/ht/events/{event}', [HtExecutionController::class, 'deleteEvent'])
            ->name('ht.events.delete');

        // ===== AUCTIONS =====
        Route::post('{action}/ht/auctions', [HtExecutionController::class, 'storeAuction'])
            ->name('ht.auctions.store');

        Route::post('{action}/ht/auctions/{auction}', [HtExecutionController::class, 'updateAuction'])
            ->name('ht.auctions.update');

        Route::delete('{action}/ht/auctions/{auction}', [HtExecutionController::class, 'deleteAuction'])
            ->name('ht.auctions.delete');

        Route::get('{action}/ht/auctions/{auction}/risalah', [HtExecutionController::class, 'risalah'])
            ->name('ht.auctions.risalah');

        // ===== STATUS HT =====
        // Route::post('{action}/ht/status', [HtExecutionController::class, 'updateHtStatus'])
        //     ->name('ht.update-status');

        Route::post('{action}/ht/close', [HtExecutionController::class, 'closeHt'])
            ->name('ht.close');

    });
