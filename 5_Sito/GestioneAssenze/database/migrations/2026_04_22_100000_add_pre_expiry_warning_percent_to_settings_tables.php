<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absence_settings', function (Blueprint $table) {
            $table->unsignedInteger('pre_expiry_warning_percent')
                ->default(80)
                ->after('absence_countdown_days');
        });

        Schema::table('delay_settings', function (Blueprint $table) {
            $table->unsignedInteger('pre_expiry_warning_percent')
                ->default(80)
                ->after('pre_expiry_warning_business_days');
        });

        DB::table('absence_settings')
            ->whereNull('pre_expiry_warning_percent')
            ->update(['pre_expiry_warning_percent' => 80]);

        DB::table('delay_settings')
            ->whereNull('pre_expiry_warning_percent')
            ->update(['pre_expiry_warning_percent' => 80]);
    }

    public function down(): void
    {
        Schema::table('absence_settings', function (Blueprint $table) {
            $table->dropColumn('pre_expiry_warning_percent');
        });

        Schema::table('delay_settings', function (Blueprint $table) {
            $table->dropColumn('pre_expiry_warning_percent');
        });
    }
};
