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
        Schema::create('team_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 50);
            $table->json('details');
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });
        Schema::create('team_login_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_login_tokens');
        Schema::dropIfExists('team_events');
    }
};
