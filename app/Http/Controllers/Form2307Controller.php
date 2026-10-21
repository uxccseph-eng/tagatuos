<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrintForm2307Request;
use App\Models\ChartAccount;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\TaxCode;
use App\Tin;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class Form2307Controller extends Controller
{
    public function print2307(PrintForm2307Request $request, int $supplierId): Response|JsonResponse
    {
        $data = $request->validated();
        $company = $request->session()->get('company_setup');
        $workspace = ChartAccount::workspaceKey($company);
        $company = array_replace($company, CompanySetting::query()->where('workspace_key', $workspace)->first()?->profile ?? []);
        $supplier = Party::query()->where('workspace_key', $workspace)->whereIn('classification', ['Supplier', 'Both'])->findOrFail($supplierId);
        $startDate = CarbonImmutable::create((int) $data['year'], ((int) $data['quarter'] - 1) * 3 + 1, 1)->startOfDay();
        $endDate = $startDate->addMonths(2)->endOfMonth();
        $catalog = collect(TaxCode::atcCatalog($workspace))->keyBy('code');
        $groups = [];
        foreach ($data['records'] as $index => $record) {
            $field = 'records.'.$index;
            if ((int) $record['supplier_id'] !== $supplier->id) {
                throw ValidationException::withMessages([$field.'.supplier_id' => 'All entries must belong to the selected supplier.']);
            }
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $record['transaction_date']);
            if ($date->lt($startDate) || $date->gt($endDate)) {
                throw ValidationException::withMessages([$field.'.transaction_date' => 'The transaction must fall within the selected calendar quarter.']);
            }
            $code = $record['atc_code'];
            if (! $catalog->has($code)) {
                throw ValidationException::withMessages([$field.'.atc_code' => 'Select an ATC from Masters.']);
            }
            $base = $this->cents((string) $record['income_payment']);
            $withheld = $this->cents((string) $record['tax_withheld']);
            if ($withheld > $base) {
                throw ValidationException::withMessages([$field.'.tax_withheld' => 'Tax withheld cannot exceed the income payment.']);
            }
            $groups[$code] ??= ['code' => $code, 'description' => $catalog[$code]['name'], 'months' => [0, 0, 0], 'total' => 0, 'withheld' => 0];
            $groups[$code]['months'][$date->month - $startDate->month] += $base;
            $groups[$code]['total'] += $base;
            $groups[$code]['withheld'] += $withheld;
        }
        ksort($groups);
        $signatory = $request->boolean('use_default_signatory')
            ? ['name' => $company['signatory_name'] ?? '', 'title' => $company['signatory_title'] ?? '', 'tin' => $company['signatory_tin'] ?? '']
            : ['name' => $data['signatory_name'] ?? '', 'title' => $data['signatory_title'] ?? '', 'tin' => $data['signatory_tin'] ?? ''];
        if (trim($signatory['name']) === '' || trim($signatory['title']) === '') {
            throw ValidationException::withMessages(['signatory_name' => 'Enter a signatory name and designation, or complete the default Certificate Signatory in Settings > BIR.']);
        }
        $companyTin = preg_replace('/\D/', '', (string) ($company['tin'] ?? ''));
        if (strlen($companyTin) === 9) {
            $companyTin .= str_pad((string) ($company['branch_code'] ?? ''), 5, '0', STR_PAD_LEFT);
        }
        $payor = ['tin' => $this->tin($companyTin, 'company.tin'), 'name' => ($company['registered_name'] ?? '') ?: $company['company_name'], 'address' => implode(', ', array_filter(array_map(fn (string $key): string => trim((string) ($company[$key] ?? '')), ['building', 'street', 'barangay', 'city', 'province']))), 'zip' => $company['zip_code'] ?? ''];
        $payee = ['tin' => $this->tin((string) $supplier->tin, 'supplier.tin'), 'name' => $supplier->name, 'address' => $supplier->address, 'zip' => $supplier->zip_code ?? ''];
        foreach (['payor' => $payor, 'payee' => $payee] as $key => $identity) {
            if (trim((string) $identity['address']) === '') {
                throw ValidationException::withMessages([$key.'.address' => 'Complete the registered address for the '.$key.' before printing.']);
            }
        }
        $pages = array_chunk(array_values($groups), 10);
        $background = base64_encode(file_get_contents(resource_path('views/form2307-background.jpg')));

        $view = compact('payor', 'payee', 'startDate', 'endDate', 'signatory', 'pages', 'background');
        $filename = 'BIR-2307-'.$payee['tin']['digits'].'-Q'.$data['quarter'].'-'.$data['year'].'.pdf';
        $pdf = Pdf::loadView('form2307', $view)
            ->setPaper([0, 0, 612, 936])
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'Helvetica']);
        if ($request->boolean('preview')) {
            return response()->json([
                'filename' => $filename,
                'pdf' => base64_encode($pdf->output()),
                'html' => view('form2307', [...$view, 'preview' => true])->render(),
            ])->header('Cache-Control', 'no-store');
        }

        return $pdf->download($filename);
    }

    private function cents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** @return array{digits: string, groups: list<string>} */
    private function tin(string $value, string $field): array
    {
        $digits = preg_replace('/\D/', '', Tin::format($value));
        if (strlen($digits) !== 14 || ! preg_match('/[1-9]/', substr($digits, 0, 9))) {
            throw ValidationException::withMessages([$field => 'Enter a real TIN with its five-digit branch in Masters or Settings.']);
        }

        return ['digits' => $digits, 'groups' => [substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3), substr($digits, 9, 5)]];
    }
}
