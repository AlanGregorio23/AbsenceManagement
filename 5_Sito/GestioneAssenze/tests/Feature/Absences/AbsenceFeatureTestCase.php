<?php

namespace Tests\Feature\Absences;

use App\Models\AbsenceSetting;
use Tests\Feature\Support\LocalDiskFeatureTestCase;

abstract class AbsenceFeatureTestCase extends LocalDiskFeatureTestCase
{
    protected function localTestDiskPrefix(): string
    {
        return 'gestioneassenze-absence-workflow-';
    }

    protected function createAbsenceSetting(array $overrides = []): AbsenceSetting
    {
        return AbsenceSetting::query()->create(array_merge([
            'max_annual_hours' => 40,
            'warning_threshold_hours' => 32,
            'guardian_signature_required' => true,
            'medical_certificate_days' => 3,
            'medical_certificate_max_days' => 5,
            'absence_countdown_days' => 10,
            'pre_expiry_warning_percent' => 80,
        ], $overrides));
    }
}
