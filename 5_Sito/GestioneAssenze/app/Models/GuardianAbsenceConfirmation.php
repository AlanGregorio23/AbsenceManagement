<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuardianAbsenceConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'absence_id',
        'guardian_id',
        'status',
        'confirmed_at',
        'signed_at',
        'signature_path',
        'ip_address',
        'notes',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function absence()
    {
        return $this->belongsTo(Absence::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }
}
