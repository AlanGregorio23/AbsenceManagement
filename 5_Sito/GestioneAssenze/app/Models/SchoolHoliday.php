<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolHoliday extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PDF_IMPORT = 'pdf_import';

    protected $fillable = [
        'holiday_date',
        'school_year',
        'label',
        'source',
        'source_file_path',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
        ];
    }

    public static function schoolYearFromDate(Carbon|string $date): string
    {
        $resolvedDate = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();
        $year = (int) $resolvedDate->year;

        if ((int) $resolvedDate->month >= 8) {
            return $year.'-'.($year + 1);
        }

        return ($year - 1).'-'.$year;
    }
}
