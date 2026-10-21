<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Employee::factory()->count(3)->create(['workspace_key' => hash('sha256', 'employee-demo-workspace')]);
    }
}
