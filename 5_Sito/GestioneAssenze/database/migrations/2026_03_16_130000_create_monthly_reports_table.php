<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->date('report_month');
            $table->string('status')->default('generated');
            $table->json('summary_json')->nullable();
            $table->string('system_pdf_path')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('signed_uploaded_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'report_month'], 'uniq_monthly_reports_student_month');
            $table->index(['status', 'report_month'], 'monthly_reports_status_month_idx');
            $table->index(['class_id', 'report_month'], 'monthly_reports_class_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
    }
};
