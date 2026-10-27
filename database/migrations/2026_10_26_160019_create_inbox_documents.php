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
        Schema::create('inbox_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path', 500);
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('category', 20)->default('purchases');
            $table->string('status', 25)->default('received');
            $table->text('notes')->nullable();
            $table->string('linked_sheet', 30)->nullable();
            $table->string('linked_record_id', 100)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'sha256']);
            $table->index(['company_id', 'status', 'created_at']);
        });
        Schema::create('inbox_document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbox_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->json('details');
            $table->timestamp('created_at');
            $table->index(['company_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Source documents and their history must be preserved. Restore a verified backup to roll back this migration.');
    }
};
