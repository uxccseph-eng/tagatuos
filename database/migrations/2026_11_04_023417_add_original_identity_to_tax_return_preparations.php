<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_return_preparations', function (Blueprint $table): void {
            $table->boolean('amended')->default(false);
            $table->unsignedTinyInteger('original_slot')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('tax_return_save_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->uuid('request_id');
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('preparation_id');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'request_id']);
        });
        DB::table('tax_return_preparations')->orderBy('id')->chunkById(1, function (Collection $rows): void {
            foreach ($rows as $row) {
                $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
                DB::table('tax_return_preparations')->where('id', $row->id)->update(['amended' => (bool) ($snapshot['amended'] ?? false)]);
                DB::table('tax_return_save_requests')->insert(['company_id' => $row->company_id, 'request_id' => $row->request_id, 'payload_hash' => $row->payload_hash, 'preparation_id' => $row->id, 'created_at' => $row->created_at]);
            }
        });
        DB::table('tax_return_preparations')->where('amended', false)->orderBy('id')->chunkById(1, function (Collection $rows): void {
            foreach ($rows as $row) {
                $canonical = DB::table('tax_return_preparations')->where('company_id', $row->company_id)->where('form', $row->form)->where('period_from', $row->period_from)->where('period_to', $row->period_to)->where('amended', false)->orderByRaw("CASE WHEN status = 'filed' THEN 0 ELSE 1 END")->orderByDesc('id')->value('id');
                if ((int) $canonical === (int) $row->id) {
                    DB::table('tax_return_preparations')->where('id', $row->id)->update(['original_slot' => 1]);
                }
            }
        });
        Schema::table('tax_return_preparations', function (Blueprint $table): void {
            $table->unique(['company_id', 'form', 'period_from', 'period_to', 'original_slot'], 'tax_original_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_return_save_requests');
        Schema::table('tax_return_preparations', function (Blueprint $table): void {
            $table->dropUnique('tax_original_period_unique');
            $table->dropColumn(['amended', 'original_slot', 'revision', 'deleted_at']);
        });
    }
};
