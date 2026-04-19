<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveConfirmationToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_id',
        'guardian_id',
        'token_hash',
        'expires_at',
        'used_at',
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
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
