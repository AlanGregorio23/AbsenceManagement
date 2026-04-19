<?php

namespace App\Http\Controllers;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $DashboardStudentController = new DashboardStudentController;
        $DashboardAdminController = new DashboardAdminController;
        $TeacherAdminController = new DashboardTeacherController;
        $LaboratoryManagerController = new DashboardLaboratoryManagerController;

        return match (true) {
            $user->hasRole('admin') => $DashboardAdminController->index(),
            $user->hasRole('teacher') => $TeacherAdminController->index(),
            $user->hasRole('laboratory_manager') => $LaboratoryManagerController->index(),
            default => $DashboardStudentController->index(),
        };
    }
}
