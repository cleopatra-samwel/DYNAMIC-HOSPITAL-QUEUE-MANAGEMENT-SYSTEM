<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_COUNTER_BY_DEPT_CODE = [
        'REG' => 1,
        'CONS' => 2,
        'LAB' => 3,
        'PHARM' => 4,
        'BILL' => 5,
    ];

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->integer('counter_number')->nullable()->after('dept_name');
        });

        // Backfills any departments already in the database (this app has
        // been seeded/used across several earlier phases) — DepartmentSeeder
        // alone wouldn't reach existing rows since it seeds via
        // firstOrCreate/updateOrCreate keyed on dept_code, not a fresh insert.
        foreach (self::DEFAULT_COUNTER_BY_DEPT_CODE as $deptCode => $counterNumber) {
            DB::table('departments')->where('dept_code', $deptCode)->update(['counter_number' => $counterNumber]);
        }
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('counter_number');
        });
    }
};
