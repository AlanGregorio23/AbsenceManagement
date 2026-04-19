<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuardianDelayConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'delay_id',
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

    public function delay()
    {
        return $this->belongsTo(Delay::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }
}
