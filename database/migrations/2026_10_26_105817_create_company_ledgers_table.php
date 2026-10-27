<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('state');
            $table->json('payroll');
            $table->timestamps();
        });
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('source_key', 160);
            $table->string('sheet', 30);
            $table->string('number', 100);
            $table->date('transaction_date');
            $table->string('status', 12);
            $table->string('reference', 255)->default('');
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'source_key']);
            $table->unique(['company_id', 'sheet', 'number']);
            $table->index(['company_id', 'transaction_date', 'status']);
        });
        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->string('account_name', 255);
            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);
            $table->string('party', 255)->default('');
            $table->string('remarks', 1000)->default('');
        });
        Schema::create('company_ledger_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('revision');
            $table->uuid('request_id');
            $table->string('payload_hash', 64);
            $table->json('changes');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'revision']);
            $table->unique(['company_id', 'request_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Financial records must be preserved. Restore a verified backup to roll back the ledger migration.');
    }
};
