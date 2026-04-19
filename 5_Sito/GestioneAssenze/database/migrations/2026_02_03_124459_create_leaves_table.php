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
        Schema::create('leaves', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->dateTime('created_at_custom'); // perché timestamps hanno già created_at
            $table->date('start_date');
            $table->date('end_date');

            $table->integer('requested_hours');
            $table->boolean('hours_limit_exceeded_at_request')->default(false);
            $table->unsignedInteger('hours_limit_value_at_request')->default(0);
            $table->unsignedInteger('hours_limit_max_at_request')->default(0);
            $table->string('requested_lessons')->nullable();

            $table->string('destination')->nullable();
            $table->string('reason')->nullable();
            $table->string('status')->default('awaiting_guardian_signature');
            $table->boolean('approved_without_guardian')->default(false);

            $table->boolean('count_hours')->default(true);
            $table->text('count_hours_comment')->nullable();
            $table->text('workflow_comment')->nullable();
            $table->text('documentation_request_comment')->nullable();
            $table->string('documentation_path')->nullable();
            $table->dateTime('documentation_uploaded_at')->nullable();

            $table->dateTime('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('registered_absence_id')->nullable()->constrained('absences')->nullOnDelete();

            $table->dateTime('hours_decision_at')->nullable();
            $table->foreignId('hours_decision_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaves');
    }
};
