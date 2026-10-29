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
        if (Schema::hasTable('commission_rules') && !Schema::hasColumn('commission_rules', 'policy_id')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('policy_id')->nullable()->after('policy_sub_type_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('commission_rules') && Schema::hasColumn('commission_rules', 'policy_id')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->dropColumn('policy_id');
            });
        }
    }
};

