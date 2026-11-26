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
        if (Schema::hasTable('kyc_documents')) {
            Schema::table('kyc_documents', function (Blueprint $table) {
                if (!Schema::hasColumn('kyc_documents', 'passbook')) {
                    $table->text('passbook')->nullable()->after('pan_card');
                }
                if (!Schema::hasColumn('kyc_documents', 'cheque')) {
                    $table->text('cheque')->nullable()->after('passbook');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('kyc_documents')) {
            Schema::table('kyc_documents', function (Blueprint $table) {
                if (Schema::hasColumn('kyc_documents', 'cheque')) {
                    $table->dropColumn('cheque');
                }
                if (Schema::hasColumn('kyc_documents', 'passbook')) {
                    $table->dropColumn('passbook');
                }
            });
        }
    }
};
