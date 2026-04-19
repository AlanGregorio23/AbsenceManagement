<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\Delay;
use App\Models\GuardianAbsenceConfirmation;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\StudentStatusThresholdResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;

class DashboardTeacherController extends BaseController
{
    public function __construct()
    {
        $this->middleware('teacher');
    }

    public function index()
    {
        $user = auth()->user();

        [$classes, $students, $studentClassMap] = $this->getTeacherContext($user);

        $absenceModel = new Absence;
        $delayModel = new Delay;

        $assenze = $absenceModel->getAbsence($user);
        $ritardi = $delayModel->getDelay($user);

        $openAssenze = $this->filterActionableAbsences($assenze);
        $openRitardi = $this->filterOpenDelays($ritardi);

        $items = collect($openAssenze)
            ->merge($openRitardi)
            ->map(function (array $item) use ($studentClassMap) {
                $item['classe'] = $studentClassMap[$item['student_id']] ?? '-';

                return $item;
            });

        $rows = $items
            ->sortByDesc('date')
            ->take(8)
            ->values()
            ->map(function (array $item) {
                return [
                    'id' => $item['id'] ?? null,
                    'absence_id' => $item['absence_id'] ?? null,
                    'delay_id' => $item['delay_id'] ?? null,
                    'student_id' => $item['student_id'] ?? null,
                    'studente' => $item['studente'],
                    'classe' => $item['classe'] ?? '-',
                    'tipo' => $item['tipo'],
                    'durata' => $item['durata'],
                    'motivo' => $item['motivo'] ?? '-',
                    'data' => $item['data'],
                    'scadenza' => $item['scadenza'] ?? '-',
                    'countdown' => $item['countdown'] ?? '-',
                    'stato' => $item['stato'],
                    'stato_code' => $item['stato_code'] ?? null,
                    'badge' => $item['badge'],
                    'firma_tutore_presente' => (bool) ($item['firma_tutore_presente'] ?? false),
                    'certificato_caricato' => (bool) ($item['certificato_caricato'] ?? false),
                    'certificato_validato' => (bool) ($item['certificato_validato'] ?? false),
                    'certificato_obbligo_code' => $item['certificato_obbligo_code'] ?? null,
                    'certificato_obbligo' => $item['certificato_obbligo'] ?? null,
                    'certificato_obbligo_short' => $item['certificato_obbligo_short'] ?? null,
                    'certificato_obbligo_badge' => $item['certificato_obbligo_badge'] ?? null,
                    'certificato_obbligo_urgente' => (bool) ($item['certificato_obbligo_urgente'] ?? false),
                    'can_approve' => (bool) ($item['can_approve'] ?? false),
                    'can_approve_without_guardian' => (bool) ($item['can_approve_without_guardian'] ?? false),
                    'can_reject' => (bool) ($item['can_reject'] ?? false),
                    'can_edit_delay' => (bool) ($item['can_edit_delay'] ?? false),
                    'can_extend_deadline' => (bool) ($item['can_extend_deadline'] ?? false),
                    'can_resend_guardian_email' => (bool) ($item['can_resend_guardian_email'] ?? false),
                    'can_accept_certificate' => (bool) ($item['can_accept_certificate'] ?? false),
                    'can_reject_certificate' => (bool) ($item['can_reject_certificate'] ?? false),
                    'can_edit_absence' => (bool) ($item['can_edit_absence'] ?? false),
                    'can_delete_absence' => (bool) ($item['can_delete_absence'] ?? false),
                    'can_delete_delay' => (bool) ($item['can_delete_delay'] ?? false),
                    'derivata_da_congedo' => (bool) ($item['derivata_da_congedo'] ?? false),
                    'derived_leave_code' => $item['derived_leave_code'] ?? null,
                ];
            });

        $studentIds = $students->pluck('id');
        $pendingAbsences = $studentIds->isEmpty()
            ? 0
            : Absence::query()
                ->whereIn('student_id', $studentIds)
                ->whereIn('status', Absence::openStatuses())
                ->count();
        $arbitraryAbsences = $studentIds->isEmpty()
            ? 0
            : Absence::query()
                ->whereIn('student_id', $studentIds)
                ->where('status', Absence::STATUS_ARBITRARY)
                ->count();
        $activeDelays = $studentIds->isEmpty()
            ? 0
            : collect($openRitardi)->count();
        $deadlineStart = Carbon::now()->startOfDay();
        $deadlineEnd = Carbon::now()->addDays(2)->endOfDay();
        $upcomingDeadlines = $studentIds->isEmpty()
            ? 0
            : Absence::query()
                ->whereIn('student_id', $studentIds)
                ->whereIn('status', Absence::openStatuses())
                ->whereNotNull('medical_certificate_deadline')
                ->whereBetween('medical_certificate_deadline', [
                    $deadlineStart,
                    $deadlineEnd,
                ])
                ->count();
        $expiredDeadlines = $studentIds->isEmpty()
            ? 0
            : Absence::query()
                ->whereIn('student_id', $studentIds)
                ->where('status', Absence::STATUS_ARBITRARY)
                ->count();

        $stats = [
            [
                'label' => 'Richieste aperte',
                'value' => (string) ($pendingAbsences + $arbitraryAbsences + $activeDelays),
                'helper' => 'Classi '.$classes->count(),
            ],
            [
                'label' => 'In attesa firma',
                'value' => (string) $pendingAbsences,
                'helper' => 'Assenze da confermare',
            ],
            [
                'label' => 'Scadenze prossime',
                'value' => (string) $upcomingDeadlines,
                'helper' => 'Entro 48 ore',
            ],
            [
                'label' => 'Scadute',
                'value' => (string) $expiredDeadlines,
                'helper' => 'Arbitrarie da prorogare',
            ],
        ];

        return Inertia::render('Dashboard/Teacher', [
            'stats' => $stats,
            'rows' => $rows,
        ]);
    }

    public function showAbsence(Request $request, Absence $absence)
    {
        $user = auth()->user();
        [, , $studentClassMap] = $this->getTeacherContext($user);

        $absenceModel = new Absence;
        $item = $absenceModel->getAbsence($user)
            ->firstWhere('absence_id', $absence->id);

        if (! $item) {
            abort(404);
        }

        $resolvedAbsence = Absence::query()
            ->whereKey($absence->id)
            ->whereIn('student_id', function ($subQuery) use ($user) {
                $subQuery
                    ->select('class_user.user_id')
                    ->from('class_user')
                    ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                    ->where('class_teacher.teacher_id', $user->id);
            })
            ->with([
                'medicalCertificates',
                'student',
                'guardianConfirmations.guardian',
                'derivedFromLeave.guardianConfirmations.guardian',
            ])
            ->firstOrFail();

        $latestCertificate = $resolvedAbsence->medicalCertificates()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();
        $firstGuardianSignature = $this->resolveFirstSignedGuardianConfirmation($resolvedAbsence);
        $derivedLeave = $resolvedAbsence->derivedFromLeave;
        $leaveGuardianSignature = $derivedLeave
            ? $this->resolveFirstSignedLeaveConfirmation($derivedLeave)
            : null;
        $firstEffectiveGuardianSignature = $firstGuardianSignature ?? $leaveGuardianSignature;
        $guardianSignedAt = $firstGuardianSignature
            ? ($firstGuardianSignature->confirmed_at ?? $firstGuardianSignature->signed_at)
            : null;
        if (! $guardianSignedAt && $leaveGuardianSignature) {
            $guardianSignedAt = $leaveGuardianSignature->confirmed_at ?? $leaveGuardianSignature->signed_at;
        }

        $effectiveCertificate = $latestCertificate
            ? [
                'id' => $latestCertificate->id,
                'filename' => basename((string) $latestCertificate->file_path),
                'uploaded_at' => $latestCertificate->uploaded_at?->format('d M Y H:i'),
                'valid' => (bool) $latestCertificate->valid,
                'source' => 'absence',
                'source_label' => 'Certificato assenza',
                'viewer_url' => route('teacher.absences.certificate.view', $resolvedAbsence->id),
                'download_url' => route('teacher.absences.certificate.download', $resolvedAbsence->id),
            ]
            : null;

        $item['classe'] = $studentClassMap[$item['student_id']] ?? '-';
        $item['start_date'] = optional($resolvedAbsence->start_date)->toDateString();
        $item['end_date'] = optional($resolvedAbsence->end_date)->toDateString();
        $item['hours'] = (int) $resolvedAbsence->assigned_hours;
        $item['motivation'] = (string) ($resolvedAbsence->reason ?? '');
        $item['counts_40_hours_comment'] = (string) ($resolvedAbsence->counts_40_hours_comment ?? '');
        $item['certificate'] = $effectiveCertificate;
        $item['certificato_caricato'] = $effectiveCertificate !== null;
        $item['certificato_validato'] = (bool) ($effectiveCertificate['valid'] ?? false);
        $item['guardian_signature'] = $firstEffectiveGuardianSignature ? [
            'confirmation_id' => $firstEffectiveGuardianSignature->id,
            'guardian_name' => $firstGuardianSignature
                ? $this->resolveSignerName($firstGuardianSignature)
                : $this->resolveLeaveSignerName($firstEffectiveGuardianSignature),
            'signed_at' => $guardianSignedAt?->format('d M Y H:i'),
            'viewer_url' => $firstGuardianSignature
                ? route('teacher.absences.guardian-signature.view', [
                    'absence' => $resolvedAbsence->id,
                ])
                : route('leaves.guardian-signature.view', ['leave' => $derivedLeave->id]),
            'source' => $firstGuardianSignature ? 'absence' : 'leave',
            'source_label' => $firstGuardianSignature
                ? 'Firma richiesta assenza'
                : 'Firma raccolta sul congedo',
        ] : null;
        $item['from_leave'] = ! is_null($resolvedAbsence->derived_from_leave_id);
        $item['derived_leave_id'] = $resolvedAbsence->derived_from_leave_id;
        $item['derived_leave_code'] = $resolvedAbsence->derived_from_leave_id
            ? 'C-'.str_pad((string) $resolvedAbsence->derived_from_leave_id, 4, '0', STR_PAD_LEFT)
            : null;
        $item['derived_leave_url'] = $resolvedAbsence->derived_from_leave_id
            ? route('leaves.show', ['leave' => $resolvedAbsence->derived_from_leave_id])
            : null;

        $allowedActions = [
            'approve',
            'approve_without_guardian',
            'reject',
            'extend',
            'edit',
            'accept_certificate',
            'reject_certificate',
            'delete',
        ];
        $initialAction = (string) $request->query('action', '');
        if (! in_array($initialAction, $allowedActions, true)) {
            $initialAction = '';
        }
        $availableActions = [];
        if ($item['can_approve'] ?? false) {
            $availableActions[] = 'approve';
        }
        if ($item['can_approve_without_guardian'] ?? false) {
            $availableActions[] = 'approve_without_guardian';
        }
        if ($item['can_reject'] ?? false) {
            $availableActions[] = 'reject';
        }
        if ($item['can_extend_deadline'] ?? false) {
            $availableActions[] = 'extend';
        }
        if ($item['can_edit_absence'] ?? false) {
            $availableActions[] = 'edit';
        }
        if ($item['can_accept_certificate'] ?? false) {
            $availableActions[] = 'accept_certificate';
        }
        if ($item['can_reject_certificate'] ?? false) {
            $availableActions[] = 'reject_certificate';
        }
        if ($item['can_delete_absence'] ?? false) {
            $availableActions[] = 'delete';
        }
        if (! in_array($initialAction, $availableActions, true)) {
            $initialAction = '';
        }

        $reasons = AbsenceReason::query()
            ->orderBy('name')
            ->get()
            ->map(fn (AbsenceReason $reason) => [
                'id' => $reason->id,
                'name' => $reason->name,
            ])
            ->values();
        $history = $this->buildAbsenceOperationHistory($resolvedAbsence);

        return Inertia::render('Teacher/AbsenceDetail', [
            'item' => $item,
            'initialAction' => $initialAction,
            'reasons' => $reasons,
            'history' => $history,
        ]);
    }

    public function showDelay(Request $request, Delay $delay)
    {
        $user = auth()->user();
        [, , $studentClassMap] = $this->getTeacherContext($user);

        $delayModel = new Delay;
        $item = $delayModel->getDelay($user)
            ->firstWhere('delay_id', $delay->id);

        if (! $item) {
            abort(404);
        }

        $resolvedDelay = Delay::query()
            ->whereKey($delay->id)
            ->whereIn('student_id', function ($subQuery) use ($user) {
                $subQuery
                    ->select('class_user.user_id')
                    ->from('class_user')
                    ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                    ->where('class_teacher.teacher_id', $user->id);
            })
            ->firstOrFail();

        $item['classe'] = $studentClassMap[$item['student_id']] ?? '-';
        $item['delay_date'] = optional($resolvedDelay->delay_datetime)->toDateString();
        $item['minutes'] = (int) ($resolvedDelay->minutes ?? 0);
        $item['motivation'] = (string) ($resolvedDelay->notes ?? '');
        $item['teacher_comment'] = (string) ($resolvedDelay->teacher_comment ?? '');

        $allowedActions = [
            'approve',
            'approve_without_guardian',
            'reject',
            'extend',
            'edit',
            'delete',
        ];
        $initialAction = (string) $request->query('action', '');
        if (! in_array($initialAction, $allowedActions, true)) {
            $initialAction = '';
        }

        $availableActions = [];
        if ($item['can_approve'] ?? false) {
            $availableActions[] = 'approve';
        }
        if ($item['can_approve_without_guardian'] ?? false) {
            $availableActions[] = 'approve_without_guardian';
        }
        if ($item['can_reject'] ?? false) {
            $availableActions[] = 'reject';
        }
        if ($item['can_extend_deadline'] ?? false) {
            $availableActions[] = 'extend';
        }
        if ($item['can_edit_delay'] ?? false) {
            $availableActions[] = 'edit';
        }
        if ($item['can_delete_delay'] ?? false) {
            $availableActions[] = 'delete';
        }
        if (! in_array($initialAction, $availableActions, true)) {
            $initialAction = '';
        }

        $history = $this->buildDelayOperationHistory($resolvedDelay);

        return Inertia::render('Teacher/DelayDetail', [
            'item' => $item,
            'initialAction' => $initialAction,
            'history' => $history,
        ]);
    }

    public function classes()
    {
        $user = auth()->user();
        [$classes] = $this->getTeacherContext($user);

        $items = $classes->map(function (SchoolClass $class) {
            $label = $class->year && $class->section
                ? $class->year.$class->section
                : $class->name;

            return [
                'class_id' => $class->id,
                'id' => $label,
                'corso' => $class->name,
                'studenti' => $class->students->count(),
            ];
        });

        return Inertia::render('Teacher/Classes', [
            'items' => $items,
        ]);
    }

    public function students(Request $request)
    {
        $user = auth()->user();
        [$classes, $students, $studentClassMap] = $this->getTeacherContext($user);

        $initialClassFilter = 'Tutte';
        $requestedClassId = (int) $request->query('class_id', 0);
        if ($requestedClassId > 0) {
            $selectedClass = $classes->firstWhere('id', $requestedClassId);

            if (! $selectedClass) {
                abort(404);
            }

            $initialClassFilter = $selectedClass->year && $selectedClass->section
                ? $selectedClass->year.$selectedClass->section
                : $selectedClass->name;
        }

        $studentIds = $students->pluck('id');
        $absenceHours = collect();
        if (! $studentIds->isEmpty()) {
            $reasonRules = AbsenceReason::query()
                ->get()
                ->keyBy(fn (AbsenceReason $reason) => strtolower(trim((string) $reason->name)));
            $absenceHours = Absence::query()
                ->with('medicalCertificates')
                ->whereIn('student_id', $studentIds)
                ->where('status', '!=', Absence::STATUS_DRAFT)
                ->get()
                ->groupBy('student_id')
                ->map(fn ($items) => (int) collect($items)
                    ->filter(fn (Absence $absence) => $absence->resolveCounts40Hours($reasonRules))
                    ->sum('assigned_hours'));
        }
        $delayCounts = $studentIds->isEmpty()
            ? collect()
            : Delay::query()
                ->selectRaw('student_id, COUNT(*) as total')
                ->whereIn('student_id', $studentIds)
                ->groupBy('student_id')
                ->pluck('total', 'student_id');
        $semester = Delay::resolveSemester(Carbon::today());
        $registeredDelayCounts = $studentIds->isEmpty()
            ? collect()
            : Delay::query()
                ->selectRaw('student_id, COUNT(*) as total')
                ->whereIn('student_id', $studentIds)
                ->where('status', Delay::STATUS_REGISTERED)
                ->where('count_in_semester', true)
                ->whereBetween('delay_datetime', [$semester->start, $semester->end])
                ->groupBy('student_id')
                ->pluck('total', 'student_id');
        $statusResolver = app(StudentStatusThresholdResolver::class);
        $statusRules = $statusResolver->teacherThresholds($user);

        $items = $students->map(function (User $student) use (
            $absenceHours,
            $delayCounts,
            $registeredDelayCounts,
            $studentClassMap,
            $statusResolver,
            $statusRules
        ) {
            $assenzeOre = (int) ($absenceHours[$student->id] ?? 0);
            $ritardiTotali = (int) ($delayCounts[$student->id] ?? 0);
            $ritardiRegistratiSemestre = (int) ($registeredDelayCounts[$student->id] ?? 0);
            $statusResolved = $statusResolver->resolveTeacherSplitStatus(
                $assenzeOre,
                $ritardiRegistratiSemestre,
                $statusRules
            );

            return [
                'id' => 'S-'.str_pad((string) $student->id, 3, '0', STR_PAD_LEFT),
                'student_id' => $student->id,
                'nome' => trim($student->name.' '.$student->surname),
                'classe' => $studentClassMap[$student->id] ?? '-',
                'assenze' => $assenzeOre,
                'assenze_ore' => $assenzeOre,
                'ritardi' => $ritardiTotali,
                'ritardi_registrati_semestre' => $ritardiRegistratiSemestre,
                'status_absence_code' => $statusResolved['absence'],
                'status_delay_code' => $statusResolved['delay'],
            ];
        });

        return Inertia::render('Teacher/Students', [
            'items' => $items,
            'initialClassFilter' => $initialClassFilter,
            'statusRules' => $statusRules,
        ]);
    }

    public function history()
    {
        $user = auth()->user();
        [, , $studentClassMap] = $this->getTeacherContext($user);

        $absenceModel = new Absence;
        $delayModel = new Delay;

        $items = collect($this->filterClosedAbsences($absenceModel->getAbsence($user)))
            ->merge($this->filterClosedDelays($delayModel->getDelay($user)))
            ->map(function (array $item) use ($studentClassMap) {
                $item['classe'] = $studentClassMap[$item['student_id']] ?? '-';

                return $item;
            })
            ->sortByDesc('date')
            ->values();

        return Inertia::render('Teacher/History', [
            'items' => $items,
        ]);
    }

    private function getTeacherContext(User $user): array
    {
        $classes = $user->teachingClasses()->with('students')->get();
        $students = $classes
            ->flatMap(fn (SchoolClass $class) => $class->students)
            ->unique('id')
            ->values();

        $studentClassMap = [];

        foreach ($classes as $class) {
            $label = $class->year && $class->section
                ? $class->year.$class->section
                : $class->name;

            foreach ($class->students as $student) {
                $studentClassMap[$student->id][] = $label;
            }
        }

        $studentClassMap = array_map(
            fn (array $labels) => implode(', ', array_unique($labels)),
            $studentClassMap
        );

        return [$classes, $students, $studentClassMap];
    }

    private function filterActionableAbsences($items)
    {
        return collect($items)->filter(function (array $item) {
            $statusCode = Absence::normalizeStatus((string) ($item['stato_code'] ?? ''));

            if ($statusCode === Absence::STATUS_ARBITRARY) {
                return false;
            }

            if ($statusCode === Absence::STATUS_JUSTIFIED) {
                return (bool) ($item['can_accept_certificate'] ?? false)
                    || (bool) ($item['can_reject_certificate'] ?? false);
            }

            return (bool) ($item['can_approve'] ?? false)
                || (bool) ($item['can_approve_without_guardian'] ?? false)
                || (bool) ($item['can_reject'] ?? false)
                || (bool) ($item['can_extend_deadline'] ?? false)
                || (bool) ($item['can_accept_certificate'] ?? false)
                || (bool) ($item['can_reject_certificate'] ?? false)
                || (bool) ($item['can_resend_guardian_email'] ?? false);
        });
    }

    private function filterClosedAbsences($items)
    {
        $actionableAbsenceIds = $this->filterActionableAbsences($items)
            ->pluck('absence_id')
            ->filter()
            ->all();

        return collect($items)->reject(function (array $item) use ($actionableAbsenceIds) {
            $absenceId = $item['absence_id'] ?? null;

            return $absenceId !== null && in_array($absenceId, $actionableAbsenceIds, true);
        });
    }

    private function filterOpenDelays($items)
    {
        return collect($items)->filter(function (array $item) {
            return $this->isOpenDelay($item);
        });
    }

    private function filterClosedDelays($items)
    {
        return collect($items)->reject(function (array $item) {
            return $this->isOpenDelay($item);
        });
    }

    private function isOpenDelay(array $item): bool
    {
        return (bool) ($item['can_approve'] ?? false)
            || (bool) ($item['can_approve_without_guardian'] ?? false)
            || (bool) ($item['can_reject'] ?? false)
            || (bool) ($item['can_resend_guardian_email'] ?? false);
    }

    private function buildAbsenceOperationHistory(Absence $absence)
    {
        return OperationLog::query()
            ->with(['user:id,name,surname'])
            ->where(function ($query) use ($absence) {
                $query
                    ->where(function ($absenceQuery) use ($absence) {
                        $absenceQuery
                            ->where('entity', 'absence')
                            ->where('entity_id', $absence->id);
                    })
                    ->orWhere(function ($certificateQuery) use ($absence) {
                        $certificateQuery
                            ->whereIn('entity', ['medical_certificate', 'guardian_absence_confirmation'])
                            ->where('payload->absence_id', $absence->id);
                    })
                    ->orWhere(function ($leaveQuery) use ($absence) {
                        $leaveQuery
                            ->where('entity', 'leave')
                            ->where('payload->registered_absence_id', $absence->id);
                    });
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function (OperationLog $log) {
                $payload = is_array($log->payload) ? $log->payload : [];

                return [
                    'action' => (string) $log->action,
                    'label' => $this->resolveAbsenceOperationLabel((string) $log->action),
                    'notes' => $this->resolveAbsenceOperationNotes($payload),
                    'decided_at' => $log->created_at?->format('d M Y H:i'),
                    'decided_by' => $this->resolveOperationActor($log->user),
                ];
            })
            ->values();
    }

    private function buildDelayOperationHistory(Delay $delay)
    {
        return OperationLog::query()
            ->with(['user:id,name,surname'])
            ->where(function ($query) use ($delay) {
                $query
                    ->where(function ($delayQuery) use ($delay) {
                        $delayQuery
                            ->where('entity', 'delay')
                            ->where('entity_id', $delay->id);
                    })
                    ->orWhere(function ($notificationQuery) use ($delay) {
                        $notificationQuery
                            ->where('entity', 'delay_email_notification')
                            ->where('payload->delay_id', $delay->id);
                    });
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function (OperationLog $log) {
                $payload = is_array($log->payload) ? $log->payload : [];

                return [
                    'action' => (string) $log->action,
                    'label' => $this->resolveDelayOperationLabel((string) $log->action),
                    'notes' => $this->resolveDelayOperationNotes($payload),
                    'decided_at' => $log->created_at?->format('d M Y H:i'),
                    'decided_by' => $this->resolveOperationActor($log->user),
                ];
            })
            ->values();
    }

    private function resolveAbsenceOperationLabel(string $action): string
    {
        return match ($action) {
            'absence.request.created' => 'Creazione richiesta assenza',
            'absence.updated' => 'Modifica assenza',
            'absence.quick.updated' => 'Aggiornamento rapido assenza',
            'absence.approved' => 'Approvazione assenza',
            'absence.approved_without_guardian' => 'Approvazione senza firma tutore',
            'absence.rejected' => 'Rifiuto assenza',
            'absence.deadline.extended' => 'Proroga scadenza certificato',
            'absence.certificate.uploaded' => 'Caricamento certificato medico',
            'absence.certificate.accepted' => 'Accettazione certificato medico',
            'absence.certificate.rejected' => 'Rifiuto certificato medico',
            'absence.guardian.signature.confirmed' => 'Firma tutore confermata',
            'leave.registered' => 'Congedo registrato',
            'leave.registered_as_absence' => 'Passaggio da congedo ad assenza',
            default => ucfirst(str_replace(['.', '_'], ' ', strtolower($action))),
        };
    }

    private function resolveDelayOperationLabel(string $action): string
    {
        return match ($action) {
            'delay.request.created' => 'Creazione segnalazione ritardo',
            'delay.updated' => 'Modifica ritardo',
            'delay.approved' => 'Giustificazione ritardo',
            'delay.approved_without_guardian' => 'Approvazione ritardo senza firma tutore',
            'delay.rejected' => 'Registrazione ritardo',
            'delay.deadline.extended' => 'Proroga ritardo arbitrario',
            'delay.guardian.signature.confirmed' => 'Firma tutore ritardo confermata',
            'delay.guardian_confirmation_email.sent' => 'Invio email firma tutore ritardo',
            'delay.guardian_confirmation_email.resent' => 'Reinvio email firma tutore ritardo',
            'delay.guardian_confirmation_email.failed' => 'Errore invio email firma tutore ritardo',
            'delay.guardian_confirmation_email.missing_guardian' => 'Tutore mancante per firma ritardo',
            'delay.rule.applied' => 'Applicazione regola ritardi',
            'delay.teacher_notification.failed' => 'Errore notifica docente',
            'delay.rule_notification.failed' => 'Errore notifica regola ritardi',
            default => ucfirst(str_replace(['.', '_'], ' ', strtolower($action))),
        };
    }

    private function resolveAbsenceOperationNotes(array $payload): string
    {
        $comment = trim((string) (
            $payload['comment']
            ?? $payload['teacher_comment']
            ?? $payload['counts_40_hours_comment']
            ?? ''
        ));
        if ($comment !== '') {
            return $comment;
        }

        $previousDeadline = trim((string) ($payload['previous_deadline'] ?? ''));
        $newDeadline = trim((string) ($payload['new_deadline'] ?? ''));
        if ($previousDeadline !== '' || $newDeadline !== '') {
            return 'Scadenza: '.($previousDeadline !== '' ? $previousDeadline : '-')
                .' -> '.($newDeadline !== '' ? $newDeadline : '-');
        }

        $after = $payload['after'] ?? null;
        if (! is_array($after)) {
            return '';
        }

        $parts = [];
        $reason = trim((string) ($after['reason'] ?? ''));
        if ($reason !== '') {
            $parts[] = 'Motivo: '.$reason;
        }
        if (array_key_exists('assigned_hours', $after)) {
            $parts[] = 'Ore: '.(int) $after['assigned_hours'];
        }

        return implode(' | ', $parts);
    }

    private function resolveDelayOperationNotes(array $payload): string
    {
        $comment = trim((string) (
            $payload['comment']
            ?? $payload['teacher_comment']
            ?? ''
        ));
        if ($comment !== '') {
            return $comment;
        }

        $after = $payload['after'] ?? null;
        if (! is_array($after)) {
            return '';
        }

        $parts = [];
        $delayDate = trim((string) ($after['delay_datetime'] ?? ''));
        if ($delayDate !== '') {
            $parts[] = 'Data: '.$delayDate;
        }
        if (array_key_exists('minutes', $after)) {
            $parts[] = 'Minuti: '.(int) $after['minutes'];
        }
        $reason = trim((string) ($after['reason'] ?? ''));
        if ($reason !== '') {
            $parts[] = 'Motivo: '.$reason;
        }

        return implode(' | ', $parts);
    }

    private function resolveOperationActor(?User $user): string
    {
        if (! $user) {
            return 'Sistema';
        }

        $fullName = trim((string) ($user->name ?? '').' '.(string) ($user->surname ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        return trim((string) ($user->name ?? 'Utente'));
    }

    private function resolveFirstSignedGuardianConfirmation(Absence $absence): ?GuardianAbsenceConfirmation
    {
        return $absence->guardianConfirmations
            ->filter(fn (GuardianAbsenceConfirmation $confirmation) => $this->isGuardianConfirmationSigned($confirmation))
            ->sortBy(function (GuardianAbsenceConfirmation $confirmation) {
                $signedAt = $confirmation->confirmed_at ?? $confirmation->signed_at;

                return $signedAt?->timestamp ?? PHP_INT_MAX;
            })
            ->first();
    }

    private function isGuardianConfirmationSigned(GuardianAbsenceConfirmation $confirmation): bool
    {
        $status = strtolower(trim((string) ($confirmation->status ?? '')));

        return in_array($status, ['confirmed', 'approved', 'signed'], true)
            || ! empty($confirmation->confirmed_at)
            || ! empty($confirmation->signed_at);
    }

    private function resolveSignerName(GuardianAbsenceConfirmation $confirmation): string
    {
        $notes = json_decode((string) ($confirmation->notes ?? ''), true);
        $signerName = is_array($notes) ? trim((string) ($notes['signer_name'] ?? '')) : '';

        if ($signerName !== '') {
            return $signerName;
        }

        return trim((string) ($confirmation->guardian?->name ?? '-')) ?: '-';
    }

    private function resolveFirstSignedLeaveConfirmation(\App\Models\Leave $leave): ?\App\Models\GuardianLeaveConfirmation
    {
        return $leave->guardianConfirmations
            ->filter(fn (\App\Models\GuardianLeaveConfirmation $confirmation) => $this->isLeaveConfirmationSigned($confirmation))
            ->sortBy(function (\App\Models\GuardianLeaveConfirmation $confirmation) {
                $signedAt = $confirmation->confirmed_at ?? $confirmation->signed_at;

                return $signedAt?->timestamp ?? PHP_INT_MAX;
            })
            ->first();
    }

    private function isLeaveConfirmationSigned(\App\Models\GuardianLeaveConfirmation $confirmation): bool
    {
        $status = strtolower(trim((string) ($confirmation->status ?? '')));

        return in_array($status, ['confirmed', 'approved', 'signed'], true)
            || ! empty($confirmation->confirmed_at)
            || ! empty($confirmation->signed_at);
    }

    private function resolveLeaveSignerName(\App\Models\GuardianLeaveConfirmation $confirmation): string
    {
        $notes = json_decode((string) ($confirmation->notes ?? ''), true);
        $signerName = is_array($notes) ? trim((string) ($notes['signer_name'] ?? '')) : '';

        if ($signerName !== '') {
            return $signerName;
        }

        return trim((string) ($confirmation->guardian?->name ?? '-')) ?: '-';
    }
}
