<?php

use App\Enums\StaffRole;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AudioClipController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\ClinicalRecordController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\LabTestCatalogController;
use App\Http\Controllers\Api\MedicationCatalogController;
use App\Http\Controllers\Api\InsuranceCardController;
use App\Http\Controllers\Api\LabResultsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PrescribedMedicationController;
use App\Http\Controllers\Api\PriorityLevelController;
use App\Http\Controllers\Api\QueueEventController;
use App\Http\Controllers\Api\QueueTicketController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RequestedTestController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceFlowController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\WaitingDisplayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 0 — connectivity check
|--------------------------------------------------------------------------
| Hit from the frontend on first load to prove backend<->frontend wiring
| works before any real feature is built. Safe to remove once Phase 9's
| real dashboards exist, but harmless to leave.
*/
Route::get('/ping', fn () => response()->json([
    'message' => 'Hospital Queue API is alive.',
    'timestamp' => now()->toIso8601String(),
]));

/* Phase 1 — Auth*/
Route::post('/auth/login', [AuthController::class, 'login']);

/*
| Phase 7 — public patient tracking page. No auth (a patient has no
| account) — reached via the opaque tracking_token, never the numeric
| visit id. Registered outside the auth:sanctum group deliberately.
|
| Both endpoints below carry no authentication at all, so unlike every
| other route in this file, nothing stops a script from hammering them
| directly (a login-protected route at least costs an attacker valid
| credentials first). throttle:30,1 caps each to 30 requests/minute per
| IP — comfortably above a phone auto-refreshing or reconnecting after a
| signal drop, but low enough to blunt scraping/abuse.
*/
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/track/{trackingToken}', [TrackingController::class, 'show']);

    // Waiting-area kiosk screen — also public, same reasoning as /track above.
    Route::get('/waiting-display/{department}', [WaitingDisplayController::class, 'show']);

    // Phase 8 — the waiting-display kiosk (also public/no-login) calls this
    // the moment its own WebSocket subscription sees a TicketCalled event,
    // to fetch the announcement clip sequence for that ticket.
    Route::get('/queue-tickets/{queueTicket}/announcement', [AnnouncementController::class, 'show']);

    // Self check-in — reached via a STATIC entrance QR code (same URL for
    // everyone, unlike the per-visit tracking QR above). A walk-in patient
    // has no account, so this carries no auth either.
    Route::post('/check-ins', [CheckInController::class, 'store']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::patch('/auth/active-role', [AuthController::class, 'setActiveRole']);

    // One role-guarded ping per dashboard, so Phase 1's Definition of Done
    // ("a Pharmacy Staff account cannot access Administrator-only routes,
    // tested directly via API") is verifiable with a simple curl call.
    foreach (StaffRole::cases() as $role) {
        Route::get("/{$role->dashboardSlug()}/ping", fn () => response()->json([
            'message' => "{$role->value} route reachable.",
        ]))->middleware("role:{$role->value}");
    }

    /* | Phase 2 — Patient Registration & Classification  */

    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::get('/priority-levels', [PriorityLevelController::class, 'index']);
    Route::get('/doctors', [DoctorController::class, 'index']);

    Route::get('/patients/search', [PatientController::class, 'index']);
    Route::get('/patients', [PatientController::class, 'index']);
    Route::get('/patients/{patient}', [PatientController::class, 'show']);
    Route::post('/patients', [PatientController::class, 'store']);
    Route::put('/patients/{patient}', [PatientController::class, 'update']);

    Route::get('/visits/stats/today', [VisitController::class, 'statsToday']);
    Route::get('/visits', [VisitController::class, 'index']);
    Route::post('/visits', [VisitController::class, 'store']);
    Route::patch('/visits/{visit}/complete', [VisitController::class, 'complete']);

    // Registration Staff confirms/corrects the placeholder patient_type
    // and payment_method a self check-in was auto-created with, once that
    // Registration ticket is called — see
    // VisitController::updateRegistrationDetails.
    Route::patch('/visits/{visit}/registration-details', [VisitController::class, 'updateRegistrationDetails']);

    // The traveling clinical record — one per visit, section-level role
    // checks enforced inside ClinicalRecordController itself (varies per
    // field, so a single static `role:` middleware can't express it).
    Route::get('/visits/{visit}/clinical-record', [ClinicalRecordController::class, 'show']);
    Route::patch('/visits/{visit}/clinical-record', [ClinicalRecordController::class, 'update']);

    // The categorized Laboratory request checklist — keyed by service, not
    // visit, since it belongs to a specific Consultation service (see
    // Service::labRequestOriginService).
    Route::get('/services/{service}/requested-tests', [RequestedTestController::class, 'index']);
    Route::put('/services/{service}/requested-tests', [RequestedTestController::class, 'update']);
    Route::put('/services/{service}/requested-tests/results', [RequestedTestController::class, 'updateResults']);

    // The Doctor's medication prescription checklist — keyed by service,
    // same pattern as the Laboratory request checklist above (see
    // Service::pharmacyRequestOriginService).
    Route::get('/services/{service}/prescribed-medications', [PrescribedMedicationController::class, 'index']);
    Route::put('/services/{service}/prescribed-medications', [PrescribedMedicationController::class, 'update']);

    Route::get('/referrals', [ReferralController::class, 'index']);
    Route::get('/referrals/{referral}', [ReferralController::class, 'show']);

    Route::get('/lab-results', [LabResultsController::class, 'index']);

    // Emergency confirmation is clinical — only a Doctor account may call
    // these, enforced here at the middleware level, not just hidden in the UI.
    Route::middleware('role:Doctor')->group(function () {
        Route::patch('/visits/{visit}/confirm-emergency', [VisitController::class, 'confirmEmergency']);
        Route::patch('/visits/{visit}/downgrade-emergency', [VisitController::class, 'downgradeEmergency']);
    });

    /* |  — Queue Ticket Status Lifecycle */

    Route::get('/queue-tickets', [QueueTicketController::class, 'index']);
    Route::patch('/queue-tickets/{queueTicket}/call', [QueueTicketController::class, 'call']);
    Route::patch('/queue-tickets/{queueTicket}/start-service', [QueueTicketController::class, 'startService']);
    Route::patch('/queue-tickets/{queueTicket}/complete', [QueueTicketController::class, 'complete']);
    Route::patch('/queue-tickets/{queueTicket}/no-show', [QueueTicketController::class, 'noShow']);
    Route::patch('/queue-tickets/{queueTicket}/cancel', [QueueTicketController::class, 'cancel']);
    Route::patch('/queue-tickets/{queueTicket}/hold', [QueueTicketController::class, 'hold']);
    Route::patch('/queue-tickets/{queueTicket}/transfer', [QueueTicketController::class, 'transfer']);

    Route::get('/queue-events/recent', [QueueEventController::class, 'recent']);

    /*
    | Phase 4 — Dynamic Queue Priority Engine
    | Role-per-department is checked inside DepartmentController@callNext
    | (it varies by which department is being called, so a single static
    | `role:` middleware can't express it).
    */
    Route::post('/departments/{department}/call-next', [DepartmentController::class, 'callNext']);
    Route::get('/departments/{department}/stats', [DepartmentController::class, 'stats']);

    /*
    | Phase 5 — Multi-Department Patient Flow
    | Opens the next department's service/ticket for a visit, closing
    | whichever one is currently active first — see ServiceFlowController.
    | The Critical Rule (no double IN_SERVICE) is enforced in
    | QueueTicketController::applyTransition, and visit closure (only once
    | every service is Completed/Cancelled) in VisitController::complete.
    */
    Route::post('/visits/{visit}/services', [ServiceFlowController::class, 'store']);
    Route::patch('/services/{service}/notes', [ServiceController::class, 'updateNotes']);

    // OnHold resolution: ServiceFlowController::store auto-resolves the one
    // safe case (patient routed back into the same department). Everything
    // else — a hold nobody ever returned for — needs an explicit staff
    // decision (resolveHold) and stays visible until then (openHolds).
    Route::get('/services/open-holds', [ServiceController::class, 'openHolds']);
    Route::patch('/services/{service}/resolve-hold', [ServiceController::class, 'resolveHold']);

    // Doctor-selection's known limitation (see PriorityEngine::callNext's
    // doctor-scoping docblock): same visibility+resolution pattern as
    // OnHold above, for a doctor-locked ticket whose doctor never called it.
    Route::get('/services/doctor-locked', [ServiceController::class, 'openDoctorLocks']);
    Route::patch('/services/{service}/clear-doctor', [ServiceController::class, 'clearDoctor']);

    /*
    | Phase 6 — Billing, Payment & Insurance Management
    | Payment verification (Cashier/Billing Staff only) gates IN_SERVICE at
    | Consultation, Laboratory, and Pharmacy — see PaymentGate and
    | TicketTransitionService. Insurance card custody belongs to
    | Registration Staff only, never Cashier/Billing — see
    | InsuranceCardController.
    */
    Route::post('/services/{service}/payments/verify', [PaymentController::class, 'verify']);
    Route::get('/payments/pending', [PaymentController::class, 'pending']);
    Route::get('/payments/stats', [PaymentController::class, 'stats']);

    // Administrator-managed price lists Billing selects itemized charges
    // from (see PaymentController::verify's items payload) instead of
    // typing an arbitrary amount.
    Route::get('/lab-test-catalog', [LabTestCatalogController::class, 'index']);
    Route::post('/lab-test-catalog', [LabTestCatalogController::class, 'store']);
    Route::put('/lab-test-catalog/{labTestCatalog}', [LabTestCatalogController::class, 'update']);

    Route::get('/medication-catalog', [MedicationCatalogController::class, 'index']);
    Route::post('/medication-catalog', [MedicationCatalogController::class, 'store']);
    Route::put('/medication-catalog/{medicationCatalog}', [MedicationCatalogController::class, 'update']);

    Route::get('/insurance-cards', [InsuranceCardController::class, 'index']);
    Route::get('/insurance-cards/pending-receipt', [InsuranceCardController::class, 'pendingReceipt']);
    Route::post('/visits/{visit}/insurance-card', [InsuranceCardController::class, 'receive']);
    Route::patch('/visits/{visit}/insurance-card/release', [InsuranceCardController::class, 'release']);

    /*
    | Phase 8 — Notifications, Long-Waiting Alerts, Audio Clip Library.
    | Patient-facing notifications are never listed here — those are
    | visit-scoped and only reachable via the public tracking endpoint.
    | This is for staff/admin-facing types only (currently just
    | LONG_WAIT_ALERT — see NotificationController).
    */
    Route::get('/notifications', [NotificationController::class, 'index']);
    // Staff alert bell — overdue-wait alerts scoped to the caller's own departments.
    Route::get('/notifications/staff-alerts', [NotificationController::class, 'staffAlerts']);

    Route::get('/audio-clips', [AudioClipController::class, 'index']);
    Route::post('/audio-clips', [AudioClipController::class, 'store']);
    Route::get('/audio-clips/health-check', [AudioClipController::class, 'healthCheck']);

    /*
    | Phase 9 — Administrator-only reporting/analytics. Read-only
    | aggregation, no writes/side effects. /system-summary powers the
    | Administrator Dashboard cards; the other 5 power the separate
    | Reports & Analytics page. /reports/{type}/pdf renders any of the 5
    | as a PDF, sharing the exact same data-building code as its JSON
    | counterpart (see ReportController) so the two can never drift.
    */
    Route::get('/reports/system-summary', [ReportController::class, 'systemSummary']);
    Route::get('/reports/daily-patients', [ReportController::class, 'dailyPatients']);
    Route::get('/reports/waiting-time', [ReportController::class, 'waitingTime']);
    Route::get('/reports/department', [ReportController::class, 'department']);
    Route::get('/reports/queue-performance', [ReportController::class, 'queuePerformance']);
    Route::get('/reports/emergency', [ReportController::class, 'emergency']);
    Route::get('/reports/{type}/pdf', [ReportController::class, 'pdf']);

    /*
    | Multi-role fix — Administrator's "Users & Roles" page: grant/revoke
    | which roles a staff account holds. This is what populates each
    | user's own role list for their sidebar dropdown.
    */
    Route::get('/users', [UserController::class, 'index']);
    Route::patch('/users/{user}/roles', [UserController::class, 'updateRoles']);
});