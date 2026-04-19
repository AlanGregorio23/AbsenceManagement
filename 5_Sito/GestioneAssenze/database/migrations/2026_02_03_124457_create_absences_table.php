<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('derived_from_leave_id')->nullable();

            $table->date('start_date');
            $table->date('end_date');

            $table->string('reason')->nullable();
            $table->string('status')->default('reported');

            $table->integer('assigned_hours')->default(0);
            $table->boolean('counts_40_hours')->default(true);
            $table->text('counts_40_hours_comment')->nullable();

            $table->date('medical_certificate_deadline')->nullable();
            $table->boolean('medical_certificate_required')->default(false);
            $table->boolean('approved_without_guardian')->default(false);

            $table->text('teacher_comment')->nullable();
            $table->text('certificate_rejection_comment')->nullable();
            $table->text('deadline_extension_comment')->nullable();
            $table->dateTime('deadline_extended_at')->nullable();
            $table->foreignId('deadline_extended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('auto_arbitrary_at')->nullable();

            $table->dateTime('hours_decided_at')->nullable();
            $table->foreignId('hours_decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
