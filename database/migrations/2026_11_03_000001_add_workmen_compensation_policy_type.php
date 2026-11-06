<?php

use App\Services\WorkmenCompensationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('insurance_policy_types')->whereNull('deleted_at')->pluck('name')
            ->contains(fn ($name) => WorkmenCompensationType::alias($name));
        if (!$exists) {
            DB::table('insurance_policy_types')->insert([
                'name' => WorkmenCompensationType::NAME, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep this master record: saved policies may reference it.
    }
};
