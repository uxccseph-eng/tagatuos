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
        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_key', 64);
            $table->string('period', 7);
            $table->string('number', 30);
            $table->json('snapshot');
            $table->json('journal');
            $table->unsignedBigInteger('total_cents');
            $table->string('posted_by', 150);
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['workspace_key', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('depreciation_runs');
    }
};
