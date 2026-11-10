<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>2550Q - Preparation {{ $record->id }}</title>
<style>
@page{size:612pt 1008pt;margin:0}*{box-sizing:border-box}body{margin:12px;background:#e8edf2;color:#000;font-family:Arial,sans-serif}.bir-paper{position:relative;width:612pt;height:1008pt;background:#fff;margin:0 auto 12px;overflow:hidden;break-after:page}.bir-paper:last-of-type{break-after:auto}.bir-background{position:absolute;inset:0;width:612pt;height:1008pt}.bir-field{position:absolute;margin:0;font:10pt/14pt "Courier New",monospace;white-space:nowrap}.bir-digit{text-align:center}.bir-check{width:14.2pt;text-align:center}.bir-mask{background:white;height:13pt}.bir-text{font-family:Arial,sans-serif;white-space:normal;line-height:12pt}.copy-label{position:absolute;left:22pt;top:8pt;font:6pt Arial,sans-serif;color:#333}@media print{html,body{margin:0;padding:0;background:#fff}.bir-paper{zoom:1!important;margin:0;break-inside:avoid}}
</style></head><body>
@php
    $digits = [1 => [], 2 => []];
    $addDigits = function (string $text, float $x, float $y, int $page = 1, float $step = 14.2, bool $mask = false) use (&$digits): void {
        foreach (mb_str_split($text) as $index => $character) {
            $digits[$page][] = ['value' => $character, 'x' => $x + $index * $step + ($mask ? 1 : 0), 'y' => $y, 'width' => $step - ($mask ? 2 : 0), 'mask' => $mask];
        }
    };
    $addDigits(substr($printData['year_end'], 0, 2), 304.9, 109.6);
    $addDigits(substr($printData['year_end'], 2), 333.2, 109.6);
    foreach (['from' => 64.4, 'to' => 204.8] as $key => $x) {
        $addDigits($printData[$key], $x, 138.4);
    }
    foreach ([0 => 233.2, 3 => 290.1, 6 => 347.2] as $offset => $x) {
        $addDigits(substr($printData['tin'], $offset, 3), $x, 170.5);
    }
    $addDigits($printData['branch'], 404.6, 170.5, 1, 14.2, true);
    $addDigits($printData['rdo'], 547.1, 170.5);
    $addDigits($printData['zip'], 532.4, 246.8);
    $addDigits($printData['tin'], 22.7, 92.6, 2);
    $addDigits($printData['branch'], 150.5, 92.6, 2, 14.2, true);
    $amountTops = [15 => 347.6, 16 => 376.5, 17 => 394.8, 18 => 413.1, 19 => 431.4, 20 => 449.6, 21 => 467.9, 22 => 486.2, 23 => 504.5, 24 => 522.7, 25 => 541.0, 26 => 559.3];
    $page2Tops = ['31A' => 134.4, '31B' => 134.4, '32A' => 150.3, '33A' => 166.3, '34A' => 182.2, '34B' => 182.2, 35 => 200.0, 36 => 215.9, 37 => 231.9, 38 => 258.1, 39 => 274.1, 40 => 290.0, 41 => 306.0, 42 => 321.9, 43 => 337.9, '44A' => 368.1, '44B' => 368.1, '45A' => 384.1, '45B' => 384.1, '46A' => 400.1, '46B' => 400.1, '47A' => 416.1, '47B' => 416.1, '48A' => 432.1, '49A' => 448.0, '50A' => 463.9, '50B' => 463.9, 51 => 482.1, 52 => 507.9, 53 => 525.5, 54 => 541.5, 55 => 557.5, 56 => 573.5, 57 => 589.4, 58 => 605.4, 59 => 621.2, 60 => 637.2, 61 => 653.2];
    foreach ($printData['amounts'] as $field => $value) {
        $page = isset($amountTops[$field]) ? 1 : 2;
        $top = $page === 1 ? $amountTops[$field] : $page2Tops[$field];
        $decimalX = str_ends_with((string) $field, 'A') ? 333.2 : 547.0;
        $whole = ($value < 0 ? '-' : '').(string) intdiv(abs($value), 100);
        $addDigits($whole, $decimalX - strlen($whole) * 14.2, $top, $page);
        $addDigits(str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT), $decimalX + 14.2, $top, $page);
    }
    $schedulePositions = ['sched1TotalBalPrev' => [335.4, 744.5], 'sched1TotalBalNext' => [589.8, 744.5], 'sched2InputTaxDirect' => [589.8, 774.8], 'sched2VatExemptSale' => [187.7, 793.8], 'sched2TotalSales' => [187.7, 803.0], 'sched2AmountInputTax' => [441.1, 798.0], 'sched2TotalRatable' => [589.8, 794.0], 'sched2TotalAttr' => [589.8, 810.2], 'sched3TotalTax' => [589.8, 860.5], 'sched4AmountPaid' => [589.8, 908.6]];
@endphp
@foreach([1, 2] as $page)
<article class="bir-paper" data-bir-page="{{ $page }}" aria-label="2550Q April 2024 prepared copy page {{ $page }}">
<img class="bir-background" src="{{ asset('bir-2550q-apr2024-page'.$page.'.png') }}" alt="Blank BIR Form 2550Q April 2024 page {{ $page }} layout">
<span class="copy-label">PREPARED COPY - NOT PROOF OF BIR FILING OR PAYMENT</span>
@foreach($digits[$page] as $digit)<span class="bir-field bir-digit {{ $digit['mask'] ? 'bir-mask' : '' }}" style="left:{{ $digit['x'] }}pt;top:{{ $digit['y'] }}pt;width:{{ $digit['width'] }}pt">{{ $digit['value'] }}</span>@endforeach
@if($page === 1)
<span class="bir-field bir-check" style="left:{{ $printData['fiscal'] ? 141.2 : 74 }}pt;top:109.5pt">X</span>
<span class="bir-field bir-check" style="left:{{ [1 => 434.6, 2 => 470.4, 3 => 512.6, 4 => 554.3][$printData['quarter']] }}pt;top:109.5pt">X</span>
<span class="bir-field bir-check" style="left:389.6pt;top:138.4pt">X</span><span class="bir-field bir-check" style="left:533.3pt;top:138.4pt">X</span>
<span class="bir-field bir-text" data-bir-field="name" style="left:24pt;top:200pt;width:563pt;font-size:{{ min(10, 1050 / max(1, mb_strlen($printData['name']))) }}pt">{{ $printData['name'] }}</span>
<span class="bir-field bir-text" data-bir-field="address" style="left:24pt;top:230pt;width:563pt;font-size:8pt">{{ mb_substr($printData['address'], 0, 95) }}</span>
<span class="bir-field bir-text" style="left:24pt;top:247pt;width:435pt;font-size:8pt">{{ mb_substr($printData['address'], 95) }}</span>
<span class="bir-field bir-text" style="left:24pt;top:280pt;width:175pt;font-size:8pt">{{ $printData['contact'] }}</span>
<span class="bir-field bir-text" style="left:210pt;top:280pt;width:375pt;font-size:8pt">{{ $printData['email'] }}</span>
<span class="bir-field bir-check" style="left:{{ [1 => 161.5, 2 => 231.8, 3 => 302.3, 4 => 389.6][$printData['size']] }}pt;top:294pt">X</span>
<span class="bir-field bir-check" style="left:235.1pt;top:313.5pt">X</span>
<span class="bir-field bir-text" data-bir-description="19" style="left:175pt;top:431.7pt;width:190pt;font-size:7pt;line-height:9pt">{{ $printData['descriptions'][19] }}</span>
@else
<span class="bir-field bir-text" data-bir-field="page2-name" style="left:223pt;top:93pt;width:365pt;font-size:{{ min(9, 610 / max(1, mb_strlen($printData['name']))) }}pt">{{ $printData['name'] }}</span>
@foreach([42 => [110, 322, 250], 47 => [100, 416, 59], 56 => [112, 573.5, 247]] as $item => $position)<span class="bir-field bir-text" data-bir-description="{{ $item }}" style="left:{{ $position[0] }}pt;top:{{ $position[1] }}pt;width:{{ $position[2] }}pt;font-size:7pt;line-height:9pt">{{ $printData['descriptions'][$item] }}</span>@endforeach
@foreach($schedulePositions as $key => $position)<span class="bir-field" data-bir-schedule="{{ $key }}" data-cents="{{ $printData['schedules'][$key] }}" style="left:{{ $position[0] - 82 }}pt;top:{{ $position[1] }}pt;width:80pt;text-align:right;font-size:{{ in_array($key, ['sched2VatExemptSale', 'sched2TotalSales']) ? 7 : 8 }}pt;line-height:10pt">{{ number_format($printData['schedules'][$key] / 100, 2) }}</span>@endforeach
@endif
@foreach($printData['amounts'] as $field => $value)@if(($page === 1) === isset($amountTops[$field]))<span data-bir-amount="{{ $field }}" data-cents="{{ $value }}" hidden></span>@endif @endforeach
</article>
@endforeach
<script>function fitForm(){for(const paper of document.querySelectorAll('.bir-paper'))paper.style.zoom=Math.min(1,(innerWidth-24)/816);}fitForm();addEventListener('resize',fitForm);</script>
</body></html>
