<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_statements', function (Blueprint $table): void {
            $table->json('package')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financial_statements', function (Blueprint $table): void {
            $table->dropColumn('package');
        });
    }
};
