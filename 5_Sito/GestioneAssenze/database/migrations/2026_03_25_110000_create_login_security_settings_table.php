<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_security_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedInteger('decay_seconds')->default(300);
            $table->unsignedTinyInteger('forgot_password_max_attempts')->default(6);
            $table->unsignedInteger('forgot_password_decay_seconds')->default(60);
            $table->unsignedTinyInteger('reset_password_max_attempts')->default(6);
            $table->unsignedInteger('reset_password_decay_seconds')->default(60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_security_settings');
    }
};
