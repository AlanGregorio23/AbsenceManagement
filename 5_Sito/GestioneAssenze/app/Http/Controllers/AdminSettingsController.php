<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminSettingsUpdateRequest;
use App\Http\Requests\ImportSchoolHolidaysPdfRequest;
use App\Http\Requests\SchoolHolidayRequest;
use App\Models\AbsenceReason;
use App\Models\AbsenceSetting;
use App\Models\AdminSettings;
use App\Models\DelayRule;
use App\Models\DelaySetting;
use App\Models\LoginSecuritySetting;
use App\Models\OperationLog;
use App\Models\OperationLogSetting;
use App\Models\SchoolHoliday;
use App\Models\User;
use App\Services\SchoolHolidayPdfExtractor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminSettingsController extends BaseController
{
    public function __construct()
    {

        $this->middleware('admin');

    }

    public function edit()
    {
        $settings = AdminSettings::forEdit();

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'retentionAdvice' => $this->buildRetentionAdvice($settings),
        ]);
    }

    public function update(AdminSettingsUpdateRequest $request)
    {
        $validated = $request->validated();
        $validated['absence']['vice_director_email'] = $this->normalizeOptionalEmail(
            $validated['absence']['vice_director_email'] ?? null
        );
        $validated['delay']['deadline_active'] = (bool) ($validated['delay']['deadline_active'] ?? false);
        $validated['delay']['deadline_business_days'] = max(
            (int) ($validated['delay']['deadline_business_days'] ?? 0),
            0
        );
        $validated['delay']['justification_business_days'] = max(
            (int) ($validated['delay']['justification_business_days'] ?? 0),
            0
        );
        $validated['delay']['pre_expiry_warning_business_days'] = max(
            (int) ($validated['delay']['pre_expiry_warning_business_days'] ?? 0),
            0
        );
        $validated['absence']['leave_request_notice_working_hours'] = max(
            (int) ($validated['absence']['leave_request_notice_working_hours'] ?? 24),
            0
        );
        $before = AdminSettings::forEdit();

        $this->validateDelayRulesRanges($validated['delay_rules']);

        DB::transaction(function () use ($validated) {
            $absenceSetting = AbsenceSetting::first();

            if ($absenceSetting) {
                $absenceSetting->update($validated['absence']);
            } else {
                AbsenceSetting::create($validated['absence']);
            }

            $reasonIds = [];
            foreach ($validated['reasons'] as $reason) {
                $requiresManagementConsent = (bool) ($reason['requires_management_consent'] ?? false);
                $requiresDocumentOnLeaveCreation = $requiresManagementConsent
                    && (bool) ($reason['requires_document_on_leave_creation'] ?? false);
                $managementConsentNote = trim((string) ($reason['management_consent_note'] ?? ''));
                $payload = [
                    'name' => trim($reason['name']),
                    'counts_40_hours' => $reason['counts_40_hours'],
                    'requires_management_consent' => $requiresManagementConsent,
                    'requires_document_on_leave_creation' => $requiresDocumentOnLeaveCreation,
                    'management_consent_note' => $requiresManagementConsent && $managementConsentNote !== ''
                        ? $managementConsentNote
                        : null,
                ];

                if ($reason['id'] ?? null) {
                    AbsenceReason::whereKey($reason['id'])->update($payload);
                    $reasonIds[] = $reason['id'];

                    continue;
                }

                $created = AbsenceReason::create($payload);
                $reasonIds[] = $created->id;
            }

            if (! empty($reasonIds)) {
                AbsenceReason::whereNotIn('id', $reasonIds)->delete();
            } else {
                AbsenceReason::query()->delete();
            }

            $delaySetting = DelaySetting::first();

            if ($delaySetting) {
                $delaySetting->update($validated['delay']);
            } else {
                DelaySetting::create($validated['delay']);
            }

            $ruleIds = [];
            foreach ($validated['delay_rules'] as $rule) {
                $payload = [
                    'min_delays' => $rule['min_delays'],
                    'max_delays' => $rule['max_delays'],
                    'actions' => $this->sanitizeActions($rule['actions']),
                    'info_message' => isset($rule['info_message'])
                        ? trim((string) $rule['info_message'])
                        : null,
                ];

                if ($rule['id'] ?? null) {
                    DelayRule::whereKey($rule['id'])->update($payload);
                    $ruleIds[] = $rule['id'];

                    continue;
                }

                $created = DelayRule::create($payload);
                $ruleIds[] = $created->id;
            }

            if (! empty($ruleIds)) {
                DelayRule::whereNotIn('id', $ruleIds)->delete();
            } else {
                DelayRule::query()->delete();
            }

            $logSettings = OperationLogSetting::first();
            $logPayload = [
                'interaction_retention_days' => OperationLogSetting::sanitizeRetentionDays(
                    $validated['logs']['interaction_retention_days']
                ),
                'error_retention_days' => OperationLogSetting::sanitizeRetentionDays(
                    $validated['logs']['error_retention_days']
                ),
            ];

            if ($logSettings) {
                $logSettings->update($logPayload);
            } else {
                OperationLogSetting::create($logPayload);
            }

            $loginSettings = LoginSecuritySetting::query()->first();
            $loginPayload = [
                'max_attempts' => LoginSecuritySetting::sanitizeMaxAttempts(
                    (int) ($validated['login']['max_attempts'] ?? null)
                ),
                'decay_seconds' => LoginSecuritySetting::sanitizeDecaySeconds(
                    (int) ($validated['login']['decay_seconds'] ?? null)
                ),
                'forgot_password_max_attempts' => LoginSecuritySetting::sanitizeForgotPasswordMaxAttempts(
                    (int) ($validated['login']['forgot_password_max_attempts'] ?? null)
                ),
                'forgot_password_decay_seconds' => LoginSecuritySetting::sanitizeForgotPasswordDecaySeconds(
                    (int) ($validated['login']['forgot_password_decay_seconds'] ?? null)
                ),
                'reset_password_max_attempts' => LoginSecuritySetting::sanitizeResetPasswordMaxAttempts(
                    (int) ($validated['login']['reset_password_max_attempts'] ?? null)
                ),
                'reset_password_decay_seconds' => LoginSecuritySetting::sanitizeResetPasswordDecaySeconds(
                    (int) ($validated['login']['reset_password_decay_seconds'] ?? null)
                ),
            ];

            if ($loginSettings) {
                $loginSettings->update($loginPayload);
            } else {
                LoginSecuritySetting::query()->create($loginPayload);
            }
        });

        $after = AdminSettings::forEdit();
        OperationLog::record(
            $request->user(),
            'admin.settings.updated',
            'settings',
            null,
            [
                'login_security' => [
                    'before' => $before['login'] ?? null,
                    'after' => $after['login'] ?? null,
                ],
                'log_retention' => [
                    'before' => $before['logs'] ?? null,
                    'after' => $after['logs'] ?? null,
                ],
            ],
            'INFO',
            $request
        );

        return redirect()->route('admin.settings');
    }

    public function importHolidaysFromPdf(ImportSchoolHolidaysPdfRequest $request, SchoolHolidayPdfExtractor $extractor)
    {
        $validated = $request->validated();

        $uploadedFile = $validated['calendar_pdf'];
        $disk = Storage::disk(config('filesystems.default', 'local'));
        $storedPath = $disk->putFile('private/configurazione/calendari', $uploadedFile);
        $absolutePath = $disk->path($storedPath);

        try {
            $parsed = $extractor->extract($absolutePath);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'calendar_pdf' => 'Import calendario non riuscito: '.$this->humanizeHolidayImportError($exception),
            ]);
        }

        $actor = $request->user();
        $importedDates = $parsed['dates'];
        $schoolYears = [];
        $upsertPayload = [];
        $timestamp = now();
        foreach ($importedDates as $date) {
            $normalizedDate = Carbon::parse((string) $date)->toDateString();
            $schoolYear = SchoolHoliday::schoolYearFromDate($normalizedDate);
            $schoolYears[$schoolYear] = true;
            $upsertPayload[] = [
                'holiday_date' => $normalizedDate,
                'school_year' => $schoolYear,
                'label' => null,
                'source' => SchoolHoliday::SOURCE_PDF_IMPORT,
                'source_file_path' => $storedPath,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        DB::transaction(function () use ($schoolYears, $upsertPayload): void {
            SchoolHoliday::query()
                ->whereIn('school_year', array_keys($schoolYears))
                ->where('source', SchoolHoliday::SOURCE_PDF_IMPORT)
                ->delete();

            SchoolHoliday::query()->upsert(
                $upsertPayload,
                ['holiday_date'],
                [
                    'school_year',
                    'label',
                    'source',
                    'source_file_path',
                    'updated_by',
                    'updated_at',
                ]
            );
        });

        OperationLog::record(
            $actor,
            'admin.settings.holidays.imported',
            'settings',
            null,
            [
                'imported_count' => count($importedDates),
                'school_years' => array_keys($schoolYears),
                'source_file_path' => $storedPath,
                'parser_metadata' => $parsed['metadata'] ?? [],
            ],
            'INFO',
            $request
        );

        return redirect()->route('admin.settings')->with(
            'success',
            'Calendario vacanze importato: '.count($importedDates).' date salvate.'
        );
    }

    public function storeHoliday(SchoolHolidayRequest $request)
    {
        $validated = $request->validated();

        $date = Carbon::parse((string) $validated['holiday_date'])->toDateString();
        $label = $this->normalizeHolidayLabel($validated['label'] ?? null);
        $actor = $request->user();

        $holiday = SchoolHoliday::query()->create([
            'holiday_date' => $date,
            'school_year' => SchoolHoliday::schoolYearFromDate($date),
            'label' => $label,
            'source' => SchoolHoliday::SOURCE_MANUAL,
            'source_file_path' => null,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        OperationLog::record(
            $actor,
            'admin.settings.holiday.created',
            'school_holiday',
            $holiday->id,
            [
                'holiday_date' => $date,
                'label' => $label,
                'school_year' => $holiday->school_year,
            ],
            'INFO',
            $request
        );

        return redirect()->route('admin.settings')->with('success', 'Data vacanza aggiunta.');
    }

    public function updateHoliday(SchoolHolidayRequest $request, SchoolHoliday $holiday)
    {
        $validated = $request->validated();

        $date = Carbon::parse((string) $validated['holiday_date'])->toDateString();
        $label = $this->normalizeHolidayLabel($validated['label'] ?? null);
        $actor = $request->user();

        $holiday->update([
            'holiday_date' => $date,
            'school_year' => SchoolHoliday::schoolYearFromDate($date),
            'label' => $label,
            'source' => SchoolHoliday::SOURCE_MANUAL,
            'updated_by' => $actor?->id,
        ]);

        OperationLog::record(
            $actor,
            'admin.settings.holiday.updated',
            'school_holiday',
            $holiday->id,
            [
                'holiday_date' => $date,
                'label' => $label,
                'school_year' => $holiday->school_year,
            ],
            'INFO',
            $request
        );

        return redirect()->route('admin.settings')->with('success', 'Data vacanza aggiornata.');
    }

    public function destroyHoliday(Request $request, SchoolHoliday $holiday)
    {
        $actor = $request->user();
        $payload = [
            'holiday_date' => $holiday->holiday_date?->toDateString(),
            'label' => $holiday->label,
            'school_year' => $holiday->school_year,
        ];

        $holiday->delete();

        OperationLog::record(
            $actor,
            'admin.settings.holiday.deleted',
            'school_holiday',
            $holiday->id,
            $payload,
            'WARNING',
            $request
        );

        return redirect()->route('admin.settings')->with('success', 'Data vacanza eliminata.');
    }

    private function humanizeHolidayImportError(\Throwable $exception): string
    {
        $rawMessage = trim($exception->getMessage());
        if ($rawMessage === '') {
            return 'Errore inatteso durante il parsing del PDF.';
        }

        $normalizedMessage = strtolower($rawMessage);
        if (
            str_contains($normalizedMessage, 'did not find executable')
            || str_contains($normalizedMessage, 'python.exe')
            || str_contains($normalizedMessage, 'interprete python')
        ) {
            return 'Ambiente Python non pronto su questo PC. Il sistema sta provando a ricrearlo: riprova tra poco.';
        }

        if (
            str_contains($normalizedMessage, 'no module named')
            && str_contains($normalizedMessage, 'pypdf')
        ) {
            return 'Manca il modulo Python pypdf necessario per leggere il calendario PDF.';
        }

        return $rawMessage;
    }

    private function sanitizeActions(array $actions): array
    {
        $normalized = [];

        foreach ($actions as $action) {
            $type = $action['type'] ?? null;

            if (! $type) {
                continue;
            }

            $item = ['type' => $type];

            if (! empty($action['detail'])) {
                $item['detail'] = trim((string) $action['detail']);
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function validateDelayRulesRanges(array $rules): void
    {
        foreach ($rules as $rule) {
            $min = $rule['min_delays'];
            $max = $rule['max_delays'];

            if ($max !== null && $max < $min) {
                throw ValidationException::withMessages([
                    'delay_rules' => 'Ogni intervallo deve avere il massimo maggiore o uguale al minimo.',
                ]);
            }
        }
    }

    private function buildRetentionAdvice(array $settings): array
    {
        $usersCount = (int) User::query()->count();
        $currentLogsCount = (int) OperationLog::query()->count();

        $estimatedDailyTotal = max(60, (int) round($usersCount * 1.2));
        $estimatedDailyInfo = max(1, (int) round($estimatedDailyTotal * 0.78));
        $estimatedDailyError = max(1, $estimatedDailyTotal - $estimatedDailyInfo);

        $interactionDays = OperationLogSetting::sanitizeRetentionDays(
            (int) ($settings['logs']['interaction_retention_days'] ?? OperationLogSetting::DEFAULT_RETENTION_DAYS)
        );
        $errorDays = OperationLogSetting::sanitizeRetentionDays(
            (int) ($settings['logs']['error_retention_days'] ?? OperationLogSetting::DEFAULT_RETENTION_DAYS)
        );

        $estimatedRowsAtCurrentRetention = ($interactionDays * $estimatedDailyInfo)
            + ($errorDays * $estimatedDailyError);

        $targetOkRows = 120000;
        $targetWarningRows = 160000;

        $recommendedInteractionDays = max(
            120,
            min(730, (int) floor($targetOkRows / max(1, $estimatedDailyTotal)))
        );
        $recommendedErrorDays = max(
            365,
            min(1095, (int) floor($targetOkRows / max(1, $estimatedDailyError)))
        );

        $status = 'ok';
        if ($estimatedRowsAtCurrentRetention > $targetWarningRows) {
            $status = 'heavy';
        } elseif ($estimatedRowsAtCurrentRetention > $targetOkRows) {
            $status = 'warning';
        }

        return [
            'users_count' => $usersCount,
            'current_logs_count' => $currentLogsCount,
            'estimated_daily_total' => $estimatedDailyTotal,
            'estimated_daily_info' => $estimatedDailyInfo,
            'estimated_daily_error' => $estimatedDailyError,
            'estimated_rows_current_retention' => (int) $estimatedRowsAtCurrentRetention,
            'target_ok_rows' => $targetOkRows,
            'target_warning_rows' => $targetWarningRows,
            'status' => $status,
            'recommended_interaction_days' => $recommendedInteractionDays,
            'recommended_error_days' => $recommendedErrorDays,
        ];
    }

    private function normalizeOptionalEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) ($value ?? '')));

        return $email !== '' ? $email : null;
    }

    private function normalizeHolidayLabel(mixed $value): ?string
    {
        $label = trim((string) ($value ?? ''));

        return $label !== '' ? $label : null;
    }
}
