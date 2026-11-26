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
        if (Schema::hasTable('policies') && !Schema::hasColumn('policies', 'agent_commission')) {
            Schema::table('policies', function (Blueprint $table) {
                $afterCol = Schema::hasColumn('policies', 'payment_amount') ? 'payment_amount' : null;
                $col = $table->decimal('agent_commission', 15, 2)->nullable();
                if ($afterCol) {
                    $col->after($afterCol);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('policies') && Schema::hasColumn('policies', 'agent_commission')) {
            Schema::table('policies', function (Blueprint $table) {
                $table->dropColumn('agent_commission');
            });
        }
    }
};
