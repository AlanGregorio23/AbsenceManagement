<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbsenceReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'counts_40_hours',
        'requires_management_consent',
        'requires_document_on_leave_creation',
        'management_consent_note',
    ];

    protected $casts = [
        'counts_40_hours' => 'boolean',
        'requires_management_consent' => 'boolean',
        'requires_document_on_leave_creation' => 'boolean',
    ];
}
