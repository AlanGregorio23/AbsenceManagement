<?php

namespace App\Console\Commands;

use App\Services\MonthlyReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class GenerateMonthlyReports extends Command
{
    protected $signature = 'reports:generate-monthly {--month= : Mese riferimento formato YYYY-MM}';

    protected $description = 'Genera i report mensili per tutti gli studenti e invia email a studenti/tutori';

    public function handle(MonthlyReportService $service): int
    {
        $month = $this->resolveMonth();
        if (! $month) {
            return self::FAILURE;
        }

        $summary = $service->queueGenerationForMonth($month);

        $this->info('Generazione report mensili messa in coda.');
        $this->line('Mese: '.$summary['month']);
        $this->line('Studenti processati: '.$summary['students']);

        return self::SUCCESS;
    }

    private function resolveMonth(): ?Carbon
    {
        $option = trim((string) $this->option('month'));
        if ($option === '') {
            return Carbon::today()->startOfMonth()->subMonth();
        }

        try {
            return Carbon::createFromFormat('Y-m', $option)->startOfMonth();
        } catch (Throwable) {
            $this->error('Formato mese non valido. Usa YYYY-MM.');

            return null;
        }
    }
}
