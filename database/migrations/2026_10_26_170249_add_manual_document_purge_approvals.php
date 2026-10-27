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
        Schema::create('document_purge_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('ready');
            $table->json('items');
            $table->json('destination');
            $table->json('outcomes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_by', 'created_at']);
        });
        Schema::table('inbox_documents', function (Blueprint $table): void {
            $table->timestamp('lark_verified_at')->nullable();
            $table->timestamp('local_purged_at')->nullable();
            $table->foreignId('local_purged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('local_purge_batch_id')->nullable();
            $table->foreign('local_purge_batch_id')->references('id')->on('document_purge_batches')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Manual purge approvals and document history must be preserved. Restore a verified backup to roll back.');
    }
};
