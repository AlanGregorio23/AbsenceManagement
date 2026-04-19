<?php

namespace App\Console\Commands;

use App\Models\Delay;
use Illuminate\Console\Command;

class MarkDelaysArbitrary extends Command
{
    protected $signature = 'delays:mark-arbitrary';

    protected $description = 'Registra automaticamente i ritardi scaduti ancora aperti';

    public function handle(): int
    {
        $updated = Delay::applyAutomaticArbitrary();

        $this->info('Ritardi registrati automaticamente per scadenza: '.$updated);

        return self::SUCCESS;
    }
}
