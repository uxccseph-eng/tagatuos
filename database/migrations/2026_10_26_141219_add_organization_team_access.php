<?php

use App\Models\User;
use App\Services\OrganizationTeamService;
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
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('organization_owner_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('provisioned_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('organization_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 20);
            $table->json('allowed_tabs');
            $table->boolean('is_suspended')->default(false);
            $table->timestamps();
            $table->unique(['owner_id', 'user_id']);
        });
        DB::transaction(function (): void {
            User::query()->whereIn('type', ['firm', 'individual'])->orderBy('id')->chunkById(100, function ($owners): void {
                foreach ($owners as $owner) {
                    app(OrganizationTeamService::class)->upgradeExistingTeam($owner);
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Restore a verified pre-migration backup to roll back organization access without losing membership history.');
    }
};
