<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\AbsenceSetting;
use App\Models\Delay;
use App\Models\Leave;
use App\Models\MedicalCertificate;
use App\Services\LeaveAbsenceDraftService;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;

class DashboardStudentController extends BaseController
{
    public function __construct()
    {
        $this->middleware('student');
    }

    public function index()
    {
        $user = auth()->user();
        $this->registerDueLeaveDraftsForStudent((int) $user->id);

        $absence = new Absence;
        $delay = new Delay;
        $leave = new Leave;
        $absenceSetting = AbsenceSetting::query()->firstOrFail();

        // Richieste aperte da mostrare in dashboard (le chiuse vanno nello storico)
        $assenze = collect($absence->getAbsence($user))
            ->filter(function (array $item) {
                $statusCode = Absence::normalizeStatus((string) ($item['stato_code'] ?? ''));
                $isDraftFromLeave = $statusCode === Absence::STATUS_DRAFT
                    && ! empty($item['derivata_da_congedo']);
                $certificateCode = strtolower(trim((string) ($item['certificato_obbligo_code'] ?? '')));
                $requiresCertificateUpload = in_array(
                    $certificateCode,
                    ['required_pending'],
                    true
                );

                return in_array(
                    $statusCode,
                    Absence::openStatuses(),
                    true
                ) || $isDraftFromLeave || $requiresCertificateUpload;
            });
        $ritardi = collect($delay->getDelay($user))
            ->filter(function (array $item) {
                return Delay::normalizeStatus((string) ($item['stato_code'] ?? ''))
                    === Delay::STATUS_REPORTED;
            });
        $congedi = collect($leave->getLeave($user))
            ->filter(function (array $item) {
                $statusCode = Leave::normalizeStatus((string) ($item['stato_code'] ?? ''));

                return in_array($statusCode, Leave::openStatuses(), true);
            });

        $certificateReminderCollection = $assenze
            ->filter(function (array $item) {
                $certificateCode = strtolower(trim((string) ($item['certificato_obbligo_code'] ?? '')));

                return in_array($certificateCode, ['required_pending'], true);
            })
            ->values();

        $certificatePriorityKeys = $certificateReminderCollection
            ->map(function (array $item) {
                return ($item['tipo'] ?? '').':'.($item['id'] ?? '');
            })
            ->values();

        $otherItems = $assenze
            ->merge($ritardi)
            ->merge($congedi)
            ->reject(function (array $item) use ($certificatePriorityKeys) {
                $itemKey = ($item['tipo'] ?? '').':'.($item['id'] ?? '');

                return $certificatePriorityKeys->contains($itemKey);
            })
            ->sortByDesc('date');

        $items = $certificateReminderCollection
            ->sortByDesc('date')
            ->merge($otherItems)
            ->take(8)
            ->values();

        $certificateReminderItems = $certificateReminderCollection->all();

        $oreAssenzaTotali = (int) Absence::query()
            ->where('student_id', $user->id)
            ->where('status', '!=', Absence::STATUS_DRAFT)
            ->sum('assigned_hours');
        $oreAssenzaConteggio40 = Absence::countHoursForStudent($user->id);

        // label parte ore mese +
        $oreAssenzaMese = (int) Absence::query()
            ->where('student_id', $user->id)
            ->where('status', '!=', Absence::STATUS_DRAFT)
            ->whereBetween('start_date', [
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString(),
            ])
            ->sum('assigned_hours');

        // Azioni richieste per studente (bozze assenza, congedi aperti/inoltrati, ritardi aperti).
        $azioniRichiesteAssenze = Absence::query()
            ->where('student_id', $user->id)
            ->where(function ($query) {
                $query
                    ->whereIn('status', Absence::openStatuses())
                    ->orWhere(function ($draftQuery) {
                        $draftQuery
                            ->where('status', Absence::STATUS_DRAFT)
                            ->whereNotNull('derived_from_leave_id');
                    });
            })
            ->count();
        $azioniRichiesteCongedi = Leave::query()
            ->where('student_id', $user->id)
            ->whereIn('status', Leave::openStatuses())
            ->count();
        $azioniRichiesteRitardi = Delay::query()
            ->where('student_id', $user->id)
            ->where('status', Delay::STATUS_REPORTED)
            ->count();
        $azioniRichieste = $azioniRichiesteAssenze + $azioniRichiesteCongedi + $azioniRichiesteRitardi;
        $ritardiTotali = Delay::query()
            ->where('student_id', $user->id)
            ->count();
        $maxAnnualHours = (int) $absenceSetting->max_annual_hours;
        $oreDisponibili = max($maxAnnualHours - $oreAssenzaConteggio40, 0);

        $stats = [
            [
                'label' => 'Ore di assenza',
                'value' => (string) $oreAssenzaTotali,
                'helper' => '+'.$oreAssenzaMese.' questo mese',
            ],
            [
                'label' => 'Ore disponibili',
                'value' => $oreDisponibili.' / '.$maxAnnualHours,
                'helper' => 'Monte ore annuale residuo',
            ],
            [
                'label' => 'Azioni richieste',
                'value' => (string) $azioniRichieste,
                'helper' => 'Operazioni ancora da completare',
            ],
            [
                'label' => 'Ritardi',
                'value' => (string) $ritardiTotali,
                'helper' => 'Totale registrati',
            ],
        ];

        return Inertia::render('Dashboard/Student', [
            'assenze' => $items,
            'certificateReminderItems' => $certificateReminderItems,
            'stats' => $stats,
        ]);
    }

    public function History()
    {
        $user = auth()->user();
        $this->registerDueLeaveDraftsForStudent((int) $user->id);

        $leave = new Leave;

        $absence = new Absence;

        $delay = new Delay;

        $assenze = $absence->getAbsence($user);

        $ritardi = $delay->getDelay($user);

        $congedi = $leave->getLeave($user);

        $items = collect($assenze)
            ->merge(collect($ritardi))
            ->merge(collect($congedi))
            ->sortByDesc('date')
            ->values()
            ->all();

        return Inertia::render('Student/History', [
            'items' => $items,
        ]);

    }

    public function DocumentManagemnt()
    {
        $user = auth()->user();
        $this->registerDueLeaveDraftsForStudent((int) $user->id);

        $certificateModel = new MedicalCertificate;
        $leaveModel = new Leave;

        $certificates = $certificateModel->getMedicalCertificateItems($user);
        $leaves = $leaveModel->getLeaveDocuments($user);

        $documents = collect($certificates)
            ->merge(collect($leaves))
            ->sortByDesc('sort_date')
            ->values()
            ->map(function (array $item) {
                unset($item['sort_date']);

                return $item;
            });

        $uploadableAbsenceStatuses = array_values(array_unique(array_merge(
            Absence::openStatuses(),
            [Absence::STATUS_JUSTIFIED, 'approved']
        )));

        $absenceOptions = Absence::query()
            ->where('student_id', $user->id)
            ->whereIn('status', $uploadableAbsenceStatuses)
            ->whereNotNull('medical_certificate_deadline')
            ->whereDate('medical_certificate_deadline', '>=', Carbon::today()->toDateString())
            ->whereDoesntHave('medicalCertificates')
            ->orderByDesc('start_date')
            ->with('medicalCertificates')
            ->get()
            ->map(function (Absence $absence) {
                $deadline = Carbon::parse($absence->medical_certificate_deadline)->startOfDay();
                $startDate = Carbon::parse($absence->start_date)->startOfDay();
                $endDate = $absence->end_date
                    ? Carbon::parse($absence->end_date)->startOfDay()
                    : $startDate->copy();
                $absenceDayLabel = $startDate->isSameDay($endDate)
                    ? $startDate->format('d M Y')
                    : $startDate->format('d M Y').' - '.$endDate->format('d M Y');
                $daysLeft = Absence::businessDaysUntil(Carbon::today(), $deadline);
                $countdown = match (true) {
                    $daysLeft > 1 => $daysLeft.' giorni lavorativi',
                    $daysLeft === 1 => '1 giorno lavorativo',
                    $daysLeft === 0 => 'Scade oggi',
                    default => 'Scaduta da '.abs($daysLeft).' giorni lavorativi',
                };
                $certificateRequirement = $absence->resolveCertificateRequirementStatus();

                return [
                    'id' => $absence->id,
                    'label' => 'A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
                    'giorno_assenza' => $absenceDayLabel,
                    'scadenza' => $deadline->format('d M Y'),
                    'countdown' => $countdown,
                    'richiede_certificato' => $absence->resolveMedicalCertificateRequired(),
                    'certificato_obbligo_code' => $certificateRequirement['code'],
                    'certificato_obbligo' => $certificateRequirement['label'],
                    'certificato_obbligo_short' => $certificateRequirement['short_label'],
                    'certificato_obbligo_badge' => $certificateRequirement['badge'],
                    'certificate_rejection_comment' => (string) ($absence->certificate_rejection_comment ?? ''),
                ];
            })
            ->values();

        $leaveOptions = Leave::query()
            ->where('student_id', $user->id)
            ->whereIn('status', Leave::openStatuses())
            ->orderByDesc('created_at_custom')
            ->get()
            ->map(function (Leave $leave) {
                $statusCode = Leave::normalizeStatus($leave->status);
                $documentationComment = trim((string) ($leave->documentation_request_comment ?? ''));

                return [
                    'id' => $leave->id,
                    'label' => 'C-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
                    'motivo' => (string) ($leave->reason ?? '-'),
                    'commento' => $documentationComment,
                    'stato' => Leave::statusLabel($statusCode),
                    'stato_code' => $statusCode,
                    'documentazione_presente' => ! empty($leave->documentation_path),
                ];
            })
            ->values();

        $uploadOptions = collect($absenceOptions)
            ->map(function (array $absence) {
                return [
                    'key' => 'absence:'.$absence['id'],
                    'type' => 'absence',
                    'target_id' => $absence['id'],
                    'label' => $absence['label'],
                    'subtitle' => 'Assenza del '.$absence['giorno_assenza'],
                    'status' => $absence['certificato_obbligo_short'] ?? 'Non richiesto',
                    'comment' => (string) ($absence['certificate_rejection_comment'] ?? ''),
                ];
            })
            ->merge(
                collect($leaveOptions)->map(function (array $leave) {
                    return [
                        'key' => 'leave:'.$leave['id'],
                        'type' => 'leave',
                        'target_id' => $leave['id'],
                        'label' => $leave['label'],
                        'subtitle' => 'Congedo - '.$leave['stato'],
                        'status' => 'Documentazione / scansione',
                        'comment' => (string) ($leave['commento'] ?? ''),
                    ];
                })
            )
            ->values();

        return Inertia::render('Student/Documents', [
            'documents' => $documents,
            'absenceOptions' => $absenceOptions,
            'leaveOptions' => $leaveOptions,
            'uploadOptions' => $uploadOptions,
        ]);
    }

    private function registerDueLeaveDraftsForStudent(int $studentId): void
    {
        app(LeaveAbsenceDraftService::class)->registerDueLeaves($studentId);
    }
}
