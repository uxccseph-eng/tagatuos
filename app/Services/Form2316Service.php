<?php

namespace App\Services;

use App\Models\Employee;
use App\Tin;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class Form2316Service
{
    public function cents(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function annualTax(int $taxable): int
    {
        foreach ([[800000000, 220250000, 35], [200000000, 40250000, 30], [80000000, 10250000, 25], [40000000, 2250000, 20], [25000000, 0, 15]] as [$floor, $base, $rate]) {
            if ($taxable > $floor) {
                return $base + (int) round(($taxable - $floor) * $rate / 100);
            }
        }

        return 0;
    }

    /** @return list<string> */
    public function tin(string $value, string $field): array
    {
        $digits = preg_replace('/\D/', '', Tin::format($value));
        if (strlen($digits) !== 14 || ! preg_match('/[1-9]/', substr($digits, 0, 9))) {
            throw ValidationException::withMessages([$field => 'Complete the real TIN and five-digit branch in Masters or Settings.']);
        }

        return str_split($digits);
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    public function prepare(array $data, array $company, Employee $employee): array
    {
        $year = (int) $data['year'];
        $amounts = array_fill_keys(array_merge(range(19, 28), range(29, 52)), 0);
        $recordedWithheld = 0;
        foreach ($data['records'] as $index => $record) {
            if ((int) $record['employee_id'] !== $employee->id || (int) substr($record['date'], 0, 4) !== $year) {
                throw ValidationException::withMessages(['records.'.$index => 'Every payroll row must belong to this employee and reporting year.']);
            }
            $values = [];
            foreach (['basic', 'allowance', 'de_minimis', 'benefits', 'taxable_benefits', 'contributions', 'withheld'] as $field) {
                $values[$field] = $this->cents($record[$field]);
            }
            if ($values['taxable_benefits'] > $values['benefits'] || $values['contributions'] > $values['basic'] + $values['allowance']) {
                throw ValidationException::withMessages(['records.'.$index => 'Review benefits and employee contributions in Payroll before printing.']);
            }
            $gross = $values['basic'] + $values['allowance'] + $values['de_minimis'] + $values['benefits'];
            $amounts[19] += $gross;
            $amounts[35] += $values['de_minimis'];
            $amounts[36] += $values['contributions'];
            if ($record['pay_type'] === 'Non-taxable') {
                $deductBasic = min($values['basic'], $values['contributions']);
                $amounts[29] += $values['basic'] - $deductBasic;
                $amounts[34] += $values['benefits'];
                $amounts[37] += $values['allowance'] - ($values['contributions'] - $deductBasic);
            } else {
                $deductBasic = min($values['basic'], $values['contributions']);
                $amounts[39] += $values['basic'] - $deductBasic;
                $amounts[44] += $values['allowance'] - ($values['contributions'] - $deductBasic);
                $amounts[34] += $values['benefits'] - $values['taxable_benefits'];
                $amounts[48] += $values['taxable_benefits'];
            }
            $recordedWithheld += $values['withheld'];
        }
        $excessBenefits = max(0, $amounts[34] - 9000000);
        $amounts[34] -= $excessBenefits;
        $amounts[48] += $excessBenefits;
        $amounts[38] = array_sum(array_intersect_key($amounts, array_flip(range(29, 37))));
        $amounts[52] = array_sum(array_intersect_key($amounts, array_flip(range(39, 51))));
        $amounts[20] = $amounts[38];
        $amounts[21] = $amounts[52];
        if ($amounts[19] !== $amounts[20] + $amounts[21]) {
            throw ValidationException::withMessages(['records' => 'The compensation breakdown does not reconcile.']);
        }
        $hasPrevious = (bool) $data['has_previous'];
        if (! $hasPrevious && ($this->cents($data['previous_taxable']) !== 0 || $this->cents($data['previous_withheld']) !== 0)) {
            throw ValidationException::withMessages(['has_previous' => 'Select previous employment when prior-employer amounts are entered.']);
        }
        $amounts[22] = $hasPrevious ? $this->cents($data['previous_taxable']) : 0;
        $amounts[23] = $amounts[21] + $amounts[22];
        $amounts[24] = $this->annualTax($amounts[23]);
        $amounts[25] = $this->cents($data['adjusted_withheld']);
        $priorWithheld = $hasPrevious ? $this->cents($data['previous_withheld']) : 0;
        $amounts[26] = $amounts[25] + $priorWithheld;
        $amounts[27] = $this->cents($data['pera_credit']);
        $amounts[28] = $amounts[26] + $amounts[27];
        $signatory = (bool) $data['use_default_signatory']
            ? ['name' => $company['signatory_name'] ?? '', 'title' => $company['signatory_title'] ?? '', 'tin' => $company['signatory_tin'] ?? '']
            : ['name' => $data['signatory_name'] ?? '', 'title' => $data['signatory_title'] ?? '', 'tin' => $data['signatory_tin'] ?? ''];
        if (trim($signatory['name']) === '' || trim($signatory['title']) === '') {
            throw ValidationException::withMessages(['signatory_name' => 'Complete the signatory name and designation, or the default in Settings > BIR.']);
        }
        if (! trim((string) $employee->address)) {
            throw ValidationException::withMessages(['employee' => 'Complete this employee’s registered address in Masters > Employees.']);
        }
        $employerAddress = implode(', ', array_filter(array_map(fn (string $key): string => trim((string) ($company[$key] ?? '')), ['building', 'street', 'barangay', 'city', 'province'])));
        if ($employerAddress === '') {
            throw ValidationException::withMessages(['company' => 'Complete the employer’s registered address in Settings.']);
        }
        $companyTin = (string) ($company['tin'] ?? '');
        if (strlen(preg_replace('/\D/', '', $companyTin)) === 9) {
            $companyTin = Tin::format($companyTin, $company['branch_code'] ?? '00000');
        }
        $start = $year.'-01-01';
        $end = $year.'-12-31';
        if ($employee->hire_date && substr($employee->hire_date, 0, 4) === (string) $year) {
            $start = $employee->hire_date;
        }
        if ($employee->end_date && substr($employee->end_date, 0, 4) === (string) $year) {
            $end = $employee->end_date;
        }

        return [
            'year' => $year, 'from' => CarbonImmutable::parse($start)->format('m/d'), 'to' => CarbonImmutable::parse($end)->format('m/d'),
            'employee' => $employee, 'employeeName' => $data['employee_name'], 'employeeTin' => $this->tin((string) $employee->tin, 'employee.tin'),
            'employerTin' => $this->tin($companyTin, 'company.tin'), 'employerAddress' => $employerAddress, 'company' => $company,
            'amounts' => $amounts, 'priorWithheld' => $priorWithheld, 'recordedWithheld' => $recordedWithheld,
            'previousTin' => $hasPrevious ? $this->tin($data['previous_tin'], 'previous_tin') : [],
            'signatory' => $signatory, 'data' => $data, 'draft' => ! (bool) $data['reviewed'],
        ];
    }
}
