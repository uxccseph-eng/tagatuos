<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_statements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('company_ledger_id');
            $table->string('company_name');
            $table->string('tin', 40)->default('');
            $table->unsignedSmallInteger('reporting_year');
            $table->unsignedBigInteger('ledger_revision');
            $table->json('balance_sheet');
            $table->json('income_statement');
            $table->json('equity');
            $table->json('cash_flows');
            $table->timestamps();
            $table->unique(['company_id', 'reporting_year']);
            $table->index(['company_id', 'company_ledger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_statements');
    }
};
