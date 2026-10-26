<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\CompanyBookService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Example company books may only be seeded locally or in tests.');
        }

        DB::transaction(function (): void {
            $firm = User::factory()->firm()->create(['password' => Str::password(32)]);
            $books = app(CompanyBookService::class);
            $books->createBook($firm, 'Example Client One');
            $books->createBook($firm, 'Example Client Two');
        });
    }
}
