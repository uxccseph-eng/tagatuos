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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_key', 64);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('name_key', 64);
            $table->string('classification', 30)->default('Regular');
            $table->string('tin', 20)->nullable();
            $table->string('tin_key', 20)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 500)->nullable();
            $table->date('birth_date')->nullable();
            $table->date('hire_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('sss_no', 30)->nullable();
            $table->string('philhealth_no', 30)->nullable();
            $table->string('pagibig_no', 30)->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['workspace_key', 'code']);
            $table->unique(['workspace_key', 'name_key']);
            $table->unique(['workspace_key', 'tin_key']);
            $table->index(['workspace_key', 'archived']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
