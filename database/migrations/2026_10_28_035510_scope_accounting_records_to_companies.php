<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('companies')->whereNotNull('tenancy_activated_at')->exists()) {
            throw new RuntimeException('Reconcile activated client databases before migrating to a shared database. No client records were moved.');
        }
        if (DB::table('journal_entry_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')->whereColumn('lines.company_id', '!=', 'entries.company_id')->exists()
            || DB::table('journal_entry_lines as lines')->join('chart_accounts as accounts', 'accounts.id', '=', 'lines.chart_account_id')->leftJoin('companies', 'companies.workspace_key', '=', 'accounts.workspace_key')->where(fn (Builder $query): Builder => $query->whereNull('companies.id')->orWhereColumn('lines.company_id', '!=', 'companies.id'))->exists()) {
            throw new RuntimeException('Existing journal ownership must be reconciled before applying company constraints.');
        }
        $tables = ['chart_accounts', 'parties', 'tax_codes', 'bank_accounts', 'employees', 'ppe_assets', 'depreciation_runs', 'company_settings'];
        foreach ($tables as $name) {
            if (! Schema::hasColumn($name, 'company_id')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
                });
            }
            foreach (DB::table('companies')->select(['id', 'workspace_key'])->orderBy('id')->cursor() as $company) {
                DB::table($name)->whereNull('company_id')->where('workspace_key', $company->workspace_key)->update(['company_id' => $company->id]);
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasIndex($name, ['company_id', 'id'], 'unique')) {
                    $table->unique(['company_id', 'id']);
                }
                if (in_array($name, ['chart_accounts', 'parties', 'tax_codes', 'employees', 'ppe_assets'], true)) {
                    if (! Schema::hasIndex($name, ['company_id', 'code'], 'unique')) {
                        $table->unique(['company_id', 'code']);
                    }
                    if (! Schema::hasIndex($name, ['company_id', 'archived'])) {
                        $table->index(['company_id', 'archived']);
                    }
                }
            });
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
            if (! Schema::hasIndex('journal_entry_lines', 'lines_company_journal')) {
                $table->index(['company_id', 'journal_entry_id'], 'lines_company_journal');
            }
            if (! Schema::hasIndex('journal_entry_lines', 'lines_company_account_journal')) {
                $table->index(['company_id', 'chart_account_id', 'journal_entry_id'], 'lines_company_account_journal');
            }
            $foreignKeys = Schema::getForeignKeys('journal_entry_lines');
            if (! collect($foreignKeys)->contains(fn (array $key): bool => $key['columns'] === ['company_id', 'journal_entry_id'])) {
                $table->foreign(['company_id', 'journal_entry_id'], 'lines_company_journal_fk')->references(['company_id', 'id'])->on('journal_entries')->restrictOnDelete();
            }
            if (! collect($foreignKeys)->contains(fn (array $key): bool => $key['columns'] === ['company_id', 'chart_account_id'])) {
                $table->foreign(['company_id', 'chart_account_id'], 'lines_company_account_fk')->references(['company_id', 'id'])->on('chart_accounts')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Company ownership and financial records must be preserved. Restore a verified backup to roll back.');
    }
};
