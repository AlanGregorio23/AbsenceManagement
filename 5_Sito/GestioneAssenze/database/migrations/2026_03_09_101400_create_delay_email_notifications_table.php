<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delay_email_notifications', function (Blueprint $table) {
            $table->id();

            $table->string('type');
            $table->string('recipient_email');
            $table->string('subject');
            $table->longText('body');

            $table->foreignId('delay_id')->nullable()->constrained('delays')->nullOnDelete();

            $table->dateTime('sent_at')->nullable();
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index(['delay_id', 'type', 'recipient_email'], 'delay_email_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delay_email_notifications');
    }
};
