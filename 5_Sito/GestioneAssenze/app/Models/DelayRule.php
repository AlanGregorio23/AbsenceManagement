<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DelayRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'min_delays',
        'max_delays',
        'actions',
        'info_message',
    ];

    protected $casts = [
        'actions' => 'array',
    ];
}
