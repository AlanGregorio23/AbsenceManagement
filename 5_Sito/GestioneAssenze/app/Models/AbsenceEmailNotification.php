<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbsenceEmailNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'recipient_email',
        'subject',
        'body',
        'absence_id',
        'sent_at',
        'status',
    ];

    public function absence()
    {
        return $this->belongsTo(Absence::class);
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
