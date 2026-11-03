<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_transaction_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('sheet', 30);
            $table->string('record_id', 120);
            $table->unsignedBigInteger('version');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['company_id', 'sheet', 'record_id'], 'company_record_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_transaction_versions');
    }
};
