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
        Schema::create('ppe_assets', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_key', 64);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('category', 60);
            $table->string('serial_number', 80)->nullable();
            $table->date('acquired_date');
            $table->date('service_date');
            $table->date('disposal_date')->nullable();
            $table->unsignedBigInteger('cost_cents');
            $table->unsignedBigInteger('residual_cents')->default(0);
            $table->unsignedInteger('life_months');
            $table->foreignId('asset_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['workspace_key', 'code']);
            $table->unique(['workspace_key', 'serial_number']);
            $table->index(['workspace_key', 'archived']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ppe_assets');
    }
};
