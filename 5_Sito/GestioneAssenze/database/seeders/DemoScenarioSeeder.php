<?php

namespace Database\Seeders;

use App\Models\Absence;
use App\Models\Delay;
use App\Models\Guardian;
use App\Models\GuardianAbsenceConfirmation;
use App\Models\GuardianLeaveConfirmation;
use App\Models\Leave;
use App\Models\MonthlyReport;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoScenarioSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'Trevano26!';

    public function run(): void
    {
        $today = Carbon::today();
        $reportMonth = Carbon::create(2026, 4, 1)->startOfMonth();
        $schoolYearStart = Carbon::create(2025, 9, 1)->startOfDay();

        $student = $this->upsertUser([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'email' => 'alan.gregorio@example.com',
            'role' => 'student',
            'birth_date' => '2010-03-11',
        ]);

        $teacher = $this->upsertUser([
            'name' => 'Paolo',
            'surname' => 'Rossi',
            'email' => 'paolo.rossi@example.com',
            'role' => 'teacher',
            'birth_date' => '1984-02-17',
        ]);

        $laboratoryManager = $this->upsertUser([
            'name' => 'Luca',
            'surname' => 'Galli',
            'email' => 'luca.galli@example.com',
            'role' => 'laboratory_manager',
            'birth_date' => '1982-07-01',
        ]);

        $guardian = Guardian::query()->updateOrCreate(
            ['email' => 'alan.gregorio@icloud.com'],
            ['name' => 'Mario Gregorio']
        );

        $student->allGuardians()->sync([
            $guardian->id => [
                'relationship' => 'Padre',
                'is_primary' => true,
                'is_active' => true,
                'deactivated_at' => null,
            ],
        ]);

        $class = SchoolClass::query()->updateOrCreate(
            [
                'name' => 'Informatica',
                'year' => '2',
                'section' => 'I',
            ],
            ['active' => true]
        );

        $this->assignDemoClass($class, $student, $teacher, $schoolYearStart);
        $this->resetStudentDemoRecords($student);

        $this->seedClosedAbsenceForReport($student, $teacher, $guardian, $reportMonth);
        $this->seedOpenAbsenceFlow($student, $guardian, $reportMonth);
        $this->seedRegisteredDelayForReport($student, $teacher, $reportMonth);
        $this->seedRegisteredLeaveDraft($student, $laboratoryManager, $guardian, $today);

        if ($teacher->id <= 0 || $laboratoryManager->id <= 0) {
            $this->command?->warn('Seed demo incompleto: controllare la creazione dei ruoli base.');
        }
    }

    private function assignDemoClass(
        SchoolClass $class,
        User $student,
        User $teacher,
        Carbon $schoolYearStart
    ): void {
        DB::table('class_user')
            ->where('user_id', $student->id)
            ->delete();

        DB::table('class_user')->insert([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'start_date' => $schoolYearStart->toDateString(),
            'end_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('class_teacher')->updateOrInsert(
            [
                'class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'start_date' => $schoolYearStart->toDateString(),
            ],
            [
                'end_date' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function resetStudentDemoRecords(User $student): void
    {
        MonthlyReport::query()
            ->where('student_id', $student->id)
            ->delete();

        Delay::query()
            ->where('student_id', $student->id)
            ->delete();

        Absence::query()
            ->where('student_id', $student->id)
            ->delete();

        Leave::query()
            ->where('student_id', $student->id)
            ->delete();
    }

    private function seedClosedAbsenceForReport(
        User $student,
        User $teacher,
        Guardian $guardian,
        Carbon $reportMonth
    ): void {
        $date = $reportMonth->copy()->addDays(4);
        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
            'reason' => 'Motivi familiari',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 6,
            'counts_40_hours' => true,
            'counts_40_hours_comment' => null,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => Absence::calculateMedicalCertificateDeadline($date)->toDateString(),
            'approved_without_guardian' => false,
            'teacher_comment' => 'Assenza validata dal docente per il report demo.',
            'hours_decided_at' => $date->copy()->addDay()->setTime(10, 0),
            'hours_decided_by' => $teacher->id,
        ]);

        $this->seedGuardianAbsenceSignature($absence, $guardian, $date->copy()->setTime(18, 15));
    }

    private function seedOpenAbsenceFlow(
        User $student,
        Guardian $guardian,
        Carbon $reportMonth
    ): void {
        $date = $reportMonth->copy()->addDays(22);
        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
            'reason' => 'Appuntamento personale',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 4,
            'counts_40_hours' => false,
            'counts_40_hours_comment' => null,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => Absence::calculateMedicalCertificateDeadline($date)->toDateString(),
            'approved_without_guardian' => false,
            'teacher_comment' => null,
            'hours_decided_at' => null,
            'hours_decided_by' => null,
        ]);

        $this->seedGuardianAbsenceSignature($absence, $guardian, $date->copy()->setTime(18, 0));
    }

    private function seedRegisteredDelayForReport(
        User $student,
        User $teacher,
        Carbon $reportMonth
    ): void {
        $date = $reportMonth->copy()->addDays(15)->setTime(8, 10);

        Delay::query()->create([
            'student_id' => $student->id,
            'recorded_by' => $teacher->id,
            'delay_datetime' => $date->toDateTimeString(),
            'minutes' => 12,
            'justification_deadline' => null,
            'notes' => 'Mezzi pubblici in ritardo',
            'teacher_comment' => 'Ritardo registrato nel semestre.',
            'status' => Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
            'exclusion_comment' => null,
            'global' => false,
            'validated_at' => $date->copy()->setTime(10, 0),
            'validated_by' => $teacher->id,
            'auto_arbitrary_at' => null,
        ]);
    }

    private function seedRegisteredLeaveDraft(
        User $student,
        User $laboratoryManager,
        Guardian $guardian,
        Carbon $today
    ): void {
        $registeredDraftLeave = Leave::query()->create([
            'student_id' => $student->id,
            'created_by' => $student->id,
            'created_at_custom' => $today->copy()->subDays(2)->setTime(9, 15),
            'start_date' => $today->toDateString(),
            'end_date' => $today->toDateString(),
            'requested_hours' => 2,
            'requested_lessons' => ['start' => [3, 4], 'end' => [3, 4]],
            'reason' => 'Appuntamento amministrativo',
            'destination' => 'Ufficio comunale',
            'status' => Leave::STATUS_REGISTERED,
            'approved_without_guardian' => false,
            'count_hours' => true,
            'count_hours_comment' => null,
            'workflow_comment' => 'Congedo approvato e registrato come bozza assenza demo.',
            'documentation_request_comment' => null,
            'documentation_path' => null,
            'documentation_uploaded_at' => null,
            'registered_at' => $today->copy()->setTime(8, 30),
            'registered_by' => $laboratoryManager->id,
            'registered_absence_id' => null,
            'hours_decision_at' => $today->copy()->subDay()->setTime(14, 0),
            'hours_decision_by' => $laboratoryManager->id,
        ]);

        $registeredDraftAbsence = Absence::query()->create([
            'student_id' => $student->id,
            'derived_from_leave_id' => $registeredDraftLeave->id,
            'start_date' => $registeredDraftLeave->start_date?->toDateString(),
            'end_date' => $registeredDraftLeave->end_date?->toDateString(),
            'reason' => (string) $registeredDraftLeave->reason,
            'status' => Absence::STATUS_DRAFT,
            'assigned_hours' => (int) $registeredDraftLeave->requested_hours,
            'counts_40_hours' => (bool) $registeredDraftLeave->count_hours,
            'counts_40_hours_comment' => null,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => Absence::calculateMedicalCertificateDeadline($today)->toDateString(),
            'approved_without_guardian' => false,
            'teacher_comment' => 'Generata da congedo C-'.str_pad((string) $registeredDraftLeave->id, 4, '0', STR_PAD_LEFT).'. Bozza in attesa di invio studente come assenza ufficiale.',
            'hours_decided_at' => null,
            'hours_decided_by' => null,
        ]);

        $registeredDraftLeave->update([
            'registered_absence_id' => $registeredDraftAbsence->id,
        ]);

        GuardianLeaveConfirmation::query()->create([
            'leave_id' => $registeredDraftLeave->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
            'confirmed_at' => $today->copy()->subDay()->setTime(18, 10),
            'signed_at' => $today->copy()->subDay()->setTime(18, 10),
            'signature_path' => null,
            'ip_address' => '127.0.0.1',
            'notes' => json_encode([
                'signer_name' => 'Mario Gregorio',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function seedGuardianAbsenceSignature(
        Absence $absence,
        Guardian $guardian,
        Carbon $signedAt
    ): void {
        GuardianAbsenceConfirmation::query()->create([
            'absence_id' => $absence->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
            'confirmed_at' => $signedAt,
            'signed_at' => $signedAt,
            'signature_path' => null,
            'ip_address' => '127.0.0.1',
            'notes' => json_encode([
                'signer_name' => 'Mario Gregorio',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function upsertUser(array $attributes): User
    {
        return User::query()->updateOrCreate(
            ['email' => $attributes['email']],
            [
                'name' => $attributes['name'],
                'surname' => $attributes['surname'],
                'role' => $attributes['role'],
                'birth_date' => $attributes['birth_date'],
                'password' => Hash::make(self::DEMO_PASSWORD),
                'active' => true,
            ]
        );
    }
}
