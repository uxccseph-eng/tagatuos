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
        Schema::create('company_document_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('app_id', 80);
            $table->text('app_secret');
            $table->string('folder_token', 100)->unique();
            $table->boolean('enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('inbox_documents', function (Blueprint $table): void {
            $table->string('lark_status', 20)->nullable()->index();
            $table->string('lark_file_token', 100)->nullable();
            $table->unsignedInteger('lark_attempts')->default(0);
            $table->uuid('lark_attempt_id')->nullable();
            $table->timestamp('lark_started_at')->nullable();
            $table->timestamp('lark_next_attempt_at')->nullable();
            $table->timestamp('lark_backed_up_at')->nullable();
            $table->string('lark_error')->nullable();
        });
        Schema::create('document_backup_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('inbox_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 40);
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
        throw new RuntimeException('Document backup destinations and history must be preserved. Restore a verified backup to roll back.');
    }
};
