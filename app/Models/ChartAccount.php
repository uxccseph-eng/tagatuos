<?php

namespace App\Models;

use Database\Factories\ChartAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_key', 'code', 'name', 'type', 'normal_balance', 'statement_section', 'is_cash_bank', 'is_advance', 'is_control', 'subsidiary', 'ppe_role', 'ppe_category', 'relief', 'percentage_tax', 'ewt', 'archived', 'description', 'allow_subsidiaries', 'cash_flow_section', 'cash_flow_subclass', 'relief_category', 'ewt_atc_code'])]
#[Hidden(['workspace_key'])]
class ChartAccount extends Model
{
    public const RELIEF_CATEGORIES = [
        'vatable_sales' => 'Vatable Sales',
        'services' => 'Services',
        'capital_goods' => 'Capital Goods',
        'goods' => 'Other-than-Capital Goods',
    ];

    /** @use HasFactory<ChartAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return array_fill_keys(['is_cash_bank', 'is_advance', 'is_control', 'relief', 'percentage_tax', 'ewt', 'archived', 'allow_subsidiaries'], 'boolean');
    }

    /** @param array<string, mixed> $company */
    public static function syncSalesRelief(array $company): void
    {
        $accounts = self::query()->where('workspace_key', self::workspaceKey($company));
        if (($company['vat_type'] ?? '') === 'vat') {
            $accounts->where('type', 'Income')->where(function (Builder $query): void {
                $query->whereNull('relief_category')->orWhere('relief_category', '!=', 'vatable_sales')->orWhere('relief', false);
            })->update(['relief' => true, 'relief_category' => 'vatable_sales']);
        } else {
            $accounts->where('relief_category', 'vatable_sales')->update(['relief' => false, 'relief_category' => null]);
        }
    }

    /** @param array<string, mixed> $company */
    public static function workspaceKey(array $company): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $company['workspace_key'] ?? '')) {
            return $company['workspace_key'];
        }

        return hash_hmac('sha256', mb_strtolower(trim($company['company_name'])).'|'.preg_replace('/\D/', '', $company['tin'] ?? ''), config('app.key'));
    }

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        $labels = ['7210' => 'Salaries & Wages', '7220' => '13th Month & Other Benefits', '7230' => 'De Minimis & Non-Taxable Benefits', '7240' => 'SSS Contributions Expense', '7250' => 'PhilHealth Contributions Expense', '7260' => 'Pag-IBIG Contributions Expense', '2310' => 'SSS Payable', '2320' => 'PhilHealth Payable', '2330' => 'Pag-IBIG Payable', '2340' => 'Salaries Payable', '2350' => 'WHT Payable - Compensation', '1010' => 'Cash on Hand', '1020' => 'Cash in Bank', '1025' => 'BPI Operating Account', '1030' => 'BDO Checking', '1040' => 'UnionBank Digital', '1105' => 'Accounts Receivable - Trade', '1150' => 'Prepaid CWT', '1170' => 'Advances to Suppliers', '1200' => 'Inventory', '1500' => 'Property and Equipment', '1590' => 'Accumulated Depreciation', '2010' => 'Accounts Payable - Trade', '2100' => 'Accrued Expenses', '2110' => 'Input VAT', '2140' => 'Expanded WHT Payable', '2150' => 'Withholding VAT Payable', '2200' => 'Customer Advances', '2210' => 'Output VAT Payable', '3010' => 'Owner Capital', '3020' => 'Retained Earnings', '4000' => 'Merchandise Sales', '4020' => 'Sales - Services', '4050' => 'Consulting Income', '5000' => 'Cost of Sales', '6110' => 'Office Supplies', '6150' => 'Packaging Supplies', '7110' => 'Rent Expense', '7120' => 'Utilities', '7130' => 'Software and Subscriptions', '7140' => 'Professional Fees', '7150' => 'Depreciation Expense'];
        $records = [];
        foreach ($labels as $code => $name) {
            $code = (string) $code;
            $type = $code === '2110' || str_starts_with($code, '1') ? 'Asset' : (str_starts_with($code, '2') ? 'Liability' : (str_starts_with($code, '3') ? 'Equity' : (str_starts_with($code, '4') ? 'Income' : 'Expense')));
            $section = match ($type) {
                'Asset' => in_array($code, ['1500', '1590']) ? 'Non-Current Assets' : 'Current Assets', 'Liability' => 'Current Liabilities', 'Equity' => $code === '3020' ? 'Retained Earnings' : 'Capital', 'Income' => 'Revenue', default => $code === '5000' ? 'Cost of Goods Sold' : 'General and Administrative Expenses'
            };
            $records[] = ['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $code === '1590' || in_array($type, ['Liability', 'Equity', 'Income']) ? 'Credit' : 'Debit', 'statement_section' => $section, 'is_cash_bank' => str_starts_with($code, '10'), 'is_advance' => in_array($code, ['1170', '2200']), 'is_control' => in_array($code, ['1105', '1170', '2010', '2200']), 'subsidiary' => in_array($code, ['1105', '2200']) ? 'Customer' : (in_array($code, ['1170', '2010']) ? 'Supplier' : null), 'ppe_role' => null, 'ppe_category' => null, 'relief' => false, 'percentage_tax' => false, 'ewt' => false, 'archived' => false];
        }

        return $records;
    }

    public static function seedWorkspace(string $workspaceKey): void
    {
        $now = now();
        $records = array_map(fn (array $account): array => [...$account, 'workspace_key' => $workspaceKey, 'created_at' => $now, 'updated_at' => $now], self::defaults());
        self::query()->insertOrIgnore($records);
    }
}
