<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_database_identity')) {
            Schema::create('client_database_identity', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('company_id');
                $table->char('workspace_key', 64)->unique();
            });
        }

        if (! Schema::hasTable('chart_accounts')) {
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

        if (! Schema::hasColumn('chart_accounts', 'description')) {
            Schema::table('chart_accounts', function (Blueprint $table): void {
                $table->text('description')->nullable();
                $table->boolean('allow_subsidiaries')->default(false);
                $table->string('cash_flow_section', 20)->nullable();
                $table->string('cash_flow_subclass', 30)->nullable();
                $table->string('relief_category', 20)->nullable();
                $table->string('ewt_atc_code', 10)->nullable();
            });
        }

        if (! Schema::hasTable('parties')) {
            Schema::create('parties', function (Blueprint $table): void {
                $table->id();
                $table->string('workspace_key', 64);
                $table->string('code', 40);
                $table->string('name', 150);
                $table->string('classification', 20);
                $table->string('tin', 20)->nullable();
                $table->string('description', 500)->nullable();
                $table->string('email', 254)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('address', 500)->nullable();
                $table->boolean('archived')->default(false);
                $table->timestamps();
                $table->unique(['workspace_key', 'code']);
                $table->index(['workspace_key', 'archived']);
            });
        }

        if (! Schema::hasTable('tax_codes')) {
            Schema::create('tax_codes', function (Blueprint $table): void {
                $table->id();
                $table->string('workspace_key', 64);
                $table->string('code', 40);
                $table->string('name', 150);
                $table->string('kind', 20);
                $table->decimal('rate', 7, 4)->default(0);
                $table->string('atc', 10)->nullable();
                $table->boolean('archived')->default(false);
                $table->timestamps();
                $table->unique(['workspace_key', 'code']);
                $table->index(['workspace_key', 'archived']);
            });
        }

        if (! Schema::hasTable('bank_accounts')) {
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

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->string('workspace_key', 64);
                $table->string('code', 40);
                $table->string('name', 150);
                $table->string('name_key', 64);
                $table->string('classification', 30)->default('Regular');
                $table->string('tin', 20)->nullable();
                $table->string('tin_key', 20)->nullable();
                $table->string('email', 254)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('address', 500)->nullable();
                $table->date('birth_date')->nullable();
                $table->date('hire_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('sss_no', 30)->nullable();
                $table->string('philhealth_no', 30)->nullable();
                $table->string('pagibig_no', 30)->nullable();
                $table->boolean('archived')->default(false);
                $table->timestamps();
                $table->unique(['workspace_key', 'code']);
                $table->unique(['workspace_key', 'name_key']);
                $table->unique(['workspace_key', 'tin_key']);
                $table->index(['workspace_key', 'archived']);
            });
        }

        if (! Schema::hasTable('ppe_assets')) {
            Schema::create('ppe_assets', function (Blueprint $table): void {
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

        if (! Schema::hasTable('depreciation_runs')) {
            Schema::create('depreciation_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('workspace_key', 64);
                $table->string('period', 7);
                $table->string('number', 30);
                $table->json('snapshot');
                $table->json('journal');
                $table->unsignedBigInteger('total_cents');
                $table->string('posted_by', 150);
                $table->timestamp('posted_at');
                $table->timestamps();
                $table->unique(['workspace_key', 'period']);
            });
        }

        if (! Schema::hasColumn('ppe_assets', 'capitalization_reference')) {
            Schema::table('ppe_assets', function (Blueprint $table): void {
                $table->string('capitalization_reference', 150)->nullable();
            });
        }

        if (! Schema::hasTable('company_settings')) {
            Schema::create('company_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('workspace_key', 64)->unique();
                $table->string('lookup_key', 64)->unique();
                $table->json('profile');
                $table->json('preferences');
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('parties', 'building')) {
            Schema::table('parties', function (Blueprint $table): void {
                foreach (['building', 'street', 'barangay', 'city', 'province'] as $field) {
                    $table->string($field)->nullable();
                }
                $table->string('zip_code', 4)->nullable();
            });
        }

        if (! Schema::hasColumn('parties', 'entity_type')) {
            Schema::table('parties', function (Blueprint $table): void {
                $table->string('entity_type', 20)->default('corporation');
                foreach (['last_name', 'first_name', 'middle_name'] as $field) {
                    $table->string($field, 30)->nullable();
                }
            });
        }

        if (! Schema::hasTable('company_ledgers')) {
            Schema::create('company_ledgers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->unique();
                $table->unsignedBigInteger('revision')->default(0);
                $table->json('state');
                $table->json('payroll');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id');
                $table->string('source_key', 160);
                $table->string('sheet', 30);
                $table->string('number', 100);
                $table->date('transaction_date');
                $table->string('status', 12);
                $table->string('reference', 255)->default('');
                $table->foreignId('posted_by');
                $table->timestamps();
                $table->unique(['company_id', 'source_key']);
                $table->unique(['company_id', 'sheet', 'number']);
                $table->index(['company_id', 'transaction_date', 'status']);
            });
        }
        if (! Schema::hasTable('journal_entry_lines')) {
            Schema::create('journal_entry_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
                $table->foreignId('company_id');
                $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
                $table->string('account_name', 255);
                $table->decimal('debit', 15, 4)->default(0);
                $table->decimal('credit', 15, 4)->default(0);
                $table->string('party', 255)->default('');
                $table->string('remarks', 1000)->default('');
            });
        }
        if (! Schema::hasTable('company_ledger_changes')) {
            Schema::create('company_ledger_changes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('user_id');
                $table->unsignedBigInteger('revision');
                $table->uuid('request_id');
                $table->string('payload_hash', 64);
                $table->json('changes');
                $table->timestamp('created_at');
                $table->unique(['company_id', 'revision']);
                $table->unique(['company_id', 'request_id']);
            });
        }

        if (! Schema::hasTable('sheet_import_profiles')) {
            Schema::create('sheet_import_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id');
                $table->string('sheet', 20);
                $table->char('header_hash', 64);
                $table->json('headers');
                $table->json('mapping');
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('updated_by');
                $table->timestamps();
                $table->unique(['company_id', 'sheet', 'header_hash']);
            });
        }
        if (! Schema::hasTable('sheet_import_batches')) {
            Schema::create('sheet_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id');
                $table->string('sheet', 20);
                $table->char('batch_hash', 64);
                $table->unsignedInteger('entry_count');
                $table->foreignId('posted_by');
                $table->timestamp('created_at');
                $table->unique(['company_id', 'sheet', 'batch_hash']);
            });
        }

        if (! Schema::hasColumn('parties', 'is_petty_cash')) {
            Schema::table('parties', function (Blueprint $table): void {
                $table->boolean('is_petty_cash')->default(false);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Client financial records must be preserved. Restore a verified client backup to roll back.');
    }
};
