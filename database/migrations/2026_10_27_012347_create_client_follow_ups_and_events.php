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
        Schema::create('client_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('creation_hash', 64);
            $table->char('open_key', 64)->nullable();
            $table->string('category', 30);
            $table->string('status', 12)->default('open');
            $table->string('linked_sheet', 30)->nullable();
            $table->string('linked_record_id', 100)->nullable();
            $table->string('transaction_number', 100)->nullable();
            $table->date('transaction_date')->nullable();
            $table->string('party', 200)->nullable();
            $table->string('reference', 200)->nullable();
            $table->decimal('amount', 15, 4)->nullable();
            $table->text('request_text');
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'request_id']);
            $table->unique(['company_id', 'open_key']);
            $table->index(['company_id', 'status', 'due_date']);
        });
        Schema::create('client_follow_up_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_follow_up_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after');
            $table->string('note', 1000)->nullable();
            $table->timestamp('created_at');
            $table->index(['company_id', 'client_follow_up_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Client follow-up requests and history must be preserved. Restore a verified backup to roll back.');
    }
};
