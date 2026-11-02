<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('journal_entries', 'reserved_number')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->string('reserved_number', 100)->nullable();
            });
        }
        DB::table('journal_entries')->update(['reserved_number' => DB::raw("CASE WHEN status = 'archived' THEN NULL ELSE number END")]);
        foreach (DB::table('company_ledgers')->orderBy('id')->cursor() as $ledger) {
            $sheets = [...json_decode($ledger->state, true, 512, JSON_THROW_ON_ERROR), 'payroll' => json_decode($ledger->payroll, true, 512, JSON_THROW_ON_ERROR)];
            foreach ($sheets as $sheet => $rows) {
                if (! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $record) {
                    if (($record['status'] ?? '') === 'archived') {
                        DB::table('journal_entries')->where('company_id', $ledger->company_id)->where('source_key', $sheet.':'.$record['id'])->update(['status' => 'archived', 'reserved_number' => null]);
                    }
                }
            }
        }
        if (Schema::hasIndex('journal_entries', 'journal_entries_company_id_sheet_number_unique')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->dropUnique('journal_entries_company_id_sheet_number_unique');
            });
        }
        if (! Schema::hasIndex('journal_entries', 'journal_entries_company_sheet_reserved_unique')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->unique(['company_id', 'sheet', 'reserved_number'], 'journal_entries_company_sheet_reserved_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Number history must be preserved. Restore a verified backup to roll back.');
    }
};
