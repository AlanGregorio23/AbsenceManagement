<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbsenceSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'max_annual_hours',
        'warning_threshold_hours',
        'student_status_warning_percent',
        'student_status_critical_percent',
        'vice_director_email',
        'guardian_signature_required',
        'medical_certificate_days',
        'medical_certificate_max_days',
        'absence_countdown_days',
        'leave_request_notice_working_hours',
    ];

    protected $casts = [
        'guardian_signature_required' => 'boolean',
        'student_status_warning_percent' => 'integer',
        'student_status_critical_percent' => 'integer',
        'leave_request_notice_working_hours' => 'integer',
    ];
}
