<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delay_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_delays')->default(0);
            $table->unsignedInteger('max_delays')->nullable();
            $table->json('actions');
            $table->string('info_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delay_rules');
    }
};
