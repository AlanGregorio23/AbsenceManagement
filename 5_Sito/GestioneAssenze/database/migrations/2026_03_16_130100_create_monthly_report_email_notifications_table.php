<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_report_email_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_report_id')
                ->constrained('monthly_reports')
                ->cascadeOnDelete();
            $table->string('type')->default('initial_send');
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('body')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['monthly_report_id', 'type'], 'monthly_report_email_type_idx');
            $table->index('recipient_email', 'monthly_report_email_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_email_notifications');
    }
};
