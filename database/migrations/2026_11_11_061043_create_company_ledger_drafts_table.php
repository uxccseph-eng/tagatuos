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
        Schema::connection(config('tenancy.database.central_connection'))->create('company_ledger_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('draft_id');
            $table->unsignedInteger('version');
            $table->longText('payload');
            $table->uuid('commit_request_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'draft_id'], 'private_company_draft_identity');
            $table->index(['company_id', 'user_id', 'updated_at'], 'private_company_draft_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('tenancy.database.central_connection'))->dropIfExists('company_ledger_drafts');
    }
};
