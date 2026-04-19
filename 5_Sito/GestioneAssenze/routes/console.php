<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$timezone = 'Europe/Zurich';
$schoolWindowStart = '06:00';
$schoolWindowEnd = '20:00';

Schedule::command('absences:mark-arbitrary')
    ->hourlyAt(5)
    ->between($schoolWindowStart, $schoolWindowEnd)
    ->timezone($timezone)
    ->withoutOverlapping();

Schedule::command('delays:mark-arbitrary')
    ->hourlyAt(10)
    ->between($schoolWindowStart, $schoolWindowEnd)
    ->timezone($timezone)
    ->withoutOverlapping();

Schedule::command('delays:resend-expired-signature-tokens')
    ->hourlyAt(20)
    ->between($schoolWindowStart, $schoolWindowEnd)
    ->timezone($timezone)
    ->withoutOverlapping();

Schedule::command('leaves:register-due-absences')
    ->hourlyAt(30)
    ->between($schoolWindowStart, $schoolWindowEnd)
    ->timezone($timezone)
    ->withoutOverlapping();

Schedule::command('reports:generate-monthly')
    ->monthlyOn(1, '05:30')
    ->timezone($timezone)
    ->withoutOverlapping();

Schedule::command('logs:prune-retention')
    ->dailyAt('01:45')
    ->timezone($timezone);

Schedule::command('students:promote-adult-guardian')
    ->dailyAt('02:15')
    ->timezone($timezone)
    ->withoutOverlapping();
