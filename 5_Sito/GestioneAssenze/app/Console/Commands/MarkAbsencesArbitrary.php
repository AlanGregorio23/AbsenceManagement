<?php

namespace App\Console\Commands;

use App\Models\Absence;
use Illuminate\Console\Command;

class MarkAbsencesArbitrary extends Command
{
    protected $signature = 'absences:mark-arbitrary';

    protected $description = 'Imposta come arbitrarie le assenze scadute ancora aperte';

    public function handle(): int
    {
        $updated = Absence::applyAutomaticArbitrary();

        $this->info('Assenze impostate come arbitrarie: '.$updated);

        return self::SUCCESS;
    }
}
