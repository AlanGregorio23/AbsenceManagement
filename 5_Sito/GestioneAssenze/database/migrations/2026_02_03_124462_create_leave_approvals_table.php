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
        Schema::create('leave_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_id')->constrained('leaves')->cascadeOnDelete();
            $table->foreignId('decided_by')->constrained('users')->cascadeOnDelete();

            $table->string('decision'); // approved/rejected
            $table->text('notes')->nullable();
            $table->dateTime('decided_at')->nullable();

            $table->boolean('override_guardian_signature')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_approvals');
    }
};
