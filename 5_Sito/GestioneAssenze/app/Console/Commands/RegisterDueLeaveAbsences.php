<?php

namespace App\Console\Commands;

use App\Services\LeaveAbsenceDraftService;
use Illuminate\Console\Command;

class RegisterDueLeaveAbsences extends Command
{
    protected $signature = 'leaves:register-due-absences';

    protected $description = 'Crea le bozze assenza per i congedi registrati con data inizio raggiunta';

    public function handle(LeaveAbsenceDraftService $leaveAbsenceDraftService): int
    {
        $registered = $leaveAbsenceDraftService->registerDueLeaves();

        $this->info('Bozze assenza generate da congedi: '.$registered);

        return self::SUCCESS;
    }
}
