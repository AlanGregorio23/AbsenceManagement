<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuardianLeaveConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_id',
        'guardian_id',
        'status',
        'confirmed_at',
        'notes',
        'signed_at',
        'signature_path',
        'ip_address',
    ];

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }
}
