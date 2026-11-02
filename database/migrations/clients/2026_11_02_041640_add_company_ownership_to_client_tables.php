<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['chart_accounts', 'parties', 'tax_codes', 'bank_accounts', 'employees', 'ppe_assets', 'depreciation_runs', 'company_settings'] as $name) {
            if (! Schema::hasColumn($name, 'company_id')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->unsignedBigInteger('company_id')->index();
                    $table->unique(['company_id', 'id']);
                });
            }
        }
        Schema::table('journal_entries', function (Blueprint $table): void {
            if (! Schema::hasIndex('journal_entries', ['company_id', 'id'], 'unique')) {
                $table->unique(['company_id', 'id']);
            }
            if (! Schema::hasIndex('journal_entries', 'journals_company_status_date')) {
                $table->index(['company_id', 'status', 'transaction_date', 'id'], 'journals_company_status_date');
            }
        });
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            if (! Schema::hasIndex('journal_entry_lines', 'lines_company_account_journal')) {
                $table->index(['company_id', 'chart_account_id', 'journal_entry_id'], 'lines_company_account_journal');
            }
            if (! collect(Schema::getForeignKeys('journal_entry_lines'))->contains(fn (array $key): bool => $key['columns'] === ['company_id', 'journal_entry_id'])) {
                $table->foreign(['company_id', 'journal_entry_id'], 'lines_company_journal_fk')->references(['company_id', 'id'])->on('journal_entries')->restrictOnDelete();
            }
            if (! collect(Schema::getForeignKeys('journal_entry_lines'))->contains(fn (array $key): bool => $key['columns'] === ['company_id', 'chart_account_id'])) {
                $table->foreign(['company_id', 'chart_account_id'], 'lines_company_account_fk')->references(['company_id', 'id'])->on('chart_accounts')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Client financial records must be preserved.');
    }
};
