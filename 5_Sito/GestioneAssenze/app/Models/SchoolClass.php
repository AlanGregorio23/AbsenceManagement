<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'name',
        'year',
        'section',
        'active',
    ];

    public function students()
    {
        return $this->belongsToMany(
            User::class,
            'class_user',
            'class_id',
            'user_id'
        )->withPivot(['start_date', 'end_date'])
            ->withTimestamps();
    }

    public function teachers()
    {
        return $this->belongsToMany(User::class, 'class_teacher', 'class_id', 'teacher_id')
            ->withPivot(['start_date', 'end_date'])
            ->withTimestamps();
    }

    public function monthlyReports()
    {
        return $this->hasMany(MonthlyReport::class, 'class_id');
    }

    public function getTeacherAttribute()
    {
        if ($this->relationLoaded('teachers')) {
            return $this->teachers
                ->sortByDesc(fn ($teacher) => $teacher->pivot?->start_date)
                ->first();
        }

        return $this->teachers()
            ->orderByPivot('start_date', 'desc')
            ->first();
    }

    public function getClassCodeAttribute(): string
    {
        $parts = array_values(array_filter([
            strtoupper(trim((string) ($this->section ?? ''))),
            trim((string) ($this->year ?? '')),
            strtoupper(trim((string) ($this->name ?? ''))),
        ], static fn (string $value) => $value !== ''));

        return implode('', $parts);
    }

    public function getClasses()
    {
        $classes = SchoolClass::query()
            ->with([
                'teachers' => function ($query) {
                    $query->orderByPivot('start_date', 'desc');
                },
                'students',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (SchoolClass $class) {

                return [
                    'id' => 'C-'.str_pad((string) $class->id, 4, '0', STR_PAD_LEFT),
                    'nome' => $class->name,
                    'docente' => $class->teacher?->name ?? 'nessun docente assegnato',
                    'anno' => $class->year,
                    'studenti' => $class->students->count(),
                    'sezione' => $class->section,
                    'creato_il' => $class->created_at->format('d M Y'),
                ];
            });

        return $classes;
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
