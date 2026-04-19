<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbsenceConfirmationToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'absence_id',
        'guardian_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    public function absence()
    {
        return $this->belongsTo(Absence::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
