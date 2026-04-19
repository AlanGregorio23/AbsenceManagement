<?php

namespace Database\Seeders;

use App\Models\Absence;
use App\Models\MedicalCertificate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MedicalCertificateSeeder extends Seeder
{
    public function run(): void
    {
        $validator = User::query()
            ->whereIn('role', ['teacher', 'admin'])
            ->first();

        $absences = Absence::query()
            ->orderByDesc('start_date')
            ->take(3)
            ->get();

        if ($absences->isEmpty()) {
            return;
        }

        foreach ($absences as $index => $absence) {
            $uploadedAt = Carbon::parse($absence->start_date)->addHours(2);
            $shouldValidate = $index === 0 && $validator !== null;

            MedicalCertificate::create([
                'absence_id' => $absence->id,
                'file_path' => 'certificati/CM-'.$absence->id.'.pdf',
                'uploaded_at' => $uploadedAt,
                'valid' => $shouldValidate,
                'validated_by' => $shouldValidate ? $validator->id : null,
                'validated_at' => $shouldValidate ? $uploadedAt->copy()->addDay() : null,
            ]);
        }
    }
}
