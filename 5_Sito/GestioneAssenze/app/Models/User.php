<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Carbon\Carbon;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'email',
        'password',
        'role',
        'birth_date',
        'active',
        'avatar_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function classes()
    {
        return $this->belongsToMany(SchoolClass::class, 'class_user', 'user_id', 'class_id')
            ->withPivot(['start_date', 'end_date'])
            ->withTimestamps();
    }

    public function teachingClasses()
    {
        return $this->belongsToMany(SchoolClass::class, 'class_teacher', 'teacher_id', 'class_id')
            ->withPivot(['start_date', 'end_date'])
            ->withTimestamps();
    }

    public function absences()
    {
        return $this->hasMany(Absence::class, 'student_id');
    }

    public function delays()
    {
        return $this->hasMany(Delay::class, 'student_id');
    }

    public function delaysRecorded()
    {
        return $this->hasMany(Delay::class, 'recorded_by');
    }

    public function delaysValidated()
    {
        return $this->hasMany(Delay::class, 'validated_by');
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class, 'student_id');
    }

    public function monthlyReports()
    {
        return $this->hasMany(MonthlyReport::class, 'student_id');
    }

    public function approvedMonthlyReports()
    {
        return $this->hasMany(MonthlyReport::class, 'approved_by');
    }

    public function operationLogs()
    {
        return $this->hasMany(OperationLog::class);
    }

    public function notificationPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function studentStatusSetting()
    {
        return $this->hasOne(UserStudentStatusSetting::class);
    }

    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student', 'student_id', 'guardian_id')
            ->withPivot(['relationship', 'is_primary', 'is_active', 'deactivated_at'])
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    public function allGuardians()
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student', 'student_id', 'guardian_id')
            ->withPivot(['relationship', 'is_primary', 'is_active', 'deactivated_at'])
            ->withTimestamps();
    }

    public function inactiveGuardians()
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student', 'student_id', 'guardian_id')
            ->withPivot(['relationship', 'is_primary', 'is_active', 'deactivated_at'])
            ->wherePivot('is_active', false)
            ->withTimestamps();
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function isAdult(?Carbon $referenceDate = null): bool
    {
        if (! $this->birth_date) {
            return false;
        }

        $today = ($referenceDate ?? Carbon::today())->copy()->startOfDay();
        $adultThreshold = Carbon::parse($this->birth_date)->startOfDay()->addYears(18);

        return $adultThreshold->lte($today);
    }

    public function fullName(): string
    {
        return trim((string) $this->name.' '.(string) $this->surname);
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $avatarPath = ltrim(str_replace('\\', '/', trim((string) $this->avatar_path)), '/');
                if ($avatarPath === '') {
                    return null;
                }

                if (str_starts_with($avatarPath, 'profile-avatars/')) {
                    return asset($avatarPath);
                }

                if (str_starts_with($avatarPath, 'archivio/')) {
                    return route('profile.avatar.show', [
                        'user' => $this->id,
                        'v' => md5($avatarPath),
                    ]);
                }

                return null;
            },
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'date',
            'active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function getUser()
    {

        $users = User::query()
            ->with([
                'classes' => function ($query) {
                    $query->orderByPivot('start_date', 'desc');
                },
                'guardians' => function ($query) {
                    $query->orderBy('guardians.name');
                },
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (User $user) {
                $className = $user->classes->first();
                $role = $user->role ?? 'student';
                $label = match ($role) {
                    'student' => 'Studente',
                    'teacher' => 'Docente di classe',
                    'laboratory_manager' => 'Capo laboratorio',
                    'admin' => 'Admin',
                    default => $role,
                };
                $state = (bool) $user->active;
                $stato = $state ? 'Attivo' : 'Inattivo';
                $guardians = $user->guardians
                    ->map(function (Guardian $guardian) {
                        return [
                            'id' => $guardian->id,
                            'name' => $guardian->name,
                            'email' => $guardian->email,
                            'relationship' => $guardian->pivot?->relationship,
                        ];
                    })
                    ->values();
                $guardianContact = $guardians->first();

                return [
                    'id' => 'U-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                    'user_id' => $user->id,
                    'nome' => $user->name,
                    'cognome' => $user->surname,
                    'email' => $user->email,
                    'ruolo_code' => $role,
                    'ruolo' => $label,
                    'classe' => $className?->name ?? '-',
                    'stato' => $stato,
                    'tutore_legale' => $guardianContact ? [
                        'id' => $guardianContact['id'],
                        'name' => $guardianContact['name'],
                        'email' => $guardianContact['email'],
                        'relationship' => $guardianContact['relationship'],
                    ] : null,
                    'tutori' => $guardians,
                    'creato_il' => $user->created_at->format('d M Y'),
                ];
            });

        return $users;

    }
}
