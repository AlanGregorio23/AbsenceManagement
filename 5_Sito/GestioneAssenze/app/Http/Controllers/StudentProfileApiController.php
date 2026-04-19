<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentProfileExportRequest;
use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\Delay;
use App\Models\Leave;
use App\Models\User;
use App\Support\DelayRuleEvaluator;
use App\Support\StudentStatusThresholdResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentProfileApiController extends Controller
{
    private const EXPORT_SECTIONS = [
        'student',
        'guardians',
        'summary',
        'absences',
        'delays',
        'leaves',
    ];

    public function show(Request $request, User $student): JsonResponse
    {
        $viewer = $this->resolveViewer($request);
        $profile = $this->buildProfileData($viewer, $student);

        return response()->json([
            'data' => $profile,
        ]);
    }

    public function page(Request $request, User $student): Response
    {
        $viewer = $this->resolveViewer($request);
        $profile = $this->buildProfileData($viewer, $student);

        return Inertia::render('Shared/StudentProfile', [
            'profile' => $profile,
            'backLink' => $this->resolveBackLink($viewer),
        ]);
    }

    public function export(StudentProfileExportRequest $request, User $student): StreamedResponse
    {
        $viewer = $this->resolveViewer($request);
        if (! $student->hasRole('student')) {
            abort(404);
        }

        $this->authorizeViewer($viewer, $student);

        $validated = $request->validated();
        $sections = $this->normalizeExportSections($validated['sections'] ?? []);
        [$dateFrom, $dateTo] = $this->resolveExportDateRange($validated);

        $student->load([
            'classes',
            'guardians' => function ($query) {
                $query->orderBy('guardians.name');
            },
        ]);

        $reasonRules = AbsenceReason::query()
            ->get()
            ->keyBy(fn (AbsenceReason $reason) => strtolower(trim((string) $reason->name)));

        $absences = $this->exportAbsenceQuery($student, $dateFrom, $dateTo)->get();
        $delays = $this->exportDelayQuery($student, $dateFrom, $dateTo)->get();
        $leaves = $this->exportLeaveQuery($student, $dateFrom, $dateTo)->get();
        $stats = $this->buildExportStats($absences, $delays, $leaves, $reasonRules);
        $classLabels = $student->classes
            ->map(function ($class) {
                if ($class->year && $class->section) {
                    return $class->year.$class->section;
                }

                return $class->name;
            })
            ->unique()
            ->values()
            ->all();

        $fileName = 'export-studente-'
            .$student->id.'-'
            .now()->format('Ymd-His')
            .'.csv';

        return response()->streamDownload(function () use (
            $student,
            $viewer,
            $sections,
            $dateFrom,
            $dateTo,
            $classLabels,
            $absences,
            $delays,
            $leaves,
            $reasonRules,
            $stats
        ): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Export dati studente'], ';');
            fputcsv($handle, ['Studente', $student->fullName()], ';');
            fputcsv($handle, ['Generato il', now()->format('d/m/Y H:i')], ';');
            fputcsv($handle, ['Generato da', $viewer->fullName()], ';');
            fputcsv($handle, ['Periodo da', $dateFrom?->format('d/m/Y') ?? 'Tutto'], ';');
            fputcsv($handle, ['Periodo a', $dateTo?->format('d/m/Y') ?? 'Tutto'], ';');
            fwrite($handle, PHP_EOL);

            if (in_array('student', $sections, true)) {
                $this->writeExportSection($handle, 'Dati studente', ['Campo', 'Valore'], [
                    ['Codice', 'S-'.str_pad((string) $student->id, 3, '0', STR_PAD_LEFT)],
                    ['Nome', (string) $student->name],
                    ['Cognome', (string) $student->surname],
                    ['Email', (string) $student->email],
                    ['Data nascita', $student->birth_date?->format('d/m/Y') ?? '-'],
                    ['Stato account', (bool) $student->active ? 'Attivo' : 'Inattivo'],
                    ['Classi', implode(', ', $classLabels) ?: '-'],
                ]);
            }

            if (in_array('guardians', $sections, true)) {
                $guardianRows = $student->guardians
                    ->map(fn ($guardian) => [
                        $guardian->name,
                        $guardian->email,
                        $guardian->pivot?->relationship ?: '-',
                    ])
                    ->values()
                    ->all();

                $this->writeExportSection($handle, 'Tutori', ['Nome', 'Email', 'Relazione'], $guardianRows);
            }

            if (in_array('summary', $sections, true)) {
                $this->writeExportSection($handle, 'Riepilogo', ['Campo', 'Valore'], $stats);
            }

            if (in_array('absences', $sections, true)) {
                $absenceRows = $absences->map(function (Absence $absence) use ($reasonRules) {
                    $certificate = $absence->medicalCertificates
                        ->sortByDesc(fn ($item) => $item->uploaded_at?->timestamp ?? 0)
                        ->first();
                    $countsHours = $absence->resolveCounts40Hours($reasonRules);

                    return [
                        'A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
                        $absence->start_date?->format('d/m/Y') ?? '-',
                        $absence->end_date?->format('d/m/Y') ?? '-',
                        (int) $absence->assigned_hours,
                        (string) ($absence->reason ?? '-'),
                        $this->absenceStatusLabel((string) $absence->status),
                        $this->boolLabel($countsHours),
                        $this->boolLabel((bool) $absence->medical_certificate_required),
                        $this->boolLabel($certificate !== null),
                        $certificate ? $this->boolLabel((bool) $certificate->valid) : '-',
                        (string) ($absence->teacher_comment ?? ''),
                    ];
                })->all();

                $this->writeExportSection($handle, 'Assenze', [
                    'Codice',
                    'Inizio',
                    'Fine',
                    'Ore',
                    'Motivo',
                    'Stato',
                    'Nel limite ore',
                    'Certificato richiesto',
                    'Certificato caricato',
                    'Certificato valido',
                    'Commento docente',
                ], $absenceRows);
            }

            if (in_array('delays', $sections, true)) {
                $delayRows = $delays->map(fn (Delay $delay) => [
                    'R-'.str_pad((string) $delay->id, 4, '0', STR_PAD_LEFT),
                    $delay->delay_datetime?->format('d/m/Y') ?? '-',
                    (int) $delay->minutes,
                    (string) ($delay->notes ?? '-'),
                    $this->delayStatusLabel($delay),
                    $this->boolLabel((bool) $delay->count_in_semester),
                    $delay->justification_deadline?->format('d/m/Y') ?? '-',
                    (string) ($delay->teacher_comment ?? ''),
                ])->all();

                $this->writeExportSection($handle, 'Ritardi', [
                    'Codice',
                    'Data',
                    'Minuti',
                    'Motivo',
                    'Stato',
                    'Conteggiato semestre',
                    'Scadenza',
                    'Commento docente',
                ], $delayRows);
            }

            if (in_array('leaves', $sections, true)) {
                $leaveRows = $leaves->map(fn (Leave $leave) => [
                    'C-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
                    $leave->start_date?->format('d/m/Y') ?? '-',
                    $leave->end_date?->format('d/m/Y') ?? '-',
                    (int) $leave->requested_hours,
                    (string) ($leave->reason ?? '-'),
                    (string) ($leave->destination ?? '-'),
                    Leave::statusLabel((string) $leave->status),
                    $this->boolLabel((bool) ($leave->count_hours ?? true)),
                    $this->boolLabel(! empty($leave->documentation_path)),
                    (string) ($leave->workflow_comment ?? ''),
                ])->all();

                $this->writeExportSection($handle, 'Congedi', [
                    'Codice',
                    'Inizio',
                    'Fine',
                    'Ore',
                    'Motivo',
                    'Destinazione',
                    'Stato',
                    'Nel limite ore',
                    'Documentazione',
                    'Commento',
                ], $leaveRows);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function normalizeExportSections(array $sections): array
    {
        $normalized = collect($sections)
            ->map(fn ($section) => trim((string) $section))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalized === [] || in_array('all', $normalized, true)) {
            return self::EXPORT_SECTIONS;
        }

        return collect($normalized)
            ->filter(fn (string $section) => in_array($section, self::EXPORT_SECTIONS, true))
            ->values()
            ->all() ?: self::EXPORT_SECTIONS;
    }

    /**
     * @param  array<string,mixed>  $validated
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function resolveExportDateRange(array $validated): array
    {
        $dateFrom = filled($validated['date_from'] ?? null)
            ? Carbon::parse((string) $validated['date_from'])->startOfDay()
            : null;
        $dateTo = filled($validated['date_to'] ?? null)
            ? Carbon::parse((string) $validated['date_to'])->startOfDay()
            : null;

        return [$dateFrom, $dateTo];
    }

    private function exportAbsenceQuery(User $student, ?Carbon $dateFrom, ?Carbon $dateTo)
    {
        $query = Absence::query()
            ->with('medicalCertificates')
            ->where('student_id', $student->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        if ($dateFrom) {
            $query->where(function ($builder) use ($dateFrom) {
                $builder
                    ->whereDate('end_date', '>=', $dateFrom->toDateString())
                    ->orWhere(function ($subQuery) use ($dateFrom) {
                        $subQuery
                            ->whereNull('end_date')
                            ->whereDate('start_date', '>=', $dateFrom->toDateString());
                    });
            });
        }

        if ($dateTo) {
            $query->whereDate('start_date', '<=', $dateTo->toDateString());
        }

        return $query;
    }

    private function exportDelayQuery(User $student, ?Carbon $dateFrom, ?Carbon $dateTo)
    {
        $query = Delay::query()
            ->where('student_id', $student->id)
            ->orderByDesc('delay_datetime')
            ->orderByDesc('id');

        if ($dateFrom) {
            $query->whereDate('delay_datetime', '>=', $dateFrom->toDateString());
        }

        if ($dateTo) {
            $query->whereDate('delay_datetime', '<=', $dateTo->toDateString());
        }

        return $query;
    }

    private function exportLeaveQuery(User $student, ?Carbon $dateFrom, ?Carbon $dateTo)
    {
        $query = Leave::query()
            ->where('student_id', $student->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        if ($dateFrom) {
            $query->where(function ($builder) use ($dateFrom) {
                $builder
                    ->whereDate('end_date', '>=', $dateFrom->toDateString())
                    ->orWhere(function ($subQuery) use ($dateFrom) {
                        $subQuery
                            ->whereNull('end_date')
                            ->whereDate('start_date', '>=', $dateFrom->toDateString());
                    });
            });
        }

        if ($dateTo) {
            $query->whereDate('start_date', '<=', $dateTo->toDateString());
        }

        return $query;
    }

    private function buildExportStats(
        Collection $absences,
        Collection $delays,
        Collection $leaves,
        Collection $reasonRules
    ): array {
        $hoursOnLimit = (int) $absences
            ->filter(fn (Absence $absence) => $absence->resolveCounts40Hours($reasonRules))
            ->sum('assigned_hours');
        $arbitraryHours = (int) $absences
            ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_ARBITRARY)
            ->sum('assigned_hours');
        $registeredDelays = $delays
            ->filter(fn (Delay $delay) => (bool) $delay->count_in_semester);

        return [
            ['Assenze totali', (int) $absences->count()],
            ['Ore assenza totali', (int) $absences->sum('assigned_hours')],
            ['Ore nel limite annuale', $hoursOnLimit],
            ['Ore arbitrarie', $arbitraryHours],
            ['Ritardi totali', (int) $delays->count()],
            ['Ritardi conteggiati semestre', (int) $registeredDelays->count()],
            ['Minuti ritardo totali', (int) $delays->sum('minutes')],
            ['Congedi totali', (int) $leaves->count()],
            ['Ore congedo totali', (int) $leaves->sum('requested_hours')],
        ];
    }

    private function writeExportSection($handle, string $title, array $headers, array $rows): void
    {
        fputcsv($handle, [$title], ';');
        fputcsv($handle, $headers, ';');

        if ($rows === []) {
            fputcsv($handle, ['Nessun dato'], ';');
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }

        fwrite($handle, PHP_EOL);
    }

    private function boolLabel(bool $value): string
    {
        return $value ? 'Si' : 'No';
    }

    private function absenceStatusLabel(string $status): string
    {
        return match (Absence::normalizeStatus($status)) {
            Absence::STATUS_JUSTIFIED => 'Giustificata',
            Absence::STATUS_ARBITRARY => 'Arbitraria',
            Absence::STATUS_DRAFT => 'Bozza',
            default => 'Segnalata',
        };
    }

    private function delayStatusLabel(Delay $delay): string
    {
        $status = Delay::normalizeStatus($delay->status);

        if ($status === Delay::STATUS_JUSTIFIED) {
            return 'Giustificato';
        }

        if ($status === Delay::STATUS_REGISTERED) {
            if ($delay->justification_deadline
                && Carbon::parse($delay->justification_deadline)->startOfDay()->lt(Carbon::today())
            ) {
                return 'Arbitrario';
            }

            return 'Registrato';
        }

        return 'Segnalato';
    }

    private function authorizeViewer(User $viewer, User $student): void
    {
        if ($viewer->hasRole('admin') || $viewer->hasRole('laboratory_manager')) {
            return;
        }

        if ($viewer->hasRole('teacher')) {
            if ($this->teacherCanViewStudent($viewer, $student)) {
                return;
            }

            abort(403);
        }

        abort(403);
    }

    private function teacherCanViewStudent(User $teacher, User $student): bool
    {
        return DB::table('class_user')
            ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
            ->where('class_user.user_id', $student->id)
            ->where('class_teacher.teacher_id', $teacher->id)
            ->exists();
    }

    private function buildProfileData(User $viewer, User $student): array
    {
        if (! $student->hasRole('student')) {
            abort(404);
        }

        $this->authorizeViewer($viewer, $student);

        $student->load([
            'classes',
            'guardians' => function ($query) {
                $query->orderBy('guardians.name');
            },
        ]);

        $classLabels = $student->classes
            ->map(function ($class) {
                if ($class->year && $class->section) {
                    return $class->year.$class->section;
                }

                return $class->name;
            })
            ->unique()
            ->values()
            ->all();

        $absences = Absence::query()
            ->with('medicalCertificates')
            ->where('student_id', $student->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
        $delays = Delay::query()
            ->where('student_id', $student->id)
            ->orderByDesc('delay_datetime')
            ->orderByDesc('id')
            ->get();
        $leaves = Leave::query()
            ->where('student_id', $student->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $reasonRules = AbsenceReason::query()
            ->get()
            ->keyBy(fn (AbsenceReason $reason) => strtolower(trim((string) $reason->name)));

        $hoursOn40 = (int) $absences
            ->filter(fn (Absence $absence) => $absence->resolveCounts40Hours($reasonRules))
            ->sum('assigned_hours');
        $arbitraryHours = (int) $absences
            ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_ARBITRARY)
            ->sum('assigned_hours');

        $stats = [
            'absences_total' => (int) $absences->count(),
            'absences_reported' => (int) $absences
                ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_REPORTED)
                ->count(),
            'absences_justified' => (int) $absences
                ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_JUSTIFIED)
                ->count(),
            'absences_arbitrary' => (int) $absences
                ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_ARBITRARY)
                ->count(),
            'absence_hours_total' => (int) $absences->sum('assigned_hours'),
            'hours_on_40' => $hoursOn40,
            'arbitrary_hours' => $arbitraryHours,
            'delays_total' => (int) $delays->count(),
            'delays_active' => (int) $delays
                ->filter(fn (Delay $delay) => Delay::normalizeStatus($delay->status) === Delay::STATUS_REPORTED)
                ->count(),
            'delay_minutes_total' => (int) $delays->sum('minutes'),
            'leaves_total' => (int) $leaves->count(),
            'leave_hours_total' => (int) $leaves->sum('requested_hours'),
        ];
        $delayRuleInsights = $this->buildDelayRuleInsights($delays);
        $stats['delays_registered_semester'] = (int) $delayRuleInsights['registered_count'];
        $stats['delays_unregistered_semester'] = max(
            (int) $stats['delays_total'] - (int) $stats['delays_registered_semester'],
            0
        );

        $statusResult = $this->buildStatus($stats, $viewer);
        $status = $statusResult['status'];
        $statusRules = $statusResult['rules'];

        $guardians = $student->guardians
            ->map(function ($guardian) {
                return [
                    'id' => $guardian->id,
                    'name' => $guardian->name,
                    'email' => $guardian->email,
                    'relationship' => $guardian->pivot?->relationship,
                ];
            })
            ->values()
            ->all();

        $guardianContact = count($guardians) > 0 ? $guardians[0] : null;

        $records = $this->buildRecords($absences, $delays, $leaves, $reasonRules);
        $timeline = collect($records)
            ->take(8)
            ->map(function (array $record) {
                return [
                    'type' => $record['type'],
                    'date' => $record['date'],
                    'status' => $record['status'],
                    'description' => $record['detail'],
                ];
            })
            ->values()
            ->all();

        return [
            'student_id' => $student->id,
            'code' => 'S-'.str_pad((string) $student->id, 3, '0', STR_PAD_LEFT),
            'name' => $student->name,
            'surname' => $student->surname,
            'full_name' => trim($student->name.' '.$student->surname),
            'email' => $student->email,
            'birth_date' => $student->birth_date?->toDateString(),
            'active' => (bool) $student->active,
            'classes' => $classLabels,
            'guardians' => $guardians,
            'guardian_contact' => $guardianContact,
            'primary_guardian' => $guardianContact,
            'stats' => $stats,
            'status' => $status,
            'status_rules' => $statusRules,
            'delay_rule_insights' => $delayRuleInsights,
            'timeline' => $timeline,
            'records' => $records,
        ];
    }

    private function buildDelayRuleInsights(Collection $delays): array
    {
        $semester = Delay::resolveSemester(Carbon::today());
        $registeredDelays = $delays
            ->filter(fn (Delay $delay) => Delay::shouldCountInSemester($delay, $semester));
        $registeredCount = (int) $registeredDelays->count();
        $registeredMinutes = (int) $registeredDelays
            ->sum(fn (Delay $delay) => max(0, (int) ($delay->minutes ?? 0)));

        $ruleEvaluation = DelayRuleEvaluator::evaluateForCount($registeredCount);
        $matchedRule = $ruleEvaluation['primary_rule'];
        $applicableRules = $ruleEvaluation['applicable_rules'];
        $actions = $ruleEvaluation['actions'];
        $extraActivityRequired = $actions->contains(
            fn (array $action) => $action['type'] === 'extra_activity_notice'
        );
        $recoveryEstimate = $this->buildDelayRecoveryEstimate($registeredMinutes, $extraActivityRequired);
        $conductPenaltyActions = $actions
            ->filter(fn (array $action) => $action['type'] === 'conduct_penalty')
            ->values();
        $conductPenaltyPossible = $conductPenaltyActions->isNotEmpty();
        $conductPenaltyDetails = $conductPenaltyActions
            ->pluck('detail')
            ->map(fn ($detail) => trim((string) $detail))
            ->filter(fn (string $detail) => $detail !== '')
            ->values()
            ->all();
        $actionLines = $actions
            ->map(fn (array $action) => $this->mapDelayRuleActionLine($action, $recoveryEstimate))
            ->all();

        return [
            'registered_count' => $registeredCount,
            'registered_minutes' => $registeredMinutes,
            'rule_id' => $matchedRule?->id,
            'rule_min_delays' => $matchedRule?->min_delays,
            'rule_max_delays' => $matchedRule?->max_delays,
            'applicable_rule_ids' => $applicableRules->pluck('id')->values()->all(),
            'info_message' => implode(' ', $ruleEvaluation['info_messages']),
            'info_messages' => $ruleEvaluation['info_messages'],
            'actions' => $actions->all(),
            'action_lines' => $actionLines,
            'requires_extra_activity' => $extraActivityRequired,
            'recovery_estimate' => $recoveryEstimate,
            'possible_conduct_penalty' => $conductPenaltyPossible,
            'conduct_penalty_details' => $conductPenaltyDetails,
            'semester_key' => $semester->key,
            'semester_start' => $semester->start->toDateString(),
            'semester_end' => $semester->end->toDateString(),
        ];
    }

    private function buildDelayRecoveryEstimate(int $registeredMinutes, bool $requiresExtraActivity): array
    {
        $minutesFromDelays = max(0, $registeredMinutes);

        if (! $requiresExtraActivity || $minutesFromDelays === 0) {
            return [
                'minutes_from_registered_delays' => $minutesFromDelays,
                'minutes' => 0,
                'hours' => 0,
                'activities_60_min' => 0,
                'label' => 'Non previsto',
            ];
        }

        $activities = (int) ceil($minutesFromDelays / 60);
        $estimatedMinutes = $activities * 60;
        $estimatedHours = round($estimatedMinutes / 60, 2);
        $estimatedHoursLabel = rtrim(rtrim(number_format($estimatedHours, 2, '.', ''), '0'), '.');
        $activityLabel = $activities === 1
            ? '1 attivita da 60 min'
            : $activities.' attivita da 60 min';

        return [
            'minutes_from_registered_delays' => $minutesFromDelays,
            'minutes' => $estimatedMinutes,
            'hours' => $estimatedHours,
            'activities_60_min' => $activities,
            'label' => $activityLabel.' ('.$estimatedMinutes.' min, '.$estimatedHoursLabel.' ore)',
        ];
    }

    private function mapDelayRuleActionLine(array $action, array $recoveryEstimate = []): string
    {
        $type = strtolower(trim((string) ($action['type'] ?? '')));
        $detail = trim((string) ($action['detail'] ?? ''));
        $recoveryActivities = max(0, (int) ($recoveryEstimate['activities_60_min'] ?? 0));
        $recoveryMinutes = max(0, (int) ($recoveryEstimate['minutes'] ?? 0));
        $recoveryHours = (float) ($recoveryEstimate['hours'] ?? 0);

        return match ($type) {
            'notify_student' => 'Notifica allievo',
            'notify_guardian' => 'Notifica tutore',
            'notify_teacher' => 'Notifica docente di classe',
            'extra_activity_notice' => $recoveryActivities > 0
                ? 'Recupero previsto: '.$recoveryActivities.' attivita da 60 min ('
                .$recoveryMinutes.' min, '.$recoveryHours.' ore)'
                : 'Recupero ore non previsto',
            'conduct_penalty' => $detail !== ''
                ? 'Penalita condotta prevista: '.$detail
                : 'Penalita condotta prevista',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /**
     * @return array{
     *   status:array{
     *     teacher_view:array{absence_code:string,delay_code:string},
     *     laboratory_view:array{absence_code:string,delay_code:string}
     *   },
     *   rules:array<string,mixed>
     * }
     */
    private function buildStatus(array $stats, User $viewer): array
    {
        $statusResolver = app(StudentStatusThresholdResolver::class);
        $teacherRules = $statusResolver->teacherThresholds($viewer);
        $teacherAbsenceHours = (int) ($stats['hours_on_40'] ?? 0);
        $teacherRegisteredDelays = (int) ($stats['delays_registered_semester'] ?? 0);
        $splitStatus = $statusResolver->resolveTeacherSplitStatus(
            $teacherAbsenceHours,
            $teacherRegisteredDelays,
            $teacherRules
        );

        return [
            'status' => [
                'teacher_view' => [
                    'absence_code' => $splitStatus['absence'],
                    'delay_code' => $splitStatus['delay'],
                ],
                'laboratory_view' => [
                    'absence_code' => $splitStatus['absence'],
                    'delay_code' => $splitStatus['delay'],
                ],
            ],
            'rules' => [
                'teacher' => $teacherRules,
                'laboratory' => $teacherRules,
            ],
        ];
    }

    private function buildRecords(
        Collection $absences,
        Collection $delays,
        Collection $leaves,
        Collection $reasonRules
    ): array {
        $absenceRecords = $absences->toBase()->map(
            fn (Absence $absence) => $this->mapAbsenceRecord($absence, $reasonRules)
        );
        $delayRecords = $delays->toBase()->map(
            fn (Delay $delay) => $this->mapDelayRecord($delay)
        );
        $leaveRecords = $leaves->toBase()->map(
            fn (Leave $leave) => $this->mapLeaveRecord($leave)
        );

        return $absenceRecords
            ->merge($delayRecords)
            ->merge($leaveRecords)
            ->sortByDesc('sort_date')
            ->values()
            ->map(function (array $record) {
                unset($record['sort_date']);

                return $record;
            })
            ->all();
    }

    private function mapAbsenceRecord(Absence $absence, Collection $reasonRules): array
    {
        $statusCode = Absence::normalizeStatus($absence->status);
        [$statusLabel, $statusBadge] = match ($statusCode) {
            Absence::STATUS_JUSTIFIED => ['Giustificata', 'bg-emerald-100 text-emerald-700'],
            Absence::STATUS_ARBITRARY => ['Arbitraria', 'bg-rose-100 text-rose-700'],
            default => ['Segnalata', 'bg-amber-100 text-amber-700'],
        };

        $start = Carbon::parse($absence->start_date);
        $end = Carbon::parse($absence->end_date ?? $absence->start_date);
        $period = $start->isSameDay($end)
            ? $start->format('d M Y')
            : $start->format('d M Y').' - '.$end->format('d M Y');
        $counts40 = $absence->resolveCounts40Hours($reasonRules);

        return [
            'record_id' => 'A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
            'type' => 'Assenza',
            'type_badge' => 'bg-sky-100 text-sky-700',
            'date' => $start->format('d M Y'),
            'period' => $period,
            'detail' => (string) ($absence->reason ?: '-'),
            'status' => $statusLabel,
            'status_badge' => $statusBadge,
            'hours' => (int) $absence->assigned_hours,
            'hours_label' => ((int) $absence->assigned_hours).' ore',
            'delay_minutes' => null,
            'delay_minutes_label' => '-',
            'count_40_hours' => $counts40,
            'count_40_label' => $counts40 ? 'Si' : 'No',
            'sort_date' => $start->startOfDay()->toDateTimeString(),
        ];
    }

    private function mapDelayRecord(Delay $delay): array
    {
        $date = Carbon::parse($delay->delay_datetime);
        $delayMinutes = max(0, (int) ($delay->minutes ?? 0));

        $statusCode = Delay::normalizeStatus($delay->status);
        [$statusLabel, $statusBadge] = match ($statusCode) {
            Delay::STATUS_JUSTIFIED => ['Giustificato', 'bg-emerald-100 text-emerald-700'],
            Delay::STATUS_REGISTERED => ['Registrato', 'bg-sky-100 text-sky-700'],
            default => ['Attesa docente', 'bg-amber-100 text-amber-700'],
        };

        return [
            'record_id' => 'R-'.str_pad((string) $delay->id, 4, '0', STR_PAD_LEFT),
            'type' => 'Ritardo',
            'type_badge' => 'bg-fuchsia-100 text-fuchsia-700',
            'date' => $date->format('d M Y'),
            'period' => $date->format('d M Y'),
            'detail' => (string) ($delay->notes ?: 'Ritardo segnalato'),
            'status' => $statusLabel,
            'status_badge' => $statusBadge,
            'hours' => null,
            'hours_label' => '-',
            'delay_minutes' => $delayMinutes,
            'delay_minutes_label' => $delayMinutes === 1 ? '1 min' : $delayMinutes.' min',
            'count_40_hours' => null,
            'count_40_label' => '-',
            'sort_date' => $date->toDateTimeString(),
        ];
    }

    private function mapLeaveRecord(Leave $leave): array
    {
        $start = Carbon::parse($leave->start_date);
        $end = Carbon::parse($leave->end_date ?? $leave->start_date);
        $period = $start->isSameDay($end)
            ? $start->format('d M Y')
            : $start->format('d M Y').' - '.$end->format('d M Y');
        $statusCode = Leave::normalizeStatus($leave->status);
        $statusLabel = Leave::statusLabel($statusCode);
        $statusBadge = Leave::statusBadge($statusCode);
        $counts40Hours = (bool) ($leave->count_hours ?? true);
        $destination = trim((string) ($leave->destination ?? ''));
        $detail = (string) ($leave->reason ?: '-');
        if ($destination !== '') {
            $detail .= ' | Destinazione: '.$destination;
        }

        return [
            'record_id' => 'C-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
            'type' => 'Congedo',
            'type_badge' => 'bg-emerald-100 text-emerald-700',
            'date' => $start->format('d M Y'),
            'period' => $period,
            'detail' => $detail,
            'status' => $statusLabel,
            'status_badge' => $statusBadge,
            'hours' => (int) ($leave->requested_hours ?? 0),
            'hours_label' => ((int) ($leave->requested_hours ?? 0)).' ore',
            'delay_minutes' => null,
            'delay_minutes_label' => '-',
            'count_40_hours' => $counts40Hours,
            'count_40_label' => $counts40Hours ? 'Si' : 'No',
            'sort_date' => $start->startOfDay()->toDateTimeString(),
        ];
    }

    private function resolveViewer(Request $request): User
    {
        $viewer = $request->user();
        if (! $viewer) {
            abort(403);
        }

        return $viewer;
    }

    private function resolveBackLink(User $viewer): array
    {
        if ($viewer->hasRole('teacher')) {
            return [
                'href' => route('teacher.students'),
                'label' => 'Torna agli studenti',
            ];
        }

        if ($viewer->hasRole('laboratory_manager')) {
            return [
                'href' => route('lab.students'),
                'label' => 'Torna agli allievi',
            ];
        }

        if ($viewer->hasRole('admin')) {
            return [
                'href' => route('admin.users'),
                'label' => 'Torna agli utenti',
            ];
        }

        return [
            'href' => route('dashboard'),
            'label' => 'Torna alla dashboard',
        ];
    }
}
