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
        Schema::create('guardian_absence_confirmations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('absence_id')->constrained('absences')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();

            $table->string('status')->default('pending'); // confirmed/rejected/pending
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['absence_id', 'guardian_id'], 'uniq_absence_guardian');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardian_absence_confirmations');
    }
};
