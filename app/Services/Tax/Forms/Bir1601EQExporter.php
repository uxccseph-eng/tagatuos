<?php

namespace App\Services\Tax\Forms;

use App\Services\Tax\BaseBirExporter;
use Illuminate\Database\Eloquent\Model;

class Bir1601EQExporter extends BaseBirExporter
{
    public function form(): string
    {
        return '1601-EQ';
    }

    protected function schema(): string
    {
        return '1601eq';
    }

    protected function filename(array $context, array $profile, array $period): string
    {
        return $profile['tin'].$profile['branch_code'].'-1601EQ-'.$period['year'].'Q'.$period['quarter'].'.xml';
    }

    protected function fields(array $context, array $profile, array $period): array
    {
        if (! in_array($profile['agent_category'] ?? '', ['private', 'government'], true)) {
            $this->fail('taxpayer.agent_category', 'Select the withholding agent category in BIR settings.');
        }
        $groups = [];
        $rowCount = 0;
        $pickerCodes = array_flip(json_decode(file_get_contents(resource_path('templates/bir-1601eq-atc-order.json')), true, 512, JSON_THROW_ON_ERROR));
        foreach ($context['withholding'] ?? [] as $row) {
            if (++$rowCount > 50000) {
                $this->fail('withholding', 'Export at most 50,000 source records at a time.');
            }
            $row = $row instanceof Model ? $row->toArray() : $row;
            $atc = $row['atc'] ?? '';
            $rate = $row['rate'] ?? null;
            if (! is_string($atc) || ! preg_match('/^W[IC][0-9]{3}$/D', $atc) || ! is_int($rate) || $rate <= 0 || $rate > 1000000) {
                $this->fail('withholding', 'Each row needs a valid expanded-withholding ATC and a rate scaled to four decimals.');
            }
            if (! array_key_exists($atc, $pickerCodes)) {
                $this->fail('withholding', 'ATC '.$atc.' is not in this verified offline picker layout. A newer saved-file sample is required.');
            }
            $base = $this->cents($row, 'base');
            $tax = $this->cents($row, 'withheld');
            if ($base <= 0 || abs($this->percentage($base, $rate) - $tax) > 1) {
                $this->fail('withholding', 'A withholding row does not reconcile to its tax base and rate.');
            }
            $key = $atc.'|'.$rate;
            $groups[$key] ??= ['atc' => $atc, 'rate' => $rate, 'base' => 0, 'withheld' => 0];
            $groups[$key]['base'] += $base;
            $groups[$key]['withheld'] += $tax;
        }
        uasort($groups, fn (array $left, array $right): int => [$left['atc'], $left['rate']] <=> [$right['atc'], $right['rate']]);
        if (count($groups) > 6) {
            $this->fail('withholding', 'This verified layout has six ATC rows. An expanded eBIRForms sample is required for more rows; no ATCs have been discarded.');
        }
        $prefix = 'frm1601EQ:';
        $fields = [];
        $total = 0;
        foreach (array_values($groups) as $index => $group) {
            if (abs($this->percentage($group['base'], $group['rate']) - $group['withheld']) > 1) {
                $this->fail('withholding', 'ATC '.$group['atc'].' has accumulated rounding differences. Reconcile the quarterly base and withholding before XML export.');
            }
            $row = $index + 1;
            $fraction = rtrim(str_pad((string) ($group['rate'] % 10000), 4, '0', STR_PAD_LEFT), '0');
            $fields[$prefix.'txtAtcCd'.$row] = $group['atc'];
            $fields[$prefix.'txtTaxBase'.$row] = $this->amount($group['base']);
            $fields[$prefix.'txtTaxRate'.$row] = intdiv($group['rate'], 10000).'.'.($fraction === '' ? '0' : $fraction);
            $fields[$prefix.'txtTaxbeWithHeld'.$row] = $this->amount($group['withheld']);
            $fields['AtcCode'.($pickerCodes[$group['atc']] + 1)] = 'true';
            $total += $group['withheld'];
        }
        $options = $context['options'] ?? [];
        $calculation = $context['calculation'] ?? [];
        if ($this->cents($calculation, 'additional_tax') !== 0 || (isset($calculation['tax']) && $calculation['tax'] !== $total)) {
            $this->fail('tax', 'The reviewed withholding must reconcile to the ATC matrix without unallocated additional tax.');
        }
        $first = $this->cents($options, 'remittance_month1');
        $second = $this->cents($options, 'remittance_month2');
        $prior = $this->cents($options, 'prior_overremittance');
        if ($prior > 0 && (int) $period['quarter'] === 1) {
            $this->fail('prior_overremittance', 'A prior-quarter over-remittance cannot be carried in from a previous calendar year.');
        }
        $credits = $first + $second + $prior;
        if (isset($calculation['credits']) && $calculation['credits'] !== $credits) {
            $this->fail('credits', 'Payments / credits must equal the first-month remittance, second-month remittance and eligible prior-quarter over-remittance.');
        }
        $surcharge = $this->cents($calculation, 'surcharge');
        $interest = $this->cents($calculation, 'interest');
        $compromise = $this->cents($calculation, 'compromise');
        $penalties = $surcharge + $interest + $compromise;
        $overRemitted = $total - $credits + $penalties < 0;
        // The offline sample uses the v2018 control IDs, whose numbering differs from the 2019 printed form.
        foreach ([19 => $total, 20 => $first, 21 => $second, 22 => 0, 23 => $prior, 24 => $credits, 25 => $total - $credits, 26 => $surcharge, 27 => $interest, 28 => $compromise, 29 => $penalties, 30 => $total - $credits + $penalties] as $number => $value) {
            $fields[$prefix.'txtTax'.$number] = $this->amount($value);
        }
        $action = $options['overpayment_action'] ?? '';
        if ($overRemitted && (! in_array($action, ['refund', 'certificate', 'carry_over'], true) || ($action === 'carry_over' && (int) $period['quarter'] === 4))) {
            $this->fail('overpayment_action', 'Choose refund / tax credit certificate, or a carryover within the same calendar year.');
        }
        $fields += [
            $prefix.'txtYear' => (string) $period['year'], $prefix.'optAmend:Y' => 'false', $prefix.'optAmend:N' => 'true',
            $prefix.'optWithheld:Y' => $total > 0 ? 'true' : 'false', $prefix.'optWithheld:N' => $total > 0 ? 'false' : 'true',
            $prefix.'txtBranchCode' => $profile['branch_code'], $prefix.'txtRDOCode' => strtoupper($profile['rdo']),
            $prefix.'txtTaxpayerName' => $this->text($profile['name'], true), $prefix.'txtAddress' => $this->text($profile['address'], true),
            $prefix.'txtZipCode' => $profile['zip_code'], $prefix.'txtTelNum' => $this->text($profile['contact_number']),
            $prefix.'optCategory:P' => $profile['agent_category'] === 'private' ? 'true' : 'false', $prefix.'optCategory:G' => $profile['agent_category'] === 'government' ? 'true' : 'false',
            'txtEmail' => $this->text($profile['contact_email']),
            $prefix.'ifRefund' => $overRemitted && $action === 'refund' ? 'true' : 'false',
            $prefix.'ifIssueCert' => $overRemitted && $action === 'certificate' ? 'true' : 'false',
            $prefix.'ifCarriedOver' => $overRemitted && $action === 'carry_over' ? 'true' : 'false',
        ];
        foreach (range(1, 3) as $part) {
            $fields[$prefix.'txtTIN'.$part] = substr($profile['tin'], ($part - 1) * 3, 3);
        }
        foreach (range(1, 4) as $quarter) {
            $fields[$prefix.'optQuarter:'.$quarter] = $quarter === (int) $period['quarter'] ? 'true' : 'false';
        }

        return $fields;
    }
}
