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
        if (!Schema::hasTable('field_customizations')) {
            Schema::create('field_customizations', function (Blueprint $table) {
                $table->id();
                $table->string('module', 50)->index();
                $table->string('field_name', 100);
                $table->string('custom_label', 255)->nullable();
                $table->boolean('is_visible')->default(true);
                $table->boolean('is_required')->default(false);
                $table->string('validation_message', 255)->nullable();
                $table->integer('display_order')->default(0);
                $table->timestamps();

                $table->unique(['module', 'field_name']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_customizations');
    }
};

