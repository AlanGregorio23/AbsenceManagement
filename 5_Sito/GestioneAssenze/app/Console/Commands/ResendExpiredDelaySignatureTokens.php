<?php

namespace App\Console\Commands;

use App\Services\DelayGuardianSignatureService;
use Illuminate\Console\Command;

class ResendExpiredDelaySignatureTokens extends Command
{
    protected $signature = 'delays:resend-expired-signature-tokens';

    protected $description = 'Reinvia email firma tutore per ritardi con token scaduti';

    public function handle(DelayGuardianSignatureService $signatureService): int
    {
        $summary = $signatureService->resendExpiredTokensForOpenDelays();

        $this->info(sprintf(
            'Ritardi analizzati: %d, tutori eleggibili: %d, email inviate: %d, fallite: %d, saltate: %d',
            $summary['delays'],
            $summary['guardians'],
            $summary['sent'],
            $summary['failed'],
            $summary['skipped']
        ));

        return self::SUCCESS;
    }
}
