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
        Schema::create('delays', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();

            $table->dateTime('delay_datetime');
            $table->integer('minutes');
            $table->date('justification_deadline')->nullable();

            $table->string('notes')->nullable();
            $table->text('teacher_comment')->nullable();
            $table->dateTime('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('auto_arbitrary_at')->nullable();
            $table->string('status')->default('reported');

            $table->boolean('count_in_semester')->default(false);
            $table->string('exclusion_comment')->nullable();
            $table->boolean('global')->default(false);
            $table->boolean('converted_to_absence')->default(false);
            $table->foreignId('converted_absence_id')->nullable()->constrained('absences')->nullOnDelete();

            $table->timestamps();

            $table->index(['student_id', 'status'], 'delays_student_status_idx');
            $table->index(['status', 'justification_deadline'], 'delays_status_deadline_idx');
            $table->index(
                ['student_id', 'count_in_semester', 'status', 'delay_datetime'],
                'delays_student_count_status_datetime_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delays');
    }
};
