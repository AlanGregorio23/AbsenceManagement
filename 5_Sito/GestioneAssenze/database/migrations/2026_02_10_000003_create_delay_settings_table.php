<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delay_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('minutes_threshold')->default(15);
            $table->boolean('guardian_signature_required')->default(true);
            $table->boolean('deadline_active')->default(false);
            $table->unsignedInteger('deadline_business_days')->default(5);
            $table->unsignedInteger('justification_business_days')->default(5);
            $table->unsignedInteger('pre_expiry_warning_business_days')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delay_settings');
    }
};
