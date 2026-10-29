<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('policies') && !Schema::hasColumn('policies', 'policy_number_no')) {
            Schema::table('policies', function (Blueprint $table) {
                $table->string('policy_number_no')->nullable()->after('policy_number');
            });

            // Backfill existing records with policy_number
            try {
                DB::table('policies')
                    ->whereNull('policy_number_no')
                    ->update(['policy_number_no' => DB::raw('policy_number')]);
            } catch (\Throwable) {
                // Continue if raw update fails in specific DB driver
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('policies') && Schema::hasColumn('policies', 'policy_number_no')) {
            Schema::table('policies', function (Blueprint $table) {
                $table->dropColumn('policy_number_no');
            });
        }
    }
};

