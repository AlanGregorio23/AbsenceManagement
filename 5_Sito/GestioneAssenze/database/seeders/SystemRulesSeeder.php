<?php

namespace Database\Seeders;

use App\Models\AbsenceReason;
use App\Models\AbsenceSetting;
use App\Models\DelayRule;
use App\Models\DelaySetting;
use App\Models\OperationLogSetting;
use App\Support\SystemDefaultSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemRulesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedAbsenceSettings();
            $this->seedAbsenceReasons();
            $this->seedDelaySettings();
            $this->seedDelayRules();
            $this->seedOperationLogSettings();
        });
    }

    private function seedAbsenceSettings(): void
    {
        AbsenceSetting::query()->firstOrCreate(
            [],
            SystemDefaultSettings::absenceSettingDefaults()
        );
    }

    private function seedAbsenceReasons(): void
    {
        foreach (SystemDefaultSettings::absenceReasonDefaults() as $reason) {
            AbsenceReason::query()->updateOrCreate(
                ['name' => $reason['name']],
                [
                    'counts_40_hours' => $reason['counts_40_hours'],
                    'requires_management_consent' => (bool) ($reason['requires_management_consent'] ?? false),
                    'requires_document_on_leave_creation' => (bool) ($reason['requires_document_on_leave_creation'] ?? false),
                    'management_consent_note' => isset($reason['management_consent_note'])
                        ? trim((string) $reason['management_consent_note'])
                        : null,
                ]
            );
        }
    }

    private function seedDelaySettings(): void
    {
        DelaySetting::query()->firstOrCreate(
            [],
            SystemDefaultSettings::delaySettingDefaults()
        );
    }

    private function seedDelayRules(): void
    {
        foreach (SystemDefaultSettings::delayRuleDefaults() as $rule) {
            DelayRule::query()->updateOrCreate(
                [
                    'min_delays' => $rule['min_delays'],
                    'max_delays' => $rule['max_delays'],
                ],
                [
                    'actions' => $rule['actions'],
                    'info_message' => $rule['info_message'],
                ]
            );
        }
    }

    private function seedOperationLogSettings(): void
    {
        OperationLogSetting::query()->firstOrCreate(
            [],
            SystemDefaultSettings::operationLogSettingDefaults()
        );
    }
}
