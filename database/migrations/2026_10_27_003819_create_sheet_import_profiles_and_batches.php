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
        Schema::create('sheet_import_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('sheet', 20);
            $table->char('header_hash', 64);
            $table->json('headers');
            $table->json('mapping');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sheet', 'header_hash']);
        });
        Schema::create('sheet_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('sheet', 20);
            $table->char('batch_hash', 64);
            $table->unsignedInteger('entry_count');
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['company_id', 'sheet', 'batch_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Import profiles and posting receipts must be preserved. Restore a verified backup to roll back.');
    }
};
