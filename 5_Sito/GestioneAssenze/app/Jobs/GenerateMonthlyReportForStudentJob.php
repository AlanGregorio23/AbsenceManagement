<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\MonthlyReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMonthlyReportForStudentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $studentId,
        public string $month,
        public ?int $actorUserId = null
    ) {}

    public function handle(MonthlyReportService $service): void
    {
        $month = Carbon::parse($this->month)->startOfMonth();
        $actor = $this->actorUserId
            ? User::query()->find($this->actorUserId)
            : null;

        $service->generateAndSendForStudent($this->studentId, $month, $actor);
    }
}
