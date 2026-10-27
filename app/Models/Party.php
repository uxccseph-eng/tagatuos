<?php

namespace App\Models;

use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_key', 'code', 'name', 'classification', 'is_petty_cash', 'entity_type', 'last_name', 'first_name', 'middle_name', 'tin', 'description', 'email', 'phone', 'address', 'building', 'street', 'barangay', 'city', 'province', 'zip_code', 'archived'])]
#[Hidden(['workspace_key'])]
class Party extends Model
{
    public const ADDRESS_FIELDS = ['building', 'street', 'barangay', 'city', 'province', 'zip_code'];

    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'is_petty_cash' => 'boolean'];
    }

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        return [
            ['code' => 'VARIOUS-CUST', 'name' => 'VARIOUS CUSTOMERS', 'classification' => 'Customer', 'is_petty_cash' => false],
            ['code' => 'VARIOUS-SUPP', 'name' => 'VARIOUS SUPPLIERS', 'classification' => 'Supplier', 'is_petty_cash' => false],
            ['code' => 'PETTY-CASH', 'name' => 'PETTY CASH / MISCELLANEOUS PAYEES', 'classification' => 'Supplier', 'is_petty_cash' => true],
        ];
    }

    public static function seedWorkspace(string $workspaceKey): void
    {
        $now = now();
        self::query()->insertOrIgnore(array_map(fn (array $row): array => [...$row, 'workspace_key' => $workspaceKey, 'created_at' => $now, 'updated_at' => $now], self::defaults()));
    }
}
