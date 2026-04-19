<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DelayConfirmationToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'delay_id',
        'guardian_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function delay()
    {
        return $this->belongsTo(Delay::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }
}
