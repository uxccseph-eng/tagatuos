<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_summary_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->uuid('request_id');
            $table->char('payload_hash', 64);
            $table->string('action', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->bigInteger('amount');
            $table->unsignedBigInteger('actor_id');
            $table->string('slot', 100)->nullable();
            $table->json('data');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'request_id']);
            $table->unique(['company_id', 'slot']);
            $table->index(['company_id', 'year', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_summary_events');
    }
};
