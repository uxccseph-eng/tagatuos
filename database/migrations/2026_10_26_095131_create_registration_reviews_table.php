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
        Schema::create('registration_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('previous_status', 20);
            $table->string('username', 64)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['applicant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_reviews');
    }
};
