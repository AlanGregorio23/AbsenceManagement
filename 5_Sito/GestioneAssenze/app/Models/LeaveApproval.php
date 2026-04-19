<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_id',
        'decided_by',
        'decision',
        'notes',
        'decided_at',
        'override_guardian_signature',
    ];

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'override_guardian_signature' => 'boolean',
        ];
    }
}
