<?php

namespace App\Console\Commands;

use App\Models\OperationLog;
use Illuminate\Console\Command;

class PruneOperationLogs extends Command
{
    protected $signature = 'logs:prune-retention';

    protected $description = 'Elimina i log operativi oltre la retention configurata';

    public function handle(): int
    {
        $result = OperationLog::pruneByConfiguredRetention();

        $this->info(
            'Prune completato. INFO eliminati: '.$result['info_deleted']
            .' | WARNING/ERROR eliminati: '.$result['error_deleted']
        );
        $this->line(
            'Cutoff INFO: '.$result['interaction_cutoff']
            .' | Cutoff WARNING/ERROR: '.$result['error_cutoff']
        );

        return self::SUCCESS;
    }
}
