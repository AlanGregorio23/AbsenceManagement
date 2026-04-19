<?php

namespace App\Support;

use App\Models\AbsenceSetting;

class AnnualHoursLimitLabels
{
    public static function limit(?AbsenceSetting $setting = null): string
    {
        $hours = self::maxAnnualHours($setting);

        if ($hours <= 0) {
            return 'limite ore annuale';
        }

        return 'limite annuale di '.$hours.' '.self::hourUnit($hours);
    }

    public static function included(?AbsenceSetting $setting = null): string
    {
        return 'Rientra nel '.self::limit($setting);
    }

    public static function excluded(?AbsenceSetting $setting = null): string
    {
        return 'Esclusa dal '.self::limit($setting);
    }

    public static function excludedMasculine(?AbsenceSetting $setting = null): string
    {
        return 'Escluso dal '.self::limit($setting);
    }

    public static function countedLabel(?AbsenceSetting $setting = null): string
    {
        return 'Conteggio '.self::limit($setting);
    }

    public static function countedNoteLabel(?AbsenceSetting $setting = null): string
    {
        return 'Nota conteggio '.self::limit($setting);
    }

    public static function ruleReasonComment(?AbsenceSetting $setting = null, bool $feminine = true): string
    {
        $prefix = $feminine ? self::excluded($setting) : self::excludedMasculine($setting);

        return $prefix.' per regola configurata sul motivo.';
    }

    public static function certificateAcceptedComment(?AbsenceSetting $setting = null): string
    {
        return self::excluded($setting).' per certificato medico accettato.';
    }

    public static function teacherDecisionComment(?AbsenceSetting $setting = null): string
    {
        return self::excluded($setting).' per decisione docente.';
    }

    public static function pendingTeacherValidationComment(?AbsenceSetting $setting = null): string
    {
        return 'In attesa validazione docente: non conteggiata nel '.self::limit($setting).'.';
    }

    public static function labDecisionComment(?AbsenceSetting $setting = null): string
    {
        return self::excludedMasculine($setting).' per decisione capo laboratorio.';
    }

    public static function leaveExceptionComment(?AbsenceSetting $setting = null): string
    {
        return self::excluded($setting).' per eccezione congedo.';
    }

    private static function maxAnnualHours(?AbsenceSetting $setting): int
    {
        $setting ??= SystemSettingsResolver::absenceSetting();

        return max((int) $setting->max_annual_hours, 0);
    }

    private static function hourUnit(int $hours): string
    {
        return $hours === 1 ? 'ora' : 'ore';
    }
}
