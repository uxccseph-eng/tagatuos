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
        Schema::table('parties', function (Blueprint $table): void {
            foreach (['building', 'street', 'barangay', 'city', 'province'] as $field) {
                $table->string($field)->nullable();
            }
            $table->string('zip_code', 4)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropColumn(['building', 'street', 'barangay', 'city', 'province', 'zip_code']);
        });
    }
};
