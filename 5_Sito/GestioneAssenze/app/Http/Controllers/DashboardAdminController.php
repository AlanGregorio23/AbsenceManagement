<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddManualUserRequest;
use App\Http\Requests\AddUserCsvRequest;
use App\Http\Requests\AssignStudentGuardianRequest;
use App\Http\Requests\UpdateManagedUserRequest;
use App\Http\Requests\UpdateSchoolClassRequest;
use App\Models\AdminSettings;
use App\Models\Guardian;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\InactiveGuardianNotificationResolver;
use App\Services\PasswordSetupService;
use App\Support\NotificationTypeRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardAdminController extends BaseController
{
    private const LOG_EXPORT_MAX_ROWS = 200000;

    private const GAGI_CSV_FORMAT_ERROR = 'Il file non e un CSV esportato da Gagi nel formato atteso.';

    private const MANAGED_USER_ROLES = [
        'student',
        'teacher',
        'laboratory_manager',
        'admin',
    ];

    public function __construct()
    {
        $this->middleware('admin');
    }

    public function index()
    {

        $class = new SchoolClass;

        $users = User::query()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'surname', 'email', 'role'])
            ->map(function (User $user) {
                return [
                    'user_id' => $user->id,
                    'nome' => $user->name,
                    'cognome' => $user->surname,
                    'email' => $user->email,
                    'ruolo' => $this->translateUserRole((string) $user->role),
                ];
            })
            ->values();

        $utentiTotali = User::query()
            ->count();

        $docentiTotali = User::query()
            ->where('role', 'teacher')
            ->count();

        $classes = $class->getClasses();

        $classiTotali = SchoolClass::query()
            ->count();

        $log = new OperationLog;

        $logs = $log->getLog(['ERROR', 'WARNING'], 3);

        $TotError = OperationLog::query()
            ->where('level', 'ERROR')
            ->count();

        $stats = [
            [
                'label' => 'Utenti totali',
                'value' => (string) $utentiTotali,
            ],
            [
                'label' => 'Classi totali',
                'value' => (string) $classiTotali,
            ],
            [
                'label' => 'Docenti totali',
                'value' => (string) $docentiTotali,
            ],
            [
                'label' => 'Errori critici',
                'value' => (string) $TotError,
            ],
        ];

        return Inertia::render('Dashboard/Admin', [
            'utenti' => $users,
            'classi' => $classes,
            'stats' => $stats,
            'logs' => $logs,
            'settings' => AdminSettings::forEdit(),
        ]);
    }

    public function UserManagement(Request $request)
    {

        $search = trim((string) $request->query('query', ''));
        $roleFilter = trim((string) $request->query('role', ''));
        $classFilter = trim((string) $request->query('class', ''));

        $usersQuery = User::query()
            ->with([
                'classes' => function ($query) {
                    $query->orderByPivot('start_date', 'desc');
                },
                'allGuardians' => function ($query) {
                    $query
                        ->orderByRaw('CASE WHEN guardian_student.is_active = 1 THEN 0 ELSE 1 END')
                        ->orderBy('guardians.name');
                },
                'notificationPreferences' => function ($query) {
                    $query->where('event_key', InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY);
                },
            ])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $searchLike = '%'.strtolower($search).'%';
            $usersQuery->where(function ($query) use ($searchLike) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(surname) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$searchLike]);
            });
        }

        if ($roleFilter !== '') {
            $usersQuery->where('role', $roleFilter);
        }

        if ($classFilter !== '') {
            $classFilterLike = '%'.strtolower($classFilter).'%';
            $parsedClassFilter = $this->parseClassCode($classFilter);

            $usersQuery->whereHas('classes', function ($query) use ($classFilterLike, $parsedClassFilter) {
                $query->where(function ($classQuery) use ($classFilterLike, $parsedClassFilter) {
                    $classQuery
                        ->whereRaw('LOWER(classes.name) LIKE ?', [$classFilterLike])
                        ->orWhereRaw('LOWER(COALESCE(classes.section, \'\')) LIKE ?', [$classFilterLike])
                        ->orWhereRaw('LOWER(COALESCE(classes.year, \'\')) LIKE ?', [$classFilterLike]);

                    if ($parsedClassFilter !== null) {
                        $classQuery->orWhere(function ($parsedQuery) use ($parsedClassFilter) {
                            $parsedQuery
                                ->whereRaw('LOWER(COALESCE(classes.section, \'\')) = ?', [strtolower((string) $parsedClassFilter['section'])])
                                ->where('classes.year', (string) $parsedClassFilter['year'])
                                ->whereRaw('LOWER(COALESCE(classes.name, \'\')) = ?', [strtolower((string) $parsedClassFilter['name'])]);
                        });
                    }
                });
            });
        }

        $paginator = $usersQuery
            ->simplePaginate(50)
            ->withQueryString();

        $users = collect($paginator->items())
            ->map(function (User $user) {
                $className = $user->classes->first();
                $role = $user->role ?? 'student';
                $label = $this->translateUserRole($role);
                $state = (bool) $user->active;
                $stato = $state ? 'Attivo' : 'Inattivo';
                $guardians = $user->allGuardians
                    ->map(function (Guardian $guardian) {
                        return [
                            'id' => $guardian->id,
                            'name' => $guardian->name,
                            'email' => $guardian->email,
                            'relationship' => $guardian->pivot?->relationship,
                            'is_primary' => (bool) ($guardian->pivot?->is_primary ?? false),
                            'is_active' => (bool) ($guardian->pivot?->is_active ?? true),
                            'deactivated_at' => $guardian->pivot?->deactivated_at
                                ? Carbon::parse($guardian->pivot->deactivated_at)->toIso8601String()
                                : null,
                        ];
                    })
                    ->values();
                $activeGuardians = $guardians->where('is_active', true)->values();
                $inactiveGuardians = $guardians->where('is_active', false)->values();
                $guardianContact = $activeGuardians
                    ->firstWhere('is_primary', true)
                    ?? $activeGuardians->first();
                $storedPreference = $user->notificationPreferences
                    ->firstWhere('event_key', InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY)
                    ?->email_enabled;
                $notifyPreviousGuardiansEnabled = $user->hasRole('student')
                    ? ($storedPreference !== null
                        ? (bool) $storedPreference
                        : NotificationTypeRegistry::defaultEmailEnabled(
                            $user->role,
                            InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY
                        ))
                    : false;

                return [
                    'id' => 'U-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                    'user_id' => $user->id,
                    'nome' => $user->name,
                    'cognome' => $user->surname,
                    'email' => $user->email,
                    'birth_date' => $user->birth_date?->toDateString(),
                    'ruolo_code' => $role,
                    'ruolo' => $label,
                    'classe' => $className?->class_code ?: '-',
                    'stato' => $stato,
                    'tutore_legale' => $guardianContact ? [
                        'id' => $guardianContact['id'],
                        'name' => $guardianContact['name'],
                        'email' => $guardianContact['email'],
                        'relationship' => $guardianContact['relationship'],
                    ] : null,
                    'tutori' => $guardians,
                    'tutori_attivi' => $activeGuardians->count(),
                    'tutori_inattivi' => $inactiveGuardians->count(),
                    'notify_previous_guardians_enabled' => $notifyPreviousGuardiansEnabled,
                    'is_adult' => $user->isAdult(),
                    'creato_il' => $user->created_at?->format('d M Y'),
                ];
            })
            ->values();

        $currentPage = $paginator->currentPage();
        $from = $users->isEmpty() ? null : (($currentPage - 1) * $paginator->perPage()) + 1;
        $to = $users->isEmpty() ? null : $from + $users->count() - 1;

        $roleOptions = $this->managedUserRoleOptions();

        $classOptions = SchoolClass::query()
            ->select('name')
            ->distinct()
            ->orderBy('name')
            ->pluck('name')
            ->values();

        return Inertia::render('Admin/Users', [
            'utenti' => $users,
            'pagination' => [
                'current_page' => $currentPage,
                'per_page' => $paginator->perPage(),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'from' => $from,
                'to' => $to,
            ],
            'filters' => [
                'query' => $search,
                'role' => $roleFilter,
                'class' => $classFilter,
            ],
            'roleOptions' => $roleOptions,
            'classOptions' => $classOptions,
        ]);

    }

    public function updateManagedUser(
        UpdateManagedUserRequest $request,
        User $user
    ) {
        $validated = $request->validated();

        if ((int) $request->user()?->id === (int) $user->id && $user->role === 'admin' && $validated['role'] !== 'admin') {
            return back()->withErrors([
                'role' => 'Non puoi rimuovere il tuo ruolo admin mentre sei autenticato.',
            ]);
        }

        if ($user->role === 'admin' && $validated['role'] !== 'admin' && $this->isLastAdmin($user)) {
            return back()->withErrors([
                'role' => 'Serve almeno un amministratore nel sistema.',
            ]);
        }

        if (
            (int) $request->user()?->id === (int) $user->id
            && strtolower((string) $validated['email']) !== strtolower((string) $user->email)
        ) {
            return back()->withErrors([
                'email' => 'Non puoi cambiare la tua email: deve farlo un altro admin.',
            ]);
        }

        $before = [
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'role' => $user->role,
            'birth_date' => $user->birth_date?->toDateString(),
        ];

        $user->fill([
            'name' => $validated['name'],
            'surname' => $validated['surname'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'birth_date' => $validated['birth_date'] ?? null,
        ]);

        if (! $user->isDirty(['name', 'surname', 'email', 'role', 'birth_date'])) {
            return back()->with('success', 'Nessuna modifica da salvare.');
        }

        $user->save();

        OperationLog::record(
            $request->user(),
            'admin.user.updated',
            'user',
            $user->id,
            [
                'before' => $before,
                'after' => [
                    'name' => $user->name,
                    'surname' => $user->surname,
                    'email' => $user->email,
                    'role' => $user->role,
                    'birth_date' => $user->birth_date?->toDateString(),
                ],
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Utente aggiornato con successo.');
    }

    public function sendManagedUserPasswordReset(
        Request $request,
        User $user
    ) {
        $status = Password::broker()->sendResetLink([
            'email' => $user->email,
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors([
                'user' => trans($status, [], 'it'),
            ]);
        }

        OperationLog::record(
            $request->user(),
            'admin.user.password_reset.sent',
            'user',
            $user->id,
            [
                'email' => $user->email,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Email reset password inviata.');
    }

    public function destroyManagedUser(
        Request $request,
        User $user
    ) {
        if ((int) $request->user()?->id === (int) $user->id) {
            return back()->withErrors([
                'user' => 'Non puoi eliminare il tuo account mentre sei autenticato.',
            ]);
        }

        if ($user->role === 'admin' && $this->isLastAdmin($user)) {
            return back()->withErrors([
                'user' => 'Serve almeno un amministratore nel sistema.',
            ]);
        }

        $deletedUserSnapshot = [
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'role' => $user->role,
            'active' => (bool) $user->active,
        ];

        DB::transaction(function () use ($request, $user, $deletedUserSnapshot) {
            $user->delete();

            OperationLog::record(
                $request->user(),
                'admin.user.deleted',
                'user',
                $user->id,
                [
                    'deleted_user' => $deletedUserSnapshot,
                ],
                'INFO',
                $request
            );
        });

        return back()->with('success', 'Utente eliminato con successo.');
    }

    public function toggleManagedUserActive(
        Request $request,
        User $user
    ) {
        if ((int) $request->user()?->id === (int) $user->id && (bool) $user->active) {
            return back()->withErrors([
                'user' => 'Non puoi disattivare il tuo account mentre sei autenticato.',
            ]);
        }

        $newState = ! (bool) $user->active;
        $user->forceFill([
            'active' => $newState,
        ])->save();

        OperationLog::record(
            $request->user(),
            $newState ? 'admin.user.activated' : 'admin.user.deactivated',
            'user',
            $user->id,
            [
                'active' => $newState,
            ],
            'INFO',
            $request
        );

        return back()->with('success', $newState
            ? 'Utente attivato con successo.'
            : 'Utente disattivato con successo.');
    }

    public function assignGuardianToStudent(
        AssignStudentGuardianRequest $request,
        User $student
    ) {
        if (! $student->hasRole('student')) {
            return back()->withErrors([
                'guardian_email' => 'Il tutore puo essere associato solo a utenti studente.',
            ]);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $student, $request) {
            $guardian = Guardian::query()->firstOrNew([
                'email' => $validated['guardian_email'],
            ]);

            $guardian->name = trim((string) $validated['guardian_name']);
            $guardian->save();

            $now = now();
            $pivotAttributes = [
                'relationship' => $validated['relationship'] !== ''
                    ? $validated['relationship']
                    : null,
                'is_primary' => false,
                'is_active' => true,
                'deactivated_at' => null,
                'updated_at' => $now,
            ];

            $existingPivot = DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $guardian->id)
                ->first();

            if ($existingPivot) {
                DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', $guardian->id)
                    ->update($pivotAttributes);
            } else {
                DB::table('guardian_student')->insert([
                    ...$pivotAttributes,
                    'guardian_id' => $guardian->id,
                    'student_id' => $student->id,
                    'created_at' => $now,
                ]);
            }

            OperationLog::record(
                $request->user(),
                'admin.student.guardian.assigned',
                'student',
                $student->id,
                [
                    'guardian_id' => $guardian->id,
                    'guardian_email' => $guardian->email,
                    'relationship' => $validated['relationship'] ?: null,
                ],
                'INFO',
                $request
            );
        });

        return back()->with('success', 'Tutore assegnato con successo.');
    }

    public function removeGuardianFromStudent(
        Request $request,
        User $student,
        Guardian $guardian
    ) {
        if (! $student->hasRole('student')) {
            return back()->withErrors([
                'guardian_email' => 'Il tutore puo essere rimosso solo da utenti studente.',
            ]);
        }

        $pivotRow = DB::table('guardian_student')
            ->where('student_id', $student->id)
            ->where('guardian_id', $guardian->id)
            ->first();

        if (! $pivotRow) {
            return back()->withErrors([
                'guardian_email' => 'Il tutore selezionato non e associato a questo studente.',
            ]);
        }

        DB::transaction(function () use ($request, $student, $guardian) {
            DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $guardian->id)
                ->update([
                    'is_active' => false,
                    'is_primary' => false,
                    'deactivated_at' => now(),
                    'updated_at' => now(),
                ]);

            OperationLog::record(
                $request->user(),
                'admin.student.guardian.removed',
                'student',
                $student->id,
                [
                    'guardian_id' => $guardian->id,
                    'guardian_email' => $guardian->email,
                ],
                'INFO',
                $request
            );
        });

        return back()->with('success', 'Tutore rimosso con successo.');
    }

    public function ClassesManagement(Request $request)
    {
        $search = trim((string) $request->query('query', ''));
        $yearFilter = trim((string) $request->query('year', ''));
        $teacherFilter = trim((string) $request->query('teacher', ''));

        $classesQuery = SchoolClass::query()
            ->with([
                'teachers' => function ($query) {
                    $query->orderBy('name')
                        ->orderBy('surname')
                        ->orderByPivot('start_date', 'desc');
                },
                'students' => function ($query) {
                    $query->orderBy('name')
                        ->orderBy('surname');
                },
            ])
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $searchLike = '%'.strtolower($search).'%';
            $classesQuery->where(function ($query) use ($searchLike) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(section) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(year) LIKE ?', [$searchLike])
                    ->orWhereHas('teachers', function ($teacherQuery) use ($searchLike) {
                        $teacherQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$searchLike])
                            ->orWhereRaw('LOWER(surname) LIKE ?', [$searchLike]);
                    });
            });
        }

        if ($yearFilter !== '') {
            $classesQuery->where('year', $yearFilter);
        }

        if ($teacherFilter !== '') {
            $teacherTerms = collect(preg_split('/\s+/', Str::lower($teacherFilter)) ?: [])
                ->filter();

            $classesQuery->whereHas('teachers', function ($query) use ($teacherTerms) {
                $teacherTerms->each(function ($term) use ($query) {
                    $searchLike = '%'.$term.'%';

                    $query->where(function ($teacherQuery) use ($searchLike) {
                        $teacherQuery
                            ->whereRaw('LOWER(users.name) LIKE ?', [$searchLike])
                            ->orWhereRaw('LOWER(users.surname) LIKE ?', [$searchLike]);
                    });
                });
            });
        }

        $paginator = $classesQuery
            ->simplePaginate(50)
            ->withQueryString();

        $classes = collect($paginator->items())
            ->map(function (SchoolClass $class) {
                $teacher = $class->teachers
                    ->sortByDesc(fn (User $candidate) => $candidate->pivot?->start_date)
                    ->first();

                return [
                    'id' => 'C-'.str_pad((string) $class->id, 4, '0', STR_PAD_LEFT),
                    'class_id' => $class->id,
                    'nome' => $class->name,
                    'teacher_id' => $teacher?->id,
                    'docente' => $teacher ? trim($teacher->name.' '.$teacher->surname) : 'nessun docente assegnato',
                    'anno' => $class->year,
                    'studenti' => $class->students->count(),
                    'sezione' => $class->section,
                    'creato_il' => $class->created_at?->format('d M Y'),
                    'teacher_ids' => $class->teachers->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all(),
                    'student_ids' => $class->students->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        $currentPage = $paginator->currentPage();
        $from = $classes->isEmpty() ? null : (($currentPage - 1) * $paginator->perPage()) + 1;
        $to = $classes->isEmpty() ? null : $from + $classes->count() - 1;

        $teachers = User::query()
            ->where('role', 'teacher')
            ->orderBy('surname')
            ->orderBy('name')
            ->get()
            ->map(function (User $teacher) {
                return [
                    'id' => $teacher->id,
                    'label' => trim($teacher->name.' '.$teacher->surname),
                    'email' => $teacher->email,
                ];
            })
            ->values();

        $students = User::query()
            ->where('role', 'student')
            ->orderBy('surname')
            ->orderBy('name')
            ->get()
            ->map(function (User $student) {
                return [
                    'id' => $student->id,
                    'label' => trim($student->name.' '.$student->surname),
                    'email' => $student->email,
                ];
            })
            ->values();

        $years = SchoolClass::query()
            ->select('year')
            ->whereNotNull('year')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->map(fn ($year) => (string) $year)
            ->values();

        return Inertia::render('Admin/Classes', [
            'classi' => $classes,
            'pagination' => [
                'current_page' => $currentPage,
                'per_page' => $paginator->perPage(),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'from' => $from,
                'to' => $to,
            ],
            'filters' => [
                'query' => $search,
                'year' => $yearFilter,
                'teacher' => $teacherFilter,
            ],
            'yearOptions' => $years,
            'teacherOptions' => $teachers,
            'availableTeachers' => $teachers,
            'availableStudents' => $students,
        ]);
    }

    public function updateClass(
        UpdateSchoolClassRequest $request,
        SchoolClass $class
    ) {
        $validated = $request->validated();
        $teacherIds = collect($validated['teacher_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $studentIds = collect($validated['student_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        DB::transaction(function () use ($request, $class, $validated, $teacherIds, $studentIds) {
            $before = [
                'name' => $class->name,
                'year' => $class->year,
                'section' => $class->section,
                'teacher_ids' => $class->teachers()->allRelatedIds()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'student_ids' => $class->students()->allRelatedIds()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            ];

            $class->fill([
                'name' => $validated['name'],
                'year' => $validated['year'] !== '' ? $validated['year'] : null,
                'section' => $validated['section'] !== '' ? strtoupper($validated['section']) : null,
            ]);
            $class->save();

            $today = Carbon::now()->toDateString();

            $teacherStartDates = DB::table('class_teacher')
                ->where('class_id', $class->id)
                ->whereIn('teacher_id', $teacherIds->all())
                ->pluck('start_date', 'teacher_id');

            $teacherSyncData = $teacherIds
                ->mapWithKeys(function (int $teacherId) use ($teacherStartDates, $today) {
                    return [
                        $teacherId => [
                            'start_date' => $teacherStartDates->get($teacherId) ?: $today,
                            'end_date' => null,
                        ],
                    ];
                })
                ->all();

            $class->teachers()->sync($teacherSyncData);

            if ($studentIds->isNotEmpty()) {
                DB::table('class_user')
                    ->whereIn('user_id', $studentIds->all())
                    ->where('class_id', '!=', $class->id)
                    ->delete();
            }

            $studentStartDates = DB::table('class_user')
                ->where('class_id', $class->id)
                ->whereIn('user_id', $studentIds->all())
                ->pluck('start_date', 'user_id');

            $studentSyncData = $studentIds
                ->mapWithKeys(function (int $studentId) use ($studentStartDates, $today) {
                    return [
                        $studentId => [
                            'start_date' => $studentStartDates->get($studentId) ?: $today,
                            'end_date' => null,
                        ],
                    ];
                })
                ->all();

            $class->students()->sync($studentSyncData);

            OperationLog::record(
                $request->user(),
                'admin.class.updated',
                'class',
                $class->id,
                [
                    'before' => $before,
                    'after' => [
                        'name' => $class->name,
                        'year' => $class->year,
                        'section' => $class->section,
                        'teacher_ids' => $teacherIds->all(),
                        'student_ids' => $studentIds->all(),
                    ],
                ],
                'INFO',
                $request
            );
        });

        return back()->with('success', 'Classe aggiornata con successo.');
    }

    public function storeClass(UpdateSchoolClassRequest $request)
    {
        $validated = $request->validated();
        $teacherIds = collect($validated['teacher_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $studentIds = collect($validated['student_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $name = strtoupper(trim((string) $validated['name']));
        $year = trim((string) ($validated['year'] ?? ''));
        $section = strtoupper(trim((string) ($validated['section'] ?? '')));
        $schoolClass = null;

        DB::transaction(function () use ($request, $name, $year, $section, $teacherIds, $studentIds, &$schoolClass) {
            $schoolClass = SchoolClass::query()->firstOrCreate(
                [
                    'name' => $name,
                    'year' => $year !== '' ? $year : null,
                    'section' => $section !== '' ? $section : null,
                ],
                [
                    'active' => true,
                ]
            );

            if ($schoolClass->wasRecentlyCreated) {
                $today = Carbon::now()->toDateString();

                $teacherSyncData = $teacherIds
                    ->mapWithKeys(fn (int $teacherId) => [
                        $teacherId => [
                            'start_date' => $today,
                            'end_date' => null,
                        ],
                    ])
                    ->all();

                $schoolClass->teachers()->sync($teacherSyncData);

                if ($studentIds->isNotEmpty()) {
                    DB::table('class_user')
                        ->whereIn('user_id', $studentIds->all())
                        ->where('class_id', '!=', $schoolClass->id)
                        ->delete();
                }

                $studentSyncData = $studentIds
                    ->mapWithKeys(fn (int $studentId) => [
                        $studentId => [
                            'start_date' => $today,
                            'end_date' => null,
                        ],
                    ])
                    ->all();

                $schoolClass->students()->sync($studentSyncData);
            }

            OperationLog::record(
                $request->user(),
                'admin.class.created',
                'class',
                $schoolClass->id,
                [
                    'name' => $schoolClass->name,
                    'year' => $schoolClass->year,
                    'section' => $schoolClass->section,
                    'was_recently_created' => $schoolClass->wasRecentlyCreated,
                    'teacher_ids' => $schoolClass->wasRecentlyCreated ? $teacherIds->all() : [],
                    'student_ids' => $schoolClass->wasRecentlyCreated ? $studentIds->all() : [],
                ],
                'INFO',
                $request
            );
        });

        return back()->with('success', $schoolClass->wasRecentlyCreated
            ? 'Classe creata con successo.'
            : 'Classe gia presente.');
    }

    public function destroyClass(
        Request $request,
        SchoolClass $class
    ) {
        $classSnapshot = [
            'name' => $class->name,
            'year' => $class->year,
            'section' => $class->section,
        ];

        DB::transaction(function () use ($request, $class, $classSnapshot) {
            $classId = $class->id;
            $class->delete();

            OperationLog::record(
                $request->user(),
                'admin.class.deleted',
                'class',
                $classId,
                [
                    'deleted_class' => $classSnapshot,
                ],
                'INFO',
                $request
            );
        });

        return back()->with('success', 'Classe eliminata con successo.');
    }

    public function InteractionManagement(Request $request)
    {

        $log = new OperationLog;
        $filters = $this->extractLogFilters($request);

        $result = $log->getPaginatedLog(
            ['INFO'],
            50,
            $this->nullIfEmpty($filters['query']),
            $this->nullIfEmpty($filters['entity']),
            $this->nullIfEmpty($filters['action']),
            $this->nullIfEmpty($filters['date_from']),
            $this->nullIfEmpty($filters['date_to'])
        );

        return Inertia::render('Admin/Interactions', [
            'logs' => $result['data'],
            'pagination' => $result['pagination'],
            'entities' => $log->getAvailableEntities(['INFO']),
            'actions' => $log->getAvailableActions(['INFO'], $this->nullIfEmpty($filters['entity'])),
            'filters' => $filters,
        ]);

    }

    public function ErrorManagement(Request $request)
    {

        $log = new OperationLog;
        $filters = $this->extractLogFilters($request);
        $level = strtoupper($filters['level']);
        $allowedLevels = ['ERROR', 'WARNING'];
        $levels = in_array($level, $allowedLevels, true)
            ? [$level]
            : $allowedLevels;

        $result = $log->getPaginatedLog(
            $levels,
            50,
            $this->nullIfEmpty($filters['query']),
            $this->nullIfEmpty($filters['entity']),
            $this->nullIfEmpty($filters['action']),
            $this->nullIfEmpty($filters['date_from']),
            $this->nullIfEmpty($filters['date_to'])
        );

        return Inertia::render('Admin/ErrorLogs', [
            'logs' => $result['data'],
            'pagination' => $result['pagination'],
            'entities' => $log->getAvailableEntities($levels),
            'actions' => $log->getAvailableActions($levels, $this->nullIfEmpty($filters['entity'])),
            'filters' => [
                ...$filters,
                'level' => in_array($level, $allowedLevels, true) ? $level : '',
            ],
        ]);

    }

    public function showInteractionLogsExportOptions(Request $request)
    {
        $log = new OperationLog;
        $filters = $this->extractLogFilters($request);

        return Inertia::render('Admin/LogsExport', [
            'title' => 'Esporta storico interazioni',
            'description' => 'Scegli quali operazioni includere nel file CSV.',
            'sourceRoute' => 'admin.interactions',
            'exportRoute' => 'admin.interactions.export',
            'filters' => [
                ...$filters,
                'level' => '',
            ],
            'entities' => $log->getAvailableEntities(['INFO']),
            'actions' => $log->getAvailableActions(['INFO']),
            'levelOptions' => [],
            'maxRows' => self::LOG_EXPORT_MAX_ROWS,
        ]);
    }

    public function showErrorLogsExportOptions(Request $request)
    {
        $log = new OperationLog;
        $filters = $this->extractLogFilters($request);
        $level = strtoupper($filters['level']);
        $allowedLevels = ['ERROR', 'WARNING'];

        return Inertia::render('Admin/LogsExport', [
            'title' => 'Esporta log errori',
            'description' => 'Scegli quali errori o avvisi includere nel file CSV.',
            'sourceRoute' => 'admin.error-logs',
            'exportRoute' => 'admin.error-logs.export',
            'filters' => [
                ...$filters,
                'level' => in_array($level, $allowedLevels, true) ? $level : '',
            ],
            'entities' => $log->getAvailableEntities($allowedLevels),
            'actions' => $log->getAvailableActions($allowedLevels),
            'levelOptions' => [
                [
                    'code' => '',
                    'label' => 'Tutti (ERROR + WARNING)',
                ],
                [
                    'code' => 'ERROR',
                    'label' => 'ERROR',
                ],
                [
                    'code' => 'WARNING',
                    'label' => 'WARNING',
                ],
            ],
            'maxRows' => self::LOG_EXPORT_MAX_ROWS,
        ]);
    }

    public function exportInteractionLogs(Request $request): StreamedResponse
    {
        $this->disableExecutionTimeLimit();

        $log = new OperationLog;
        $filters = $this->extractLogFilters($request);
        $result = $log->getExportRows(
            ['INFO'],
            $this->nullIfEmpty($filters['query']),
            $this->nullIfEmpty($filters['entity']),
            $this->nullIfEmpty($filters['action']),
            $this->nullIfEmpty($filters['date_from']),
            $this->nullIfEmpty($filters['date_to']),
            self::LOG_EXPORT_MAX_ROWS
        );

        return $this->streamLogCsv(
            $result,
            'storico-interazioni',
            $filters,
            null
        );
    }

    public function exportErrorLogs(Request $request): StreamedResponse
    {
        $this->disableExecutionTimeLimit();

        $log = new OperationLog;
        $filters = $this->extractLogFilters($request);
        $level = strtoupper($filters['level']);
        $allowedLevels = ['ERROR', 'WARNING'];
        $levels = in_array($level, $allowedLevels, true)
            ? [$level]
            : $allowedLevels;

        $result = $log->getExportRows(
            $levels,
            $this->nullIfEmpty($filters['query']),
            $this->nullIfEmpty($filters['entity']),
            $this->nullIfEmpty($filters['action']),
            $this->nullIfEmpty($filters['date_from']),
            $this->nullIfEmpty($filters['date_to']),
            self::LOG_EXPORT_MAX_ROWS
        );

        return $this->streamLogCsv(
            $result,
            'log-errori',
            $filters,
            in_array($level, $allowedLevels, true) ? $level : null
        );
    }

    private function streamLogCsv(
        array $result,
        string $filenamePrefix,
        array $filters,
        ?string $level
    ): StreamedResponse {
        $rows = $result['rows'];
        $total = (int) ($result['total'] ?? 0);
        $exported = (int) ($result['exported'] ?? 0);
        $truncated = (bool) ($result['truncated'] ?? false);
        $fileSuffix = $truncated ? '_parziale' : '';
        $fileName = sprintf(
            '%s_%s%s.csv',
            $filenamePrefix,
            now()->format('Ymd_His'),
            $fileSuffix
        );

        return response()->streamDownload(function () use (
            $rows,
            $total,
            $exported,
            $truncated,
            $filters,
            $level
        ) {
            $this->disableExecutionTimeLimit();

            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'report',
                'operation_logs',
                'totale_trovati',
                $total,
                'righe_esportate',
                $exported,
                'limite_export',
                self::LOG_EXPORT_MAX_ROWS,
            ], ';');
            fputcsv($handle, [
                'parziale',
                $truncated ? 'si' : 'no',
                'livello',
                $level ?: 'INFO/ERROR/WARNING',
                'query',
                $filters['query'] !== '' ? $filters['query'] : '-',
                'entita',
                $filters['entity'] !== '' ? $filters['entity'] : '-',
                'azione',
                $filters['action'] !== '' ? $filters['action'] : '-',
                'da',
                $filters['date_from'] !== '' ? $filters['date_from'] : '-',
                'a',
                $filters['date_to'] !== '' ? $filters['date_to'] : '-',
            ], ';');
            fwrite($handle, PHP_EOL);

            fputcsv($handle, [
                'id',
                'livello',
                'livello_label',
                'attore',
                'azione',
                'azione_code',
                'entita',
                'entita_code',
                'ip',
                'created_at',
                'dettagli_json',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['id'] ?? '',
                    $row['livello'] ?? '',
                    $row['livello_label'] ?? '',
                    $row['attore'] ?? '',
                    $row['azione'] ?? '',
                    $row['azione_code'] ?? '',
                    $row['entita'] ?? '',
                    $row['entita_code'] ?? '',
                    $row['ip'] ?? '',
                    $row['created_at'] ?? '',
                    json_encode($row['dettagli_json'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], ';');
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return array{query:string,level:string,entity:string,action:string,date_from:string,date_to:string}
     */
    private function extractLogFilters(Request $request): array
    {
        return [
            'query' => trim((string) $request->query('query', '')),
            'level' => trim((string) $request->query('level', '')),
            'entity' => trim((string) $request->query('entity', '')),
            'action' => trim((string) $request->query('action', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ];
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function disableExecutionTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
        }
    }

    public function AddUser()
    {
        $roleOptions = $this->managedUserRoleOptions();
        $classOptions = SchoolClass::query()
            ->orderBy('name')
            ->orderBy('section')
            ->orderBy('year')
            ->get(['id', 'name', 'section', 'year'])
            ->map(function (SchoolClass $class) {
                $labelParts = array_values(
                    array_filter([
                        $class->name,
                        $class->section,
                        $class->year,
                    ], fn (?string $value) => $value !== null && trim($value) !== '')
                );

                return [
                    'id' => $class->id,
                    'label' => $labelParts === []
                        ? (string) $class->id
                        : implode(' ', $labelParts),
                ];
            })
            ->values();

        return Inertia::render('Admin/AddUser', [
            'roleOptions' => $roleOptions,
            'classOptions' => $classOptions,
        ]);

    }

    public function StoreManualUser(AddManualUserRequest $request)
    {
        $validated = $request->validated();
        $createdUser = null;

        DB::transaction(function () use ($validated, &$createdUser) {
            $createdUser = User::query()->create([
                'name' => $validated['name'],
                'surname' => $validated['surname'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'birth_date' => $validated['birth_date'] ?? null,
                'password' => Hash::make(Str::password(32)),
                'active' => true,
            ]);

            $classId = $validated['class_id'] ?? null;
            if ($validated['role'] === 'student' && $classId !== null) {
                $createdUser->classes()->sync([
                    (int) $classId => [
                        'start_date' => Carbon::now()->toDateString(),
                        'end_date' => null,
                    ],
                ]);
            }

            try {
                $status = Password::broker()->sendResetLink([
                    'email' => $createdUser->email,
                ]);
            } catch (\Throwable) {
                $status = null;
            }

            if ($status !== Password::RESET_LINK_SENT) {
                throw ValidationException::withMessages([
                    'email' => $status
                        ? trans($status, [], 'it')
                        : 'Invio email impostazione password non riuscito.',
                ]);
            }
        });

        OperationLog::record(
            $request->user(),
            'admin.user.created',
            'user',
            $createdUser?->id,
            [
                'created_user' => [
                    'id' => $createdUser?->id,
                    'name' => $createdUser?->name,
                    'surname' => $createdUser?->surname,
                    'email' => $createdUser?->email,
                    'role' => $createdUser?->role,
                ],
                'password_setup_emails_sent' => 1,
                'password_setup_emails_failed' => 0,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Utente creato. Email impostazione password inviata.');
    }

    public function StoreUserFromCsv(AddUserCsvRequest $request, PasswordSetupService $passwordSetupService)
    {
        $request->validated();
        $file = $request->file('file');
        $filePath = $file->getRealPath();
        $createdUsers = 0;
        $updatedUsers = 0;
        $passwordSetupEmailsSent = 0;
        $passwordSetupEmailsFailed = [];

        if (! is_string($filePath) || $filePath === '') {
            return back()->withErrors(['file' => 'Impossibile leggere il file caricato.']);
        }

        if ($this->isBinaryOrSpreadsheetFile($filePath)) {
            return back()->withErrors(['file' => self::GAGI_CSV_FORMAT_ERROR]);
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return back()->withErrors(['file' => 'Impossibile leggere il file caricato.']);
        }

        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);

            return back()->withErrors(['file' => 'File vuoto.']);
        }

        $delimiter = $this->detectDelimiter($headerLine);
        if ($delimiter !== ';') {
            fclose($handle);

            return back()->withErrors(['file' => self::GAGI_CSV_FORMAT_ERROR]);
        }

        $headerColumns = str_getcsv($headerLine, $delimiter);
        $isGagiFormat = $this->isGagiCsvHeader($headerColumns);
        if (! $isGagiFormat) {
            fclose($handle);

            return back()->withErrors(['file' => self::GAGI_CSV_FORMAT_ERROR]);
        }

        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            if (! array_filter($row)) {
                continue;
            }

            $nomeCognome = trim((string) ($row[1] ?? ''));
            $dataDiNascita = trim((string) ($row[2] ?? ''));
            $classeRaw = trim((string) ($row[4] ?? ''));
            $emailRaw = trim((string) ($row[6] ?? ''));

            if ($nomeCognome === '' || $classeRaw === '' || $emailRaw === '') {
                continue;
            }

            [$nome, $cognome] = $this->parseImportedFullName($nomeCognome, $isGagiFormat);
            if ($nome === '' || $cognome === '') {
                continue;
            }

            $parsedClass = $this->parseClassCode($classeRaw);
            if ($parsedClass === null) {
                continue;
            }

            $email = $this->normalizeStudentEmail($emailRaw);
            if ($email === null) {
                continue;
            }

            $classe = SchoolClass::firstOrCreate([
                'name' => $parsedClass['name'],
                'section' => $parsedClass['section'],
                'year' => $parsedClass['year'],
            ]);

            $user = User::query()->firstOrNew(['email' => $email]);
            $isNewUser = ! $user->exists;

            $user->name = $nome;
            $user->surname = $cognome;
            $user->email = $email;
            $user->role = 'student';
            $user->active = true;

            $birthDate = $this->normalizeImportedBirthDate($dataDiNascita);
            if ($birthDate !== null) {
                $user->birth_date = $birthDate;
            }

            if ($isNewUser) {
                $user->password = Hash::make(Str::password(32));
            }

            $user->save();

            if ($isNewUser) {
                $createdUsers++;
                $status = null;

                try {
                    $passwordSetupService->sendSetupLink($user);
                    $status = Password::RESET_LINK_SENT;
                } catch (\Throwable) {
                    $status = null;
                }

                if ($status === Password::RESET_LINK_SENT) {
                    $passwordSetupEmailsSent++;
                } else {
                    $passwordSetupEmailsFailed[] = $user->email;
                }
            } else {
                $updatedUsers++;
            }

            $currentClass = $user->classes()
                ->orderByPivot('start_date', 'desc')
                ->first();

            $startDate = Carbon::now()->toDateString();
            if (
                $currentClass
                && (int) $currentClass->id === (int) $classe->id
                && $currentClass->pivot?->start_date
            ) {
                $startDate = $currentClass->pivot->start_date;
            }

            $user->classes()->sync([
                $classe->id => [
                    'start_date' => $startDate,
                    'end_date' => null,
                ],
            ]);
        }

        fclose($handle);

        $importedFileName = trim((string) $file->getClientOriginalName());
        OperationLog::record(
            $request->user(),
            'admin.users.imported',
            'user',
            null,
            [
                'import_file_name' => $importedFileName !== '' ? $importedFileName : null,
                'created_users' => $createdUsers,
                'updated_users' => $updatedUsers,
                'password_setup_emails_sent' => $passwordSetupEmailsSent,
                'password_setup_emails_failed' => count($passwordSetupEmailsFailed),
                'failed_emails' => $passwordSetupEmailsFailed,
            ],
            $passwordSetupEmailsFailed === [] ? 'INFO' : 'WARNING',
            $request
        );

        $message = "Import completato: {$createdUsers} creati, {$updatedUsers} aggiornati.";
        if ($createdUsers > 0) {
            $message .= " Email impostazione password inviate {$passwordSetupEmailsSent}/{$createdUsers}.";
        }

        if ($passwordSetupEmailsFailed !== []) {
            $preview = implode(', ', array_slice($passwordSetupEmailsFailed, 0, 5));
            $remaining = count($passwordSetupEmailsFailed) - 5;
            $message .= ' Invio email fallito per: '.$preview;
            if ($remaining > 0) {
                $message .= " (+{$remaining} altri)";
            }
            $message .= '.';
        }

        return back()->with('success', $message);
    }

    private function isGagiCsvHeader(array $headerColumns): bool
    {
        $normalizedColumns = array_map(
            fn ($column) => $this->normalizeCsvHeaderColumn($column),
            $headerColumns
        );

        return count($normalizedColumns) >= 7
            && ($normalizedColumns[0] ?? null) === 'id'
            && ($normalizedColumns[1] ?? null) === 'allievo'
            && ($normalizedColumns[2] ?? null) === 'data di nascita'
            && ($normalizedColumns[4] ?? null) === 'classe'
            && ($normalizedColumns[6] ?? null) === 'networkid';
    }

    private function normalizeCsvHeaderColumn(mixed $column): string
    {
        $value = strtolower(trim((string) $column));
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function isBinaryOrSpreadsheetFile(string $filePath): bool
    {
        $sample = file_get_contents($filePath, false, null, 0, 512);
        if ($sample === false) {
            return false;
        }

        return str_starts_with($sample, "PK\x03\x04")
            || str_starts_with($sample, "\xD0\xCF\x11\xE0")
            || str_contains($sample, "\0");
    }

    private function parseImportedFullName(string $rawFullName, bool $surnameFirst): array
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $rawFullName));
        if ($normalized === '') {
            return ['', ''];
        }

        if (str_contains($normalized, ',')) {
            [$surnamePart, $namePart] = array_map(
                'trim',
                explode(',', $normalized, 2)
            );

            if ($surnamePart !== '' && $namePart !== '') {
                return [$namePart, $surnamePart];
            }
        }

        $parts = preg_split('/\s+/', $normalized) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], $parts[0]];
        }

        if ($surnameFirst) {
            $surnameParts = [$parts[0]];
            $nameStart = 1;
            $surnamePrefixes = [
                'de',
                'del',
                'della',
                'di',
                'da',
                'la',
                'le',
                'van',
                'von',
                'dei',
                'degli',
                'du',
            ];

            if (
                isset($parts[1])
                && in_array(strtolower($parts[0]), $surnamePrefixes, true)
            ) {
                $surnameParts[] = $parts[1];
                $nameStart = 2;
            }

            $surname = trim(implode(' ', $surnameParts));
            $name = trim(implode(' ', array_slice($parts, $nameStart)));
            if ($name === '') {
                $name = $surname;
            }

            return [$name, $surname];
        }

        $surname = trim((string) array_pop($parts));
        $name = trim(implode(' ', $parts));
        if ($name === '') {
            $name = $surname;
        }

        return [$name, $surname];
    }

    private function normalizeImportedBirthDate(string $rawBirthDate): ?string
    {
        $value = trim($rawBirthDate);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $value) === 1) {
            [$day, $month, $year] = array_map('intval', explode('.', $value));
            $normalizedDotDate = sprintf('%02d-%02d-%04d', $day, $month, $year);

            try {
                return Carbon::createFromFormat('d-m-Y', $normalizedDotDate)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{2}$/', $value) === 1) {
            [$day, $month, $year] = array_map('intval', explode('.', $value));
            $normalizedDotDate = sprintf('%02d-%02d-%02d', $day, $month, $year);

            try {
                return Carbon::createFromFormat('d-m-y', $normalizedDotDate)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 0 && $serial < 100000) {
                $timestamp = (int) round(($serial - 25569) * 86400);
                if ($timestamp > 0) {
                    return Carbon::createFromTimestampUTC($timestamp)->toDateString();
                }
            }
        }

        $normalized = str_replace(['.', '/'], '-', $value);
        $formats = ['Y-m-d', 'd-m-Y', 'd-m-y'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $normalized)->toDateString();
            } catch (\Throwable) {
                // prova formato successivo.
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function managedUserRoleOptions()
    {
        return collect(self::MANAGED_USER_ROLES)
            ->map(fn (string $role) => [
                'code' => $role,
                'label' => $this->translateUserRole($role),
            ])
            ->values();
    }

    private function isLastAdmin(User $candidate): bool
    {
        if (! $candidate->hasRole('admin')) {
            return false;
        }

        return User::query()
            ->where('role', 'admin')
            ->where('id', '!=', $candidate->id)
            ->doesntExist();
    }

    private function translateUserRole(string $role): string
    {
        return match (trim(strtolower($role))) {
            'student' => 'Studente',
            'teacher' => 'Docente di classe',
            'laboratory_manager' => 'Capo laboratorio',
            'admin' => 'Admin',
            default => $role,
        };
    }

    private function detectDelimiter(string $line): string
    {
        $delimiters = ["\t", ';', ','];
        $best = ';';
        $max = -1;

        foreach ($delimiters as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > $max) {
                $max = $count;
                $best = $delimiter;
            }
        }

        return $best;
    }

    private function parseClassCode(string $rawClass): ?array
    {
        $classCode = strtoupper((string) preg_replace('/\s+/', '', $rawClass));
        if ($classCode === '') {
            return null;
        }

        if (preg_match('/^([A-Z])(\d)([A-Z0-9]{1,6})$/', $classCode, $matches) === 1) {
            return [
                'section' => $matches[1],
                'year' => $matches[2],
                'name' => $matches[3],
            ];
        }

        if (preg_match('/^([A-Z]{2,4})(\d)([A-Z])$/', $classCode, $matches) === 1) {
            return [
                'section' => $matches[1],
                'year' => $matches[2],
                'name' => $matches[3],
            ];
        }

        $section = substr($classCode, 0, 1);
        $year = substr($classCode, 1, 1);
        $name = substr($classCode, 2);

        if ($section === '' || $year === '' || $name === '') {
            return null;
        }

        return [
            'section' => $section,
            'year' => $year,
            'name' => $name,
        ];
    }

    private function normalizeStudentEmail(string $rawEmail): ?string
    {
        $email = strtolower(trim($rawEmail));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        [$userName, $domain] = explode('@', $email, 2);
        $userName = trim($userName);
        $domain = trim($domain);

        if ($userName === '' || $domain === '' || ! str_contains($domain, '.')) {
            return null;
        }

        if (! str_starts_with($domain, 'student.')) {
            $domain = 'student.'.$domain;
        }

        $normalizedEmail = $userName.'@'.$domain;

        return filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) !== false
            ? $normalizedEmail
            : null;
    }
}
