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
        Schema::create('absence_email_notifications', function (Blueprint $table) {
            $table->id();

            $table->string('type');
            $table->string('recipient_email');
            $table->string('subject');
            $table->longText('body');

            $table->foreignId('absence_id')->nullable()->constrained('absences')->nullOnDelete();

            $table->dateTime('sent_at')->nullable();
            $table->string('status')->default('pending'); // pending/sent/failed

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absence_email_notifications');
    }
};
