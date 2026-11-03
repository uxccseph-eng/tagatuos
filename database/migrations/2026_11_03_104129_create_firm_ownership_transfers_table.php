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
        Schema::create('firm_ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_owner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('source_password_fingerprint', 64);
            $table->json('scope_summary');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['source_owner_id', 'status', 'expires_at']);
            $table->index(['target_owner_id', 'status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('firm_ownership_transfers');
    }
};
