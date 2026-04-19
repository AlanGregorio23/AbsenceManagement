<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_delay_confirmations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delay_id')->constrained('delays')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();

            $table->string('status')->default('pending');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique('delay_id', 'uniq_delay_single_signature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_delay_confirmations');
    }
};
