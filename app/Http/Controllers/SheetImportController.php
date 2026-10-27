<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSheetImportProfileRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyBookService;
use App\Services\SheetImportWorkbookReader;
use App\TeamPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SheetImportController extends Controller
{
    public function template(Request $request, string $sheet): BinaryFileResponse
    {
        $this->company($request);

        return response()->download(resource_path('templates/sheets-'.$sheet.'-import.xlsx'), 'sheets-'.$sheet.'-dropdown-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private const COLUMNS = [
        'sales' => ['Date', 'Customer', 'Gross Amount', 'Tax Type', 'Revenue Account', 'Income Withholding ATC', 'VAT Withholding ATC', 'Terms', 'Invoice Reference', 'Classification', 'Deposit Account', 'Description', 'Particulars'],
        'purchases' => ['Date', 'Supplier', 'Supplier TIN', 'Gross Amount', 'Tax Type', 'Account', 'Income Withholding ATC', 'VAT Withholding ATC', 'Terms', 'Invoice Reference', 'Bank', 'Description', 'Notes'],
        'payroll' => ['pay_date', 'run_name', 'period_start', 'period_end', 'employee', 'pay_type', 'basic', 'taxableAllowance', 'deMinimis', 'benefits', 'sssEe', 'phicEe', 'hdmfEe', 'withholding', 'sssEr', 'phicEr', 'hdmfEr', 'employee_code', 'taxable_benefits', 'review_basis'],
    ];

    public function workbook(Request $request, SheetImportWorkbookReader $reader): JsonResponse
    {
        $this->company($request);
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:2048'], 'worksheet' => ['nullable', 'integer', 'between:0,49']]);
        try {
            $result = $reader->read($request->file('file')->getRealPath(), (int) $request->input('worksheet', 0));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function lookup(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate(['sheet' => ['required', 'in:sales,purchases,payroll'], 'headers' => ['required', 'array', 'list', 'min:1', 'max:100'], 'headers.*' => ['nullable', 'string', 'max:150']]);
        $headers = $this->headers($data['headers']);
        $profile = DB::table('sheet_import_profiles')->where('company_id', $company->id)->where('sheet', $data['sheet'])->where('header_hash', $this->headerHash($headers))->first();

        return response()->json(['mapping' => $profile ? json_decode($profile->mapping, true, flags: JSON_THROW_ON_ERROR) : null, 'version' => $profile?->version ?? 0])->header('Cache-Control', 'private, no-store');
    }

    public function save(SaveSheetImportProfileRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validated();
        $headers = $this->headers($data['headers']);
        $mapping = $data['mapping'];
        $indices = array_values(array_filter($mapping, fn ($value): bool => $value !== null));
        if (array_diff(array_keys($mapping), self::COLUMNS[$data['sheet']]) || count($indices) !== count(array_unique($indices)) || count($indices) === 0 || max($indices) >= count($headers)) {
            throw ValidationException::withMessages(['mapping' => 'Map supported fields to different available source columns.']);
        }
        $version = DB::transaction(function () use ($request, $company, $data, $headers, $mapping): int {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $this->company($request);
            $query = DB::table('sheet_import_profiles')->where('company_id', $company->id)->where('sheet', $data['sheet'])->where('header_hash', $this->headerHash($headers));
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless((int) ($row?->version ?? 0) === (int) $data['version'], 409, 'Another accountant changed this mapping. Reload the saved mapping before replacing it.');
            $version = (int) ($row?->version ?? 0) + 1;
            $values = ['headers' => json_encode($headers, JSON_THROW_ON_ERROR), 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR), 'updated_by' => $request->user()->id, 'version' => $version, 'updated_at' => now()];
            if ($row) {
                $query->update($values);
            } else {
                DB::table('sheet_import_profiles')->insert([...$values, 'company_id' => $company->id, 'sheet' => $data['sheet'], 'header_hash' => $this->headerHash($headers), 'created_at' => now()]);
            }

            return $version;
        });

        return response()->json(['version' => $version])->header('Cache-Control', 'private, no-store');
    }

    private function company(Request $request): Company
    {
        $active = $request->attributes->get('active_company');
        abort_unless($request->user() && $active, 403, 'Open an assigned company book first.');
        $company = app(CompanyBookService::class)->findAccessibleBook(User::query()->findOrFail($request->user()->id), $active->id);
        abort_unless(in_array('sheets', TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true), 403);

        return $company;
    }

    /** @param list<?string> $headers
     * @return list<string>
     */
    private function headers(array $headers): array
    {
        return array_map(fn (?string $value): string => trim(preg_replace('/\s+/u', ' ', $value ?? '')), $headers);
    }

    /** @param list<string> $headers */
    private function headerHash(array $headers): string
    {
        return hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR));
    }
}
