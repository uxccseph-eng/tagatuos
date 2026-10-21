<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace_key', 64);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('classification', 20);
            $table->string('tin', 20)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 500)->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['workspace_key', 'code']);
            $table->index(['workspace_key', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
