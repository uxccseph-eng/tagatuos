<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique(['tenancy_db_connection']);
            $table->unique('tenancy_db_name');
            $table->string('provisioning_status', 20)->default('pending')->index();
            $table->unsignedInteger('provisioning_attempts')->default(0);
            $table->string('provisioning_error', 255)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Client database ownership must be preserved.');
    }
};
