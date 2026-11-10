<?php

namespace App\Services\Tax;

class Bir1601CPrintData
{
    public function __construct(private Bir0619EPrintData $profileData) {}

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function build(object $record, array $snapshot): array
    {
        $data = $this->profileData->build($record, $snapshot);
        $data['mapped'] = false;
        $data['reason'] = 'This preparation has no complete, reconciled 1601-C XML field mapping. Review the compensation buckets and generate an original return with XML before using the supplied form layout.';
        $fields = $snapshot['bir_xml']['fields'] ?? [];
        if (($snapshot['bir_xml']['schema'] ?? '') !== '1601c-v2018' || ! empty($snapshot['amended'])) {
            return $data;
        }
        if (mb_strlen((string) ($fields['frm1601c:txt20Other'] ?? '')) > 45) {
            return [...$data, 'reason' => 'The other non-taxable compensation description is too long for the supplied print box. The saved worksheet retains the complete text.'];
        }
        $amounts = [];
        foreach (range(14, 36) as $item) {
            $text = str_replace(',', '', (string) ($fields['frm1601c:txtTax'.$item] ?? ''));
            if (! preg_match('/^([0-9]{1,11})\.([0-9]{2})$/D', $text, $matches)) {
                return $data;
            }
            $amounts[(string) $item] = (int) $matches[1] * 100 + (int) $matches[2];
        }
        $calculation = $snapshot['calculation'];
        $expected = [
            21 => array_sum(array_intersect_key($amounts, array_flip(range(15, 20)))),
            22 => $amounts[14] - $amounts[21], 24 => $amounts[22] - $amounts[23],
            26 => 0, 27 => $amounts[25], 28 => 0, 29 => 0, 30 => 0, 31 => $amounts[27],
            32 => (int) $calculation['surcharge'], 33 => (int) $calculation['interest'], 34 => (int) $calculation['compromise'],
            35 => $amounts[32] + $amounts[33] + $amounts[34], 36 => $amounts[31] + $amounts[35],
        ];
        foreach ($expected as $item => $value) {
            if ($amounts[$item] !== $value) {
                return $data;
            }
        }
        if ($amounts[25] !== (int) $calculation['tax'] || $amounts[36] !== (int) $calculation['payable'] || (int) ($calculation['credits'] ?? 0) !== 0 || (int) ($calculation['additional_tax'] ?? 0) !== 0) {
            return $data;
        }

        return [...$data, 'mapped' => true, 'amounts' => $amounts, 'other_description' => $fields['frm1601c:txt20Other'] ?? '', 'special_relief_no' => ($fields['frm1601c:SpecialTax_2'] ?? '') === 'true', 'withheld' => $amounts[25] > 0];
    }
}
