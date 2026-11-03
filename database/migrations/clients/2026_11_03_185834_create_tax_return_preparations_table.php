<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_return_preparations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->uuid('request_id');
            $table->unsignedBigInteger('prepared_by');
            $table->string('form', 12);
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedBigInteger('ledger_revision');
            $table->char('source_hash', 64);
            $table->char('payload_hash', 64);
            $table->json('snapshot');
            $table->string('status', 20)->default('prepared');
            $table->string('filing_reference', 150)->nullable();
            $table->date('filed_on')->nullable();
            $table->unsignedBigInteger('filed_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_id']);
            $table->index(['company_id', 'form', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_return_preparations');
    }
};
