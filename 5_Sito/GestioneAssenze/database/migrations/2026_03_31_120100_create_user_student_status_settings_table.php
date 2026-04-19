<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_student_status_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('absence_warning_percent')->default(80);
            $table->unsignedTinyInteger('absence_critical_percent')->default(100);
            $table->unsignedTinyInteger('delay_warning_percent')->default(80);
            $table->unsignedTinyInteger('delay_critical_percent')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_student_status_settings');
    }
};
