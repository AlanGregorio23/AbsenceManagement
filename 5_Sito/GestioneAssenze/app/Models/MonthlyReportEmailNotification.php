<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyReportEmailNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'monthly_report_id',
        'type',
        'recipient_email',
        'subject',
        'body',
        'sent_at',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function monthlyReport()
    {
        return $this->belongsTo(MonthlyReport::class);
    }
}
