<?php

namespace Tests\Feature\Admin;

use App\Models\DelaySetting;
use App\Models\OperationLog;
use App\Models\SchoolHoliday;
use App\Models\User;
use App\Services\SchoolHolidayPdfExtractor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminSchoolHolidaySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_admin_can_create_update_and_delete_school_holidays(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $createResponse = $this->actingAs($admin)->post(route('admin.settings.holidays.store'), [
            'holiday_date' => '2026-04-15',
            'label' => 'Ponte locale',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();
        $this->assertTrue(
            SchoolHoliday::query()
                ->whereDate('holiday_date', '2026-04-15')
                ->where('school_year', '2025-2026')
                ->where('label', 'Ponte locale')
                ->where('source', SchoolHoliday::SOURCE_MANUAL)
                ->exists()
        );

        $holiday = SchoolHoliday::query()->firstOrFail();

        $updateResponse = $this->actingAs($admin)->patch(route('admin.settings.holidays.update', $holiday), [
            'holiday_date' => '2026-04-16',
            'label' => 'Ponte aggiornato',
        ]);

        $updateResponse->assertStatus(302);
        $updateResponse->assertSessionHasNoErrors();
        $this->assertTrue(
            SchoolHoliday::query()
                ->whereKey($holiday->id)
                ->whereDate('holiday_date', '2026-04-16')
                ->where('label', 'Ponte aggiornato')
                ->exists()
        );

        $deleteResponse = $this->actingAs($admin)->delete(route('admin.settings.holidays.destroy', $holiday));
        $deleteResponse->assertStatus(302);
        $deleteResponse->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('school_holidays', [
            'id' => $holiday->id,
        ]);
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'admin.settings.holiday.deleted',
            'entity' => 'school_holiday',
            'entity_id' => $holiday->id,
            'level' => 'WARNING',
        ]);
    }

    public function test_admin_can_import_school_holidays_from_pdf_parser_output(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        SchoolHoliday::query()->create([
            'holiday_date' => '2026-04-11',
            'school_year' => '2025-2026',
            'label' => null,
            'source' => SchoolHoliday::SOURCE_PDF_IMPORT,
        ]);

        app()->instance(SchoolHolidayPdfExtractor::class, new class extends SchoolHolidayPdfExtractor
        {
            public function extract(string $absolutePdfPath): array
            {
                return [
                    'dates' => ['2026-04-12', '2026-04-13'],
                    'metadata' => [
                        'school_year' => '2025-2026',
                        'first_semester_end_date' => '2026-01-23',
                        'second_semester_start_date' => '2026-01-24',
                    ],
                ];
            }
        });

        $response = $this->actingAs($admin)->post(route('admin.settings.holidays.import'), [
            'calendar_pdf' => UploadedFile::fake()->create(
                'calendario.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('school_holidays', [
            'holiday_date' => '2026-04-11',
            'source' => SchoolHoliday::SOURCE_PDF_IMPORT,
        ]);
        $this->assertDatabaseHas('school_holidays', [
            'holiday_date' => '2026-04-12',
            'source' => SchoolHoliday::SOURCE_PDF_IMPORT,
        ]);
        $this->assertDatabaseHas('school_holidays', [
            'holiday_date' => '2026-04-13',
            'source' => SchoolHoliday::SOURCE_PDF_IMPORT,
        ]);
        $delaySetting = DelaySetting::query()->first();
        $this->assertNotNull($delaySetting);
        $this->assertSame(23, $delaySetting->resolvedFirstSemesterEndDay());
        $this->assertSame(1, $delaySetting->resolvedFirstSemesterEndMonth());

        $this->assertTrue(
            OperationLog::query()
                ->where('action', 'admin.settings.holidays.imported')
                ->where('entity', 'settings')
                ->where('level', 'INFO')
                ->exists()
        );
    }
}
