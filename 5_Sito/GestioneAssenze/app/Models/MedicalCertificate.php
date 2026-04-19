<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicalCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'absence_id',
        'file_path',
        'uploaded_at',
        'valid',
        'validated_by',
        'validated_at',
    ];

    public function absence()
    {
        return $this->belongsTo(Absence::class);
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'valid' => 'boolean',
            'validated_at' => 'datetime',
        ];
    }

    public function getMedicalCertificate(?User $user = null)
    {
        $query = MedicalCertificate::query()->with('absence');

        if ($user) {
            $query->whereHas('absence', function ($builder) use ($user) {
                $builder->where('student_id', $user->id);
            });
        }

        return $query->orderByDesc('uploaded_at')->get();
    }

    public function getMedicalCertificateItems(?User $user = null)
    {
        return $this->getMedicalCertificate($user)
            ->map(function (MedicalCertificate $certificate) {
                $absence = $certificate->absence;
                $status = $certificate->valid ? 'Verificato' : 'In revisione';
                $badge = $certificate->valid
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-amber-100 text-amber-700';

                return [
                    'id' => 'CM-'.str_pad((string) $certificate->id, 4, '0', STR_PAD_LEFT),
                    'nome' => basename($certificate->file_path),
                    'tipo' => 'Certificato medico',
                    'origine' => 'Assenza',
                    'stato' => $status,
                    'badge' => $badge,
                    'data' => $certificate->uploaded_at?->format('d M Y'),
                    'sort_date' => $certificate->uploaded_at?->toDateTimeString(),
                    'assenza_id' => $absence?->id,
                ];
            });
    }
}
