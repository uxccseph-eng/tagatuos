<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('tenancy_db_connection')->nullable()->unique();
            $table->string('tenancy_db_name')->nullable();
            $table->timestamp('tenancy_activated_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Client database assignments must be preserved. Restore a verified backup to roll back.');
    }
};
