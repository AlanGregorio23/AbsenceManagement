<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\Delay;
use App\Models\Leave;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\AnnualHoursLimitLabels;
use App\Support\StudentStatusThresholdResolver;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;

class DashboardLaboratoryManagerController extends BaseController
{
    public function __construct()
    {
        $this->middleware('laboratory_manager');
    }

    public function index()
    {
        $leaves = $this->getLeaveItems();
        $open = $this->filterOpenLeaves($leaves)->values();

        $pendingCount = $leaves
            ->whereIn('stato_code', [
                Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE,
                Leave::STATUS_SIGNED,
                Leave::STATUS_IN_REVIEW,
            ])
            ->count();
        $waitingSignatureCount = $leaves
            ->where('stato_code', Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE)
            ->count();
        $preApprovedCount = $leaves
            ->where('stato_code', Leave::STATUS_PRE_APPROVED)
            ->count();
        $docRequestCount = $leaves
            ->where('stato_code', Leave::STATUS_DOCUMENTATION_REQUESTED)
            ->count();
        $stats = [
            [
                'label' => 'Richieste da valutare',
                'value' => (string) $pendingCount,
                'helper' => 'Settimana',
            ],
            [
                'label' => 'In attesa firma',
                'value' => (string) $waitingSignatureCount,
                'helper' => 'Tutore',
            ],
            [
                'label' => 'Override firma',
                'value' => (string) $preApprovedCount,
                'helper' => 'Senza blocchi',
            ],
            [
                'label' => 'Doc richiesta',
                'value' => (string) $docRequestCount,
                'helper' => 'Intervento richiesto',
            ],
        ];

        $rows = $open
            ->sortByDesc('date')
            ->take(8)
            ->values()
            ->map(function (array $item) {
                return [
                    'id' => $item['id'],
                    'leave_id' => $item['leave_id'],
                    'student_id' => $item['student_id'] ?? null,
                    'studente' => $item['studente'],
                    'classe' => $item['classe'],
                    'periodo' => $item['periodo'],
                    'motivo' => $item['motivo'] ?? '-',
                    'destinazione' => (string) ($item['destinazione'] ?? $item['destination'] ?? ''),
                    'firma_tutore_label' => $item['firma_tutore_label'] ?? '-',
                    'conteggio_40_ore' => (bool) ($item['conteggio_40_ore'] ?? true),
                    'conteggio_40_ore_label' => (string) ($item['conteggio_40_ore_label']
                        ?? ((bool) ($item['conteggio_40_ore'] ?? true)
                            ? AnnualHoursLimitLabels::included()
                            : AnnualHoursLimitLabels::excluded())),
                    'stato' => $item['stato'],
                    'badge' => $item['badge'],
                    'data' => $item['data'],
                    'richiesta_inviata_il' => (string) ($item['richiesta_inviata_il'] ?? ''),
                    'richiesta_tardiva' => (bool) ($item['richiesta_tardiva'] ?? false),
                    'richiesta_tardiva_label' => (string) ($item['richiesta_tardiva_label'] ?? ''),
                    'can_pre_approve' => (bool) ($item['can_pre_approve'] ?? false),
                    'can_approve' => (bool) ($item['can_approve'] ?? false),
                    'can_request_documentation' => (bool) ($item['can_request_documentation'] ?? false),
                    'can_reject_documentation' => (bool) ($item['can_reject_documentation'] ?? false),
                    'can_reject' => (bool) ($item['can_reject'] ?? false),
                    'can_edit' => (bool) ($item['can_edit'] ?? false),
                    'can_delete' => (bool) ($item['can_delete'] ?? false),
                    'can_forward_to_management' => (bool) ($item['can_forward_to_management'] ?? false),
                ];
            });

        return Inertia::render('Dashboard/LaboratoryManager', [
            'stats' => $stats,
            'rows' => $rows,
        ]);
    }

    public function leaves()
    {
        $items = $this->filterOpenLeaves($this->getLeaveItems())->values();

        return Inertia::render('LaboratoryManager/Leaves', [
            'items' => $items,
        ]);
    }

    public function history()
    {
        $items = $this->filterClosedLeaves($this->getLeaveItems())->values();

        return Inertia::render('LaboratoryManager/History', [
            'items' => $items,
        ]);
    }

    public function students()
    {
        $viewer = auth()->user();
        $students = User::query()
            ->where('role', 'student')
            ->orderBy('surname')
            ->orderBy('name')
            ->get();
        $studentIds = $students->pluck('id');

        $leaveCounts = $studentIds->isEmpty()
            ? collect()
            : Leave::query()
                ->selectRaw('student_id, COUNT(*) as total')
                ->whereIn('student_id', $studentIds)
                ->groupBy('student_id')
                ->pluck('total', 'student_id');
        $leaveHours = $studentIds->isEmpty()
            ? collect()
            : Leave::query()
                ->selectRaw('student_id, COALESCE(SUM(requested_hours), 0) as total')
                ->whereIn('student_id', $studentIds)
                ->where('count_hours', true)
                ->where('status', '!=', Leave::STATUS_REJECTED)
                ->groupBy('student_id')
                ->pluck('total', 'student_id');
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

        $studentClassMap = $this->getStudentClassMap();
        $statusResolver = app(StudentStatusThresholdResolver::class);
        $statusRules = $statusResolver->teacherThresholds($viewer);

        $items = $students->map(function (User $student) use (
            $leaveCounts,
            $leaveHours,
            $absenceHours,
            $delayCounts,
            $registeredDelayCounts,
            $studentClassMap,
            $statusResolver,
            $statusRules
        ) {
            $congedi = (int) ($leaveCounts[$student->id] ?? 0);
            $oreCongedo = (int) ($leaveHours[$student->id] ?? 0);
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
                'email' => $student->email,
                'congedi' => $congedi,
                'congedi_ore' => $oreCongedo,
                'assenze_ore' => $assenzeOre,
                'ritardi' => $ritardiTotali,
                'ritardi_registrati_semestre' => $ritardiRegistratiSemestre,
                'status_absence_code' => $statusResolved['absence'],
                'status_delay_code' => $statusResolved['delay'],
            ];
        });

        return Inertia::render('LaboratoryManager/Students', [
            'items' => $items,
            'statusRules' => $statusRules,
        ]);
    }

    private function getLeaveItems()
    {
        $leaveModel = new Leave;
        $items = $leaveModel->getLeave(auth()->user());
        $studentClassMap = $this->getStudentClassMap();

        return collect($items)->map(function (array $item) use ($studentClassMap) {
            $item['classe'] = $studentClassMap[$item['student_id']] ?? '-';

            return $item;
        });
    }

    private function filterOpenLeaves($items)
    {
        $openStatuses = $this->getOpenStatuses();

        return collect($items)->filter(function (array $item) use ($openStatuses) {
            return in_array($item['stato_code'] ?? '', $openStatuses, true);
        });
    }

    private function filterClosedLeaves($items)
    {
        $openStatuses = $this->getOpenStatuses();

        return collect($items)->filter(function (array $item) use ($openStatuses) {
            return ! in_array($item['stato_code'] ?? '', $openStatuses, true);
        });
    }

    private function getStudentClassMap(): array
    {
        $classes = SchoolClass::query()->with('students')->get();
        $map = [];

        foreach ($classes as $class) {
            $label = $class->name;

            foreach ($class->students as $student) {
                $map[$student->id][] = $label;
            }
        }

        return array_map(
            fn (array $labels) => implode(', ', array_unique($labels)),
            $map
        );
    }

    private function getOpenStatuses(): array
    {
        return Leave::openStatuses();
    }
}
