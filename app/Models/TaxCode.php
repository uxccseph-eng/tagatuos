<?php

namespace App\Models;

use Database\Factories\TaxCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Fillable(['workspace_key', 'code', 'name', 'kind', 'rate', 'atc', 'archived'])]
#[Hidden(['workspace_key'])]
class TaxCode extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<TaxCodeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'rate' => 'decimal:4'];
    }

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        return array_map(fn (array $row): array => array_combine(['code', 'name', 'kind', 'rate'], $row), [['EXEMPT', 'VAT-exempt', 'Exempt', 0], ['IMPORT-EXEMPT', 'VAT-exempt importation', 'Exempt', 0], ['NR', 'No Receipt', 'Exempt', 0], ['NONVAT', 'Non-VAT', 'Exempt', 0], ['VAT', 'Default VAT 12%', 'VAT', 12], ['VAT-IMPORT', 'Import VAT 12%', 'VAT', 12], ['ZERO', 'Zero-rated', 'Zero Rated', 0]]);
    }

    public static function seedWorkspace(string $workspaceKey): void
    {
        DB::transaction(function () use ($workspaceKey): void {
            $legacy = self::query()->where('workspace_key', $workspaceKey)->where('code', 'NA')->where('name', 'Not applicable')->where('kind', 'Exempt')->where('rate', 0)->lockForUpdate()->first();
            if ($legacy) {
                if (self::query()->where('workspace_key', $workspaceKey)->where('code', 'NR')->exists()) {
                    if (! $legacy->archived) {
                        $legacy->update(['archived' => true]);
                    }
                } else {
                    $legacy->update(['code' => 'NR', 'name' => 'No Receipt']);
                }
            }
            $now = now();
            self::query()->insertOrIgnore(array_map(fn (array $row): array => [...$row, ...self::companyAttributes($workspaceKey), 'workspace_key' => $workspaceKey, 'created_at' => $now, 'updated_at' => $now], self::defaults()));
        });
    }

    /** @return list<array<string, mixed>> */
    public static function atcCatalog(string $workspaceKey): array
    {
        $official = json_decode(file_get_contents(database_path('seeders/atc-codes.json')), true, 512, JSON_THROW_ON_ERROR);
        $custom = self::query()->where('workspace_key', $workspaceKey)->where('kind', 'Withholding')->where('archived', false)->whereColumn('code', 'atc')->get();
        foreach ($custom as $record) {
            if (! in_array($record->code, array_column($official, 'code'), true)) {
                $official[] = ['code' => $record->code, 'name' => $record->name, 'rate' => (float) $record->rate, 'category' => preg_match('/^W[VB]/', $record->code) ? 'VAT Withholding' : 'Income-tax CWT', 'payee' => '', 'form' => '', 'custom' => true];
            }
        }

        return $official;
    }
}
