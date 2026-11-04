<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_return_preparations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('settings_year')->nullable();
            $table->unsignedInteger('year_settings_revision')->nullable();
        });
        Schema::create('tax_return_year_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('revision')->default(1);
            $table->json('settings');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['company_id', 'year']);
        });
        Schema::create('tax_return_filing_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('form', 12);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('reference', 150)->nullable();
            $table->date('filed_on')->nullable();
            $table->unsignedBigInteger('filed_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'form', 'period_from', 'period_to'], 'tax_filing_period_unique');
        });
        Schema::create('tax_return_proofs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('filing_id');
            $table->string('path');
            $table->string('original_name', 180);
            $table->string('mime_type', 80);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamp('created_at');
            $table->index(['company_id', 'filing_id']);
            $table->unique(['company_id', 'filing_id', 'sha256'], 'tax_proof_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tax_return_preparations', function (Blueprint $table): void {
            $table->dropColumn(['settings_year', 'year_settings_revision']);
        });
        Schema::dropIfExists('tax_return_proofs');
        Schema::dropIfExists('tax_return_filing_records');
        Schema::dropIfExists('tax_return_year_settings');
    }
};
