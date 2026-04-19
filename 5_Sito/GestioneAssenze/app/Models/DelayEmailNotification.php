<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DelayEmailNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'recipient_email',
        'subject',
        'body',
        'delay_id',
        'sent_at',
        'status',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function delay()
    {
        return $this->belongsTo(Delay::class);
    }
}
