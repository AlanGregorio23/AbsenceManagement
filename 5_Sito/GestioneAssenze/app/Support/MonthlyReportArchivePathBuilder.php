<?php

namespace App\Support;

use Carbon\Carbon;

class MonthlyReportArchivePathBuilder
{
    public static function originalPath(int $studentId, Carbon $month): string
    {
        return static::baseDirectory($studentId, $month)
            .'/report_mensile_'
            .$month->format('Y_m')
            .'.pdf';
    }

    public static function signedPath(int $studentId, Carbon $month, string $extension = 'pdf'): string
    {
        return static::baseDirectory($studentId, $month)
            .'/firmati/report_mensile_'
            .$month->format('Y_m')
            .'_firmato.'
            .static::normalizeExtension($extension);
    }

    public static function isArchivePath(string $path): bool
    {
        return str_starts_with(static::normalizePath($path), 'archivio/');
    }

    public static function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    public static function normalizeExtension(string $extension): string
    {
        $normalized = strtolower(trim($extension));
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : 'pdf';
    }

    private static function baseDirectory(int $studentId, Carbon $month): string
    {
        return 'archivio/'
            .max($studentId, 0)
            .'/report_mensili/'
            .$month->format('Y')
            .'/'
            .$month->format('m');
    }
}
