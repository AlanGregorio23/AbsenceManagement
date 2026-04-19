<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class NotificationRecipientResolver
{
    public function teachersForStudent(int $studentId): Collection
    {
        return User::query()
            ->select('users.*')
            ->join('class_teacher', 'class_teacher.teacher_id', '=', 'users.id')
            ->join('class_user', 'class_user.class_id', '=', 'class_teacher.class_id')
            ->where('class_user.user_id', $studentId)
            ->where('users.role', 'teacher')
            ->where('users.active', true)
            ->distinct()
            ->get();
    }

    public function teachersForClass(?int $classId): Collection
    {
        if (! $classId) {
            return new Collection;
        }

        return User::query()
            ->select('users.*')
            ->join('class_teacher', 'class_teacher.teacher_id', '=', 'users.id')
            ->where('class_teacher.class_id', $classId)
            ->where('users.role', 'teacher')
            ->where('users.active', true)
            ->distinct()
            ->get();
    }

    public function laboratoryManagers(): Collection
    {
        return User::query()
            ->where('role', 'laboratory_manager')
            ->where('active', true)
            ->get();
    }

    public function admins(): Collection
    {
        return User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->get();
    }
}
