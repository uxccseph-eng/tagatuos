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
            $table->string('entity_type', 20)->default('corporation');
            foreach (['last_name', 'first_name', 'middle_name'] as $field) {
                $table->string($field, 30)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropColumn(['entity_type', 'last_name', 'first_name', 'middle_name']);
        });
    }
};
