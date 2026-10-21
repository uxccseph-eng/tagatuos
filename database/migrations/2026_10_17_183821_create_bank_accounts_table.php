<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace_key', 64);
            $table->string('name', 150);
            $table->string('bank', 150)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->foreignId('chart_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->string('currency', 3)->default('PHP');
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['workspace_key', 'name']);
            $table->index(['workspace_key', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
