<?php

namespace App\Support;

use App\Models\AbsenceSetting;
use App\Models\DelaySetting;
use App\Models\LoginSecuritySetting;
use App\Models\OperationLogSetting;

class SystemSettingsResolver
{
    public static function absenceSetting(): AbsenceSetting
    {
        return AbsenceSetting::query()->firstOrCreate(
            [],
            SystemDefaultSettings::absenceSettingDefaults()
        );
    }

    public static function delaySetting(): DelaySetting
    {
        return DelaySetting::query()->firstOrCreate(
            [],
            SystemDefaultSettings::delaySettingDefaults()
        );
    }

    public static function operationLogSetting(): OperationLogSetting
    {
        return OperationLogSetting::query()->firstOrCreate(
            [],
            SystemDefaultSettings::operationLogSettingDefaults()
        );
    }

    public static function loginSecuritySetting(): LoginSecuritySetting
    {
        return LoginSecuritySetting::query()->firstOrCreate(
            [],
            SystemDefaultSettings::loginSecuritySettingDefaults()
        );
    }
}
