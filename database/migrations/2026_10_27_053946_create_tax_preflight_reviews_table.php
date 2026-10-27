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
        if (! Schema::hasTable('tax_preflight_reviews')) {
            Schema::create('tax_preflight_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('schedule', 30);
                $table->date('period_from');
                $table->date('period_to');
                $table->string('form', 10)->default('');
                $table->char('snapshot_hash', 64);
                $table->string('rules_version', 40);
                $table->string('action', 20);
                $table->string('filename', 255)->nullable();
                $table->char('file_hash', 64)->nullable();
                $table->text('note')->nullable();
                $table->json('summary');
                $table->timestamp('created_at');
            });
        }

        if (! Schema::hasIndex('tax_preflight_reviews', ['company_id', 'schedule', 'period_from', 'period_to'])) {
            Schema::table('tax_preflight_reviews', function (Blueprint $table): void {
                $table->index(['company_id', 'schedule', 'period_from', 'period_to'], 'tax_preflight_company_period_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Tax review and export history must be preserved. Restore a verified backup to roll back.');
    }
};
