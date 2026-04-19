<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absence_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('max_annual_hours')->default(40);
            $table->unsignedInteger('warning_threshold_hours')->default(32);
            $table->string('vice_director_email')->nullable();
            $table->unsignedInteger('student_status_warning_percent')->default(80);
            $table->unsignedInteger('student_status_critical_percent')->default(100);
            $table->boolean('guardian_signature_required')->default(true);
            $table->unsignedInteger('medical_certificate_days')->default(3);
            $table->unsignedInteger('medical_certificate_max_days')->default(5);
            $table->unsignedInteger('absence_countdown_days')->default(10);
            $table->unsignedInteger('leave_request_notice_working_hours')->default(24);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_settings');
    }
};
