<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\DashboardAdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardLaboratoryManagerController;
use App\Http\Controllers\DashboardStudentController;
use App\Http\Controllers\DashboardTeacherController;
use App\Http\Controllers\GuardianAbsenceConfirmationController;
use App\Http\Controllers\GuardianDelayConfirmationController;
use App\Http\Controllers\GuardianLeaveConfirmationController;
use App\Http\Controllers\LaboratoryManagerLeaveController;
use App\Http\Controllers\LeaveWorkflowController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RulesController;
use App\Http\Controllers\StudentDelayController;
use App\Http\Controllers\StudentLeaveController;
use App\Http\Controllers\StudentMonthlyReportController;
use App\Http\Controllers\StudentProfileApiController;
use App\Http\Controllers\TeacherAbsenceController;
use App\Http\Controllers\TeacherDelayController;
use App\Http\Controllers\TeacherMonthlyReportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/regole', [RulesController::class, 'index'])
        ->name('rules.index');
    Route::get('/regole/pdf', [RulesController::class, 'downloadPdf'])
        ->name('rules.download');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/avatar/{user}', [ProfileController::class, 'showAvatar'])
        ->name('profile.avatar.show');
    Route::patch('/profile/notifiche', [ProfileController::class, 'updateNotifications'])
        ->name('profile.notifications.update');
    Route::patch('/profile/stato-allievi', [ProfileController::class, 'updateStudentStatusSettings'])
        ->name('profile.student-status.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/notifiche/tutte-lette', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');
    Route::post('/notifiche/{notification}/letta', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');

    Route::get('/studente/assenze/nuova', [AbsenceController::class, 'create'])
        ->name('student.absences.create');
    Route::post('/studente/assenze', [AbsenceController::class, 'NewRequestAbsence'])
        ->name('student.absences.store');
    Route::post('/studente/assenze/{absence}/certificato', [AbsenceController::class, 'uploadMedicalCertificate'])
        ->name('student.absences.certificate.upload');
    Route::post('/studente/assenze/{absence}/ore-effettive', [AbsenceController::class, 'updateDerivedLeaveEffectiveHours'])
        ->name('student.absences.effective-hours.update');
    Route::get('/studente/assenze/{absence}/bozza-congedo', [AbsenceController::class, 'editDerivedLeaveDraft'])
        ->name('student.absences.derived-draft.edit');
    Route::post('/studente/assenze/{absence}/bozza-congedo/invia', [AbsenceController::class, 'submitDerivedLeaveDraft'])
        ->name('student.absences.derived-draft.submit');
    Route::get('/studente/ritardi/nuovo', [StudentDelayController::class, 'create'])
        ->name('student.delays.create');
    Route::post('/studente/ritardi', [StudentDelayController::class, 'store'])
        ->name('student.delays.store');
    Route::get('/studente/congedi/nuovo', [StudentLeaveController::class, 'create'])
        ->name('student.leaves.create');
    Route::post('/studente/congedi', [StudentLeaveController::class, 'store'])
        ->name('student.leaves.store');
    Route::post('/studente/congedi/{leave}/documentazione', [StudentLeaveController::class, 'uploadDocumentation'])
        ->name('student.leaves.documentation.upload');

    Route::get('/studente/storico', [DashboardStudentController::class, 'History'])
        ->name('student.history');

    Route::get('/studente/documenti', [DashboardStudentController::class, 'DocumentManagemnt'])
        ->name('student.documents');
    Route::get('/studente/report-mensili', [StudentMonthlyReportController::class, 'index'])
        ->name('student.monthly-reports');
    Route::get('/studente/report-mensili/{monthlyReport}/download', [StudentMonthlyReportController::class, 'downloadOriginal'])
        ->name('student.monthly-reports.download');
    Route::get('/studente/report-mensili/{monthlyReport}/firmato/download', [StudentMonthlyReportController::class, 'downloadSigned'])
        ->name('student.monthly-reports.download-signed');
    Route::post('/studente/report-mensili/{monthlyReport}/firmato', [StudentMonthlyReportController::class, 'uploadSigned'])
        ->name('student.monthly-reports.upload-signed');

    Route::get('/docente/assenze/{absence}', [DashboardTeacherController::class, 'showAbsence'])
        ->name('teacher.absences.show');
    Route::post('/docente/assenze/{absence}/aggiorna', [TeacherAbsenceController::class, 'update'])
        ->name('teacher.absences.update');
    Route::post('/docente/assenze/{absence}/approva', [TeacherAbsenceController::class, 'approve'])
        ->name('teacher.absences.approve');
    Route::post('/docente/assenze/{absence}/approva-senza-firma', [TeacherAbsenceController::class, 'approveWithoutGuardian'])
        ->name('teacher.absences.approve-without-guardian');
    Route::post('/docente/assenze/{absence}/rifiuta', [TeacherAbsenceController::class, 'reject'])
        ->name('teacher.absences.reject');
    Route::delete('/docente/assenze/{absence}/elimina', [TeacherAbsenceController::class, 'destroy'])
        ->name('teacher.absences.destroy');
    Route::post('/docente/assenze/{absence}/proroga', [TeacherAbsenceController::class, 'extendDeadline'])
        ->name('teacher.absences.extend-deadline');
    Route::post('/docente/assenze/{absence}/reinvia-firma', [TeacherAbsenceController::class, 'resendGuardianConfirmationEmail'])
        ->name('teacher.absences.resend-guardian-email');
    Route::get('/docente/assenze/{absence}/firma-tutore', [TeacherAbsenceController::class, 'showGuardianSignature'])
        ->name('teacher.absences.guardian-signature.view');
    Route::post('/docente/assenze/{absence}/certificato/accetta', [TeacherAbsenceController::class, 'acceptMedicalCertificate'])
        ->name('teacher.absences.accept-certificate');
    Route::post('/docente/assenze/{absence}/certificato/rifiuta', [TeacherAbsenceController::class, 'rejectMedicalCertificate'])
        ->name('teacher.absences.reject-certificate');
    Route::get('/docente/assenze/{absence}/certificato', [TeacherAbsenceController::class, 'showMedicalCertificate'])
        ->name('teacher.absences.certificate.view');
    Route::get('/docente/assenze/{absence}/certificato/scarica', [TeacherAbsenceController::class, 'downloadMedicalCertificate'])
        ->name('teacher.absences.certificate.download');
    Route::get('/docente/ritardi/{delay}', [DashboardTeacherController::class, 'showDelay'])
        ->name('teacher.delays.show');
    Route::post('/docente/ritardi/{delay}/aggiorna', [TeacherDelayController::class, 'update'])
        ->name('teacher.delays.update');
    Route::post('/docente/ritardi/{delay}/approva', [TeacherDelayController::class, 'approve'])
        ->name('teacher.delays.approve');
    Route::post('/docente/ritardi/{delay}/approva-senza-firma', [TeacherDelayController::class, 'approveWithoutGuardian'])
        ->name('teacher.delays.approve-without-guardian');
    Route::post('/docente/ritardi/{delay}/rifiuta', [TeacherDelayController::class, 'reject'])
        ->name('teacher.delays.reject');
    Route::post('/docente/ritardi/{delay}/proroga', [TeacherDelayController::class, 'extendDeadline'])
        ->name('teacher.delays.extend-deadline');
    Route::post('/docente/ritardi/{delay}/reinvia-firma', [TeacherDelayController::class, 'resendGuardianConfirmationEmail'])
        ->name('teacher.delays.resend-guardian-email');
    Route::delete('/docente/ritardi/{delay}/elimina', [TeacherDelayController::class, 'destroy'])
        ->name('teacher.delays.destroy');
    Route::get('/docente/report-mensili', [TeacherMonthlyReportController::class, 'index'])
        ->name('teacher.monthly-reports');
    Route::get('/docente/report-mensili/{monthlyReport}', [TeacherMonthlyReportController::class, 'show'])
        ->name('teacher.monthly-reports.show');
    Route::get('/docente/report-mensili/{monthlyReport}/download', [TeacherMonthlyReportController::class, 'downloadOriginal'])
        ->name('teacher.monthly-reports.download');
    Route::get('/docente/report-mensili/{monthlyReport}/firmato/download', [TeacherMonthlyReportController::class, 'downloadSigned'])
        ->name('teacher.monthly-reports.download-signed');
    Route::post('/docente/report-mensili/{monthlyReport}/reinvia-email', [TeacherMonthlyReportController::class, 'resendEmail'])
        ->name('teacher.monthly-reports.resend-email');
    Route::post('/docente/report-mensili/{monthlyReport}/approva', [TeacherMonthlyReportController::class, 'approve'])
        ->name('teacher.monthly-reports.approve');

    Route::get('/docente/classi', [DashboardTeacherController::class, 'classes'])
        ->name('teacher.classes');

    Route::get('/docente/studenti', [DashboardTeacherController::class, 'students'])->name('teacher.students');
    Route::get('/studenti/{student}/profilo', [StudentProfileApiController::class, 'page'])
        ->name('students.profile.show');
    Route::get('/studenti/{student}/profilo/export', [StudentProfileApiController::class, 'export'])
        ->name('students.profile.export');
    Route::get('/api/studenti/{student}/profilo', [StudentProfileApiController::class, 'show'])
        ->name('students.profile.api');

    Route::get('/docente/storico', [DashboardTeacherController::class, 'history'])
        ->name('teacher.history');

    Route::get('/laboratorio/congedi', [LeaveWorkflowController::class, 'index'])
        ->name('lab.leaves');
    Route::get('/laboratorio/congedi/nuovo', [LaboratoryManagerLeaveController::class, 'create'])
        ->name('lab.leaves.create');
    Route::post('/laboratorio/congedi/nuovo', [LaboratoryManagerLeaveController::class, 'store'])
        ->name('lab.leaves.store');
    Route::get('/congedi/{leave}', [LeaveWorkflowController::class, 'show'])
        ->name('leaves.show');
    Route::post('/congedi/{leave}/pre-approva', [LeaveWorkflowController::class, 'preApprove'])
        ->name('leaves.pre-approve');
    Route::post('/congedi/{leave}/approva', [LeaveWorkflowController::class, 'approve'])
        ->name('leaves.approve');
    Route::post('/congedi/{leave}/rifiuta', [LeaveWorkflowController::class, 'reject'])
        ->name('leaves.reject');
    Route::delete('/congedi/{leave}/elimina', [LeaveWorkflowController::class, 'destroy'])
        ->name('leaves.destroy');
    Route::post('/congedi/{leave}/inoltra-direzione', [LeaveWorkflowController::class, 'forwardToManagement'])
        ->name('leaves.forward-to-management');
    Route::post('/congedi/{leave}/richiedi-documentazione', [LeaveWorkflowController::class, 'requestDocumentation'])
        ->name('leaves.request-documentation');
    Route::post('/congedi/{leave}/rifiuta-documentazione', [LeaveWorkflowController::class, 'rejectDocumentation'])
        ->name('leaves.reject-documentation');
    Route::post('/congedi/{leave}/aggiorna', [LeaveWorkflowController::class, 'update'])
        ->name('leaves.update');
    Route::post('/congedi/{leave}/reinvia-firma', [LeaveWorkflowController::class, 'resendGuardianConfirmationEmail'])
        ->name('leaves.resend-guardian-email');
    Route::get('/congedi/{leave}/firma-tutore', [LeaveWorkflowController::class, 'showGuardianSignature'])
        ->name('leaves.guardian-signature.view');
    Route::get('/congedi/{leave}/documentazione', [LeaveWorkflowController::class, 'showDocumentation'])
        ->name('leaves.documentation.view');
    Route::get('/congedi/{leave}/pdf-inoltro-direzione', [LeaveWorkflowController::class, 'downloadForwardingPdf'])
        ->name('leaves.forwarding-pdf.download');

    Route::get('/laboratorio/allievi', [DashboardLaboratoryManagerController::class, 'students'])
        ->name('lab.students');

    Route::get('/laboratorio/storico', [DashboardLaboratoryManagerController::class, 'history'])
        ->name('lab.history');

    Route::get('/admin/utenti', [DashboardAdminController::class, 'UserManagement'])->name('admin.users');
    Route::patch('/admin/utenti/{user}', [DashboardAdminController::class, 'updateManagedUser'])
        ->name('admin.users.update');
    Route::post('/admin/utenti/{user}/reset-password', [DashboardAdminController::class, 'sendManagedUserPasswordReset'])
        ->name('admin.users.reset-password');
    Route::delete('/admin/utenti/{user}', [DashboardAdminController::class, 'destroyManagedUser'])
        ->name('admin.users.destroy');
    Route::patch('/admin/utenti/{user}/stato', [DashboardAdminController::class, 'toggleManagedUserActive'])
        ->name('admin.users.toggle-active');
    Route::post('/admin/allievi/{student}/tutore', [DashboardAdminController::class, 'assignGuardianToStudent'])
        ->name('admin.students.guardian.assign');
    Route::delete('/admin/allievi/{student}/tutori/{guardian}', [DashboardAdminController::class, 'removeGuardianFromStudent'])
        ->name('admin.students.guardian.remove');

    Route::get('/admin/classi', [DashboardAdminController::class, 'ClassesManagement'])->name('admin.classes');
    Route::post('/admin/classi', [DashboardAdminController::class, 'storeClass'])
        ->name('admin.classes.store');
    Route::patch('/admin/classi/{class}', [DashboardAdminController::class, 'updateClass'])
        ->name('admin.classes.update');
    Route::delete('/admin/classi/{class}', [DashboardAdminController::class, 'destroyClass'])
        ->name('admin.classes.destroy');

    Route::get('/admin/configurazione', [AdminSettingsController::class, 'edit'])->name('admin.settings');
    Route::post('/admin/configurazione', [AdminSettingsController::class, 'update'])
        ->name('admin.settings.update');
    Route::post('/admin/configurazione/vacanze/import', [AdminSettingsController::class, 'importHolidaysFromPdf'])
        ->name('admin.settings.holidays.import');
    Route::post('/admin/configurazione/vacanze', [AdminSettingsController::class, 'storeHoliday'])
        ->name('admin.settings.holidays.store');
    Route::patch('/admin/configurazione/vacanze/{holiday}', [AdminSettingsController::class, 'updateHoliday'])
        ->name('admin.settings.holidays.update');
    Route::delete('/admin/configurazione/vacanze/{holiday}', [AdminSettingsController::class, 'destroyHoliday'])
        ->name('admin.settings.holidays.destroy');

    Route::get('/admin/allievi/aggiunta', [DashboardAdminController::class, 'AddUser'])->name('admin.user.create');
    Route::post('/admin/allievi/aggiunta/manuale', [DashboardAdminController::class, 'StoreManualUser'])
        ->name('admin.user.manual.store');
    Route::post('/admin/allievi/aggiunta', [DashboardAdminController::class, 'StoreUserFromCsv'])->name('admin.user.FromCSVStore');

    Route::get('/admin/log-errori', [DashboardAdminController::class, 'ErrorManagement'])
        ->name('admin.error-logs');
    Route::get('/admin/log-errori/export/opzioni', [DashboardAdminController::class, 'showErrorLogsExportOptions'])
        ->name('admin.error-logs.export.options');
    Route::get('/admin/log-errori/export', [DashboardAdminController::class, 'exportErrorLogs'])
        ->name('admin.error-logs.export');

    Route::get('/admin/storico-interazioni', [DashboardAdminController::class, 'InteractionManagement'])->name('admin.interactions');
    Route::get('/admin/storico-interazioni/export/opzioni', [DashboardAdminController::class, 'showInteractionLogsExportOptions'])
        ->name('admin.interactions.export.options');
    Route::get('/admin/storico-interazioni/export', [DashboardAdminController::class, 'exportInteractionLogs'])
        ->name('admin.interactions.export');
});

Route::get('/tutore/assenze/firma/{token}', [GuardianAbsenceConfirmationController::class, 'show'])
    ->name('guardian.absences.signature.show');
Route::post('/tutore/assenze/firma/{token}', [GuardianAbsenceConfirmationController::class, 'store'])
    ->name('guardian.absences.signature.store');
Route::get('/tutore/congedi/firma/{token}', [GuardianLeaveConfirmationController::class, 'show'])
    ->name('guardian.leaves.signature.show');
Route::post('/tutore/congedi/firma/{token}', [GuardianLeaveConfirmationController::class, 'store'])
    ->name('guardian.leaves.signature.store');
Route::get('/tutore/ritardi/firma/{token}', [GuardianDelayConfirmationController::class, 'show'])
    ->name('guardian.delays.signature.show');
Route::post('/tutore/ritardi/firma/{token}', [GuardianDelayConfirmationController::class, 'store'])
    ->name('guardian.delays.signature.store');

require __DIR__.'/auth.php';
