<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DelaySetting extends Model
{
    use HasFactory;

    public const DEFAULT_FIRST_SEMESTER_END_DAY = 26;

    public const DEFAULT_FIRST_SEMESTER_END_MONTH = 1;

    protected $fillable = [
        'minutes_threshold',
        'guardian_signature_required',
        'deadline_active',
        'deadline_business_days',
        'justification_business_days',
        'pre_expiry_warning_business_days',
        'pre_expiry_warning_percent',
        'first_semester_end_day',
        'first_semester_end_month',
    ];

    protected $casts = [
        'guardian_signature_required' => 'boolean',
        'deadline_active' => 'boolean',
        'pre_expiry_warning_percent' => 'integer',
        'first_semester_end_day' => 'integer',
        'first_semester_end_month' => 'integer',
    ];

    public function resolvedFirstSemesterEndDay(): int
    {
        return max(
            1,
            min((int) ($this->first_semester_end_day ?? self::DEFAULT_FIRST_SEMESTER_END_DAY), 31)
        );
    }

    public function resolvedFirstSemesterEndMonth(): int
    {
        return max(
            1,
            min((int) ($this->first_semester_end_month ?? self::DEFAULT_FIRST_SEMESTER_END_MONTH), 12)
        );
    }

    public function firstSemesterEndLabel(): string
    {
        return sprintf(
            '%02d/%02d',
            $this->resolvedFirstSemesterEndDay(),
            $this->resolvedFirstSemesterEndMonth()
        );
    }
}
