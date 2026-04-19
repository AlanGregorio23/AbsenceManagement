<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DelaySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'minutes_threshold',
        'guardian_signature_required',
        'deadline_active',
        'deadline_business_days',
        'justification_business_days',
        'pre_expiry_warning_business_days',
    ];

    protected $casts = [
        'guardian_signature_required' => 'boolean',
        'deadline_active' => 'boolean',
    ];
}
