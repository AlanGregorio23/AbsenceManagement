<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delay_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('first_semester_end_day')
                ->default(26)
                ->after('pre_expiry_warning_percent');
            $table->unsignedTinyInteger('first_semester_end_month')
                ->default(1)
                ->after('first_semester_end_day');
        });

        DB::table('delay_settings')
            ->whereNull('first_semester_end_day')
            ->update(['first_semester_end_day' => 26]);

        DB::table('delay_settings')
            ->whereNull('first_semester_end_month')
            ->update(['first_semester_end_month' => 1]);
    }

    public function down(): void
    {
        Schema::table('delay_settings', function (Blueprint $table) {
            $table->dropColumn([
                'first_semester_end_day',
                'first_semester_end_month',
            ]);
        });
    }
};
