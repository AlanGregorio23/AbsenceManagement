<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_log_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('interaction_retention_days')->default(425);
            $table->unsignedInteger('error_retention_days')->default(425);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_log_settings');
    }
};
