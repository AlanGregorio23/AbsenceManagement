<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveEmailNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'recipient_email',
        'subject',
        'body',
        'leave_id',
        'sent_at',
        'status',
    ];

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
