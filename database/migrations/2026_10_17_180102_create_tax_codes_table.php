<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace_key', 64);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('kind', 20);
            $table->decimal('rate', 7, 4)->default(0);
            $table->string('atc', 10)->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['workspace_key', 'code']);
            $table->index(['workspace_key', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
