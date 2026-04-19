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
        Schema::create('leave_email_notifications', function (Blueprint $table) {
            $table->id();

            $table->string('type');
            $table->string('recipient_email');
            $table->string('subject');
            $table->longText('body');

            $table->foreignId('leave_id')->nullable()->constrained('leaves')->nullOnDelete();

            $table->dateTime('sent_at')->nullable();
            $table->string('status')->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_email_notifications');
    }
};
