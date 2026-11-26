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
                if (!Schema::hasColumn('kyc_documents', 'aadhaar_number')) {
                    $table->string('aadhaar_number', 50)->nullable()->after('aadhaar_card');
                }
                if (!Schema::hasColumn('kyc_documents', 'pan_number')) {
                    $table->string('pan_number', 50)->nullable()->after('pan_card');
                }
                if (!Schema::hasColumn('kyc_documents', 'account_number')) {
                    $table->string('account_number', 50)->nullable()->after('passbook');
                }
                if (!Schema::hasColumn('kyc_documents', 'cheque_number')) {
                    $table->string('cheque_number', 50)->nullable()->after('cheque');
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
                $columns = ['aadhaar_number', 'pan_number', 'account_number', 'cheque_number'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('kyc_documents', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
