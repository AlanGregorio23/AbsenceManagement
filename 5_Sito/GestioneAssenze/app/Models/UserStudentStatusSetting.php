<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserStudentStatusSetting extends Model
{
    use HasFactory;

    public const DEFAULT_WARNING_PERCENT = 80;

    public const DEFAULT_CRITICAL_PERCENT = 100;

    protected $fillable = [
        'user_id',
        'absence_warning_percent',
        'absence_critical_percent',
        'delay_warning_percent',
        'delay_critical_percent',
    ];

    protected function casts(): array
    {
        return [
            'absence_warning_percent' => 'integer',
            'absence_critical_percent' => 'integer',
            'delay_warning_percent' => 'integer',
            'delay_critical_percent' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
