<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absence_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('counts_40_hours')->default(true);
            $table->boolean('requires_management_consent')->default(false);
            $table->boolean('requires_document_on_leave_creation')->default(false);
            $table->text('management_consent_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_reasons');
    }
};
