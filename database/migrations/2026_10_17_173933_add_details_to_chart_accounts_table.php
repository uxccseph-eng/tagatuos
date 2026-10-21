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
        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->boolean('allow_subsidiaries')->default(false);
            $table->string('cash_flow_section', 20)->nullable();
            $table->string('cash_flow_subclass', 30)->nullable();
            $table->string('relief_category', 20)->nullable();
            $table->string('ewt_atc_code', 10)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->dropColumn(['description', 'allow_subsidiaries', 'cash_flow_section', 'cash_flow_subclass', 'relief_category', 'ewt_atc_code']);
        });
    }
};
