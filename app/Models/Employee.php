<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['workspace_key', 'code', 'name', 'name_key', 'tin_key', 'classification', 'tin', 'email', 'phone', 'address', 'birth_date', 'hire_date', 'end_date', 'sss_no', 'philhealth_no', 'pagibig_no', 'archived'])]
#[Hidden(['workspace_key', 'name_key', 'tin_key'])]
class Employee extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['archived' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            $employee->name = Str::squish($employee->name);
            $employee->name_key = hash('sha256', Str::lower($employee->name));
            $digits = preg_replace('/\D/', '', $employee->tin ?? '');
            $employee->tin_key = preg_match('/[1-9]/', $digits) ? $digits : null;
        });
    }
}
