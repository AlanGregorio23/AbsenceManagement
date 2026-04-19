<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delay_confirmation_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delay_id')->constrained('delays')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();

            $table->string('token_hash');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();

            $table->timestamps();

            $table->unique('token_hash', 'delay_confirmation_tokens_token_hash_unique');
            $table->index(
                ['delay_id', 'guardian_id', 'used_at', 'expires_at'],
                'delay_confirmation_tokens_delay_guardian_state_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delay_confirmation_tokens');
    }
};
