<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guardian extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
    ];

    public function students()
    {
        return $this->belongsToMany(User::class, 'guardian_student', 'guardian_id', 'student_id')
            ->withPivot(['relationship', 'is_primary', 'is_active', 'deactivated_at'])
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    public function allStudents()
    {
        return $this->belongsToMany(User::class, 'guardian_student', 'guardian_id', 'student_id')
            ->withPivot(['relationship', 'is_primary', 'is_active', 'deactivated_at'])
            ->withTimestamps();
    }

    public function absenceTokens()
    {
        return $this->hasMany(AbsenceConfirmationToken::class);
    }

    public function absenceConfirmations()
    {
        return $this->hasMany(GuardianAbsenceConfirmation::class);
    }

    public function leaveTokens()
    {
        return $this->hasMany(LeaveConfirmationToken::class);
    }

    public function delayTokens()
    {
        return $this->hasMany(DelayConfirmationToken::class);
    }

    public function delayConfirmations()
    {
        return $this->hasMany(GuardianDelayConfirmation::class);
    }
}
