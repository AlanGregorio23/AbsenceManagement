<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEY = 'absences_derived_from_leave_id_foreign';

    public function up(): void
    {
        if ($this->foreignKeyExists()) {
            return;
        }

        Schema::table('absences', function (Blueprint $table) {
            $table
                ->foreign('derived_from_leave_id', self::FOREIGN_KEY)
                ->references('id')
                ->on('leaves')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->foreignKeyExists()) {
            return;
        }

        Schema::table('absences', function (Blueprint $table) {
            $table->dropForeign(self::FOREIGN_KEY);
        });
    }

    private function foreignKeyExists(): bool
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.referential_constraints')
                ->whereRaw('constraint_schema = database()')
                ->where('constraint_name', self::FOREIGN_KEY)
                ->exists();
        }

        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA foreign_key_list(absences)') as $foreignKey) {
                if (($foreignKey->from ?? null) === 'derived_from_leave_id'
                    && ($foreignKey->table ?? null) === 'leaves') {
                    return true;
                }
            }
        }

        return false;
    }
};
