<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppe_assets', function (Blueprint $table): void {
            $table->date('service_date')->nullable()->change();
            $table->unsignedInteger('life_months')->nullable()->change();
            $table->boolean('setup_pending')->default(false);
            $table->boolean('source_review_required')->default(false);
            $table->string('purchase_source_id', 120)->nullable();
            $table->unique(['workspace_key', 'purchase_source_id'], 'ppe_purchase_source_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('ppe_assets')->whereNull('service_date')->orWhereNull('life_months')->exists()) {
            throw new RuntimeException('Complete pending PPE setup before rolling back purchase drafts.');
        }
        Schema::table('ppe_assets', function (Blueprint $table): void {
            $table->dropUnique('ppe_purchase_source_unique');
            $table->dropColumn(['setup_pending', 'source_review_required', 'purchase_source_id']);
            $table->date('service_date')->nullable(false)->change();
            $table->unsignedInteger('life_months')->nullable(false)->change();
        });
    }
};
