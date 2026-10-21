<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace_key', 64);
            $table->string('code', 4);
            $table->string('name', 150);
            $table->string('type', 20);
            $table->string('normal_balance', 6);
            $table->string('statement_section', 80);
            $table->boolean('is_cash_bank')->default(false);
            $table->boolean('is_advance')->default(false);
            $table->boolean('is_control')->default(false);
            $table->string('subsidiary', 30)->nullable();
            $table->string('ppe_role', 30)->nullable();
            $table->string('ppe_category', 60)->nullable();
            $table->boolean('relief')->default(false);
            $table->boolean('percentage_tax')->default(false);
            $table->boolean('ewt')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['workspace_key', 'code']);
            $table->index(['workspace_key', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_accounts');
    }
};
