<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('workspace_key', 64)->nullable()->unique();
        });
        DB::transaction(function (): void {
            DB::table('companies')->orderBy('id')->chunkById(100, function ($companies): void {
                foreach ($companies as $company) {
                    DB::table('companies')->where('id', $company->id)->update([
                        'workspace_key' => hash_hmac('sha256', 'company-book:'.$company->id, config('app.key')),
                    ]);
                }
            });
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('workspace_key', 64)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Permanent company scope keys must be preserved. Restore a verified pre-migration backup to roll back this migration.');
    }
};
