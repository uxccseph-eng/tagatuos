<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->unique();
            $table->enum('registration_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->boolean('is_system_owner')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
        });
        DB::transaction(function (): void {
            DB::table('users')->update(['registration_status' => 'approved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['username', 'registration_status', 'is_system_owner', 'reviewed_at', 'rejection_reason']);
        });
    }
};
