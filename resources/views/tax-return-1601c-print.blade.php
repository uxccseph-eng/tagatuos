<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>1601-C - Preparation {{ $record->id }}</title>
<style>
@page{size:612pt 936pt;margin:0}*{box-sizing:border-box}body{margin:12px;background:#e8edf2;color:#000;font-family:Arial,sans-serif}.bir-paper{position:relative;width:612pt;height:936pt;background:#fff;margin:0 auto 12px;overflow:hidden;break-after:page}.bir-paper:last-of-type{break-after:auto}.bir-background{position:absolute;inset:0;width:612pt;height:936pt}.bir-field{position:absolute;margin:0;font:10pt/14pt "Courier New",monospace;white-space:nowrap}.bir-digit{width:14.4pt;text-align:center}.bir-text{white-space:normal;line-height:12pt}.copy-label{position:absolute;left:18pt;top:8pt;font-size:6pt;color:#333}@media print{html,body{margin:0;padding:0;background:#fff}.bir-paper{zoom:1!important;margin:0;break-inside:avoid}}
</style></head><body>
@php
    $digits = [1 => [], 2 => []];
    $addDigits = function (string $text, float $x, float $y, int $page = 1, float $step = 14.4) use (&$digits): void {
        foreach (mb_str_split($text) as $index => $character) {
            $digits[$page][] = ['value' => $character, 'x' => $x + $index * $step, 'y' => $y, 'width' => $step];
        }
    };
    $addDigits(substr($printData['month'], 0, 2), 46.6, 110.5);
    $addDigits(substr($printData['month'], 2), 89.4, 110.5);
    foreach ([0 => 233.4, 3 => 290.7, 6 => 348.1] as $offset => $x) {
        $addDigits(substr($printData['tin'], $offset, 3), $x, 143);
    }
    $addDigits($printData['branch'], 405.5, 143);
    $addDigits($printData['rdo'], 549.4, 143, 1, 15.0);
    $addDigits($printData['zip'], 535.5, 213);
    $amountTops = [14 => 296.2, 15 => 321.9, 16 => 337.8, 17 => 353.8, 18 => 369.7, 19 => 385.7, 20 => 402.1, 21 => 417.6, 22 => 433.6, 23 => 449.4, 24 => 465.4, 25 => 481.4, 26 => 497.3, 27 => 513.3, 28 => 529.2, 29 => 545.7, 30 => 561.4, 31 => 577.7, 32 => 593.7, 33 => 609.6, 34 => 625.6, 35 => 641.6, 36 => 657.5];
    foreach ($printData['amounts'] as $field => $value) {
        $whole = (string) intdiv($value, 100);
        $addDigits($whole, 549.1 - strlen($whole) * 14.4, $amountTops[$field]);
        $addDigits(str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT), 564.6, $amountTops[$field]);
    }
    $addDigits($printData['tin'].$printData['branch'], 18.5, 95, 2);
    $addDigits('0', 477.4, 289, 2);
    $addDigits('00', 506.3, 289, 2);
@endphp
@foreach([1, 2] as $page)
<article class="bir-paper" data-bir-page="{{ $page }}" aria-label="1601-C January 2018 prepared copy page {{ $page }}">
<img class="bir-background" src="{{ asset('bir-1601c-jan2018-page'.$page.'.png') }}" alt="Blank BIR Form 1601-C January 2018 page {{ $page }} layout">
<span class="copy-label">PREPARED COPY - NOT PROOF OF BIR FILING OR PAYMENT</span>
@foreach($digits[$page] as $digit)<span class="bir-field bir-digit" style="left:{{ $digit['x'] }}pt;top:{{ $digit['y'] }}pt;width:{{ $digit['width'] }}pt">{{ $digit['value'] }}</span>@endforeach
@if($page === 1)
<span class="bir-field" style="left:234pt;top:111.5pt">X</span>
<span class="bir-field" style="left:{{ $printData['withheld'] ? 307 : 347.5 }}pt;top:111.5pt">X</span>
@if(in_array($printData['category'], ['private', 'government'], true))<span class="bir-field" style="left:{{ $printData['category'] === 'private' ? 449 : 521 }}pt;top:229pt">X</span>@endif
@if($printData['special_relief_no'])<span class="bir-field" style="left:220pt;top:265pt">X</span>@endif
<span class="bir-field bir-text" data-bir-field="name" style="left:19pt;top:170pt;width:570pt;font-size:{{ min(10, 900 / max(1, mb_strlen($printData['name']))) }}pt">{{ $printData['name'] }}</span>
<span class="bir-field" style="left:19pt;top:197pt;font-size:8pt">{{ mb_substr($printData['address'], 0, 100) }}</span>
<span class="bir-field" style="left:19pt;top:213pt;font-size:{{ min(8, 650 / max(1, mb_strlen(mb_substr($printData['address'], 100)))) }}pt">{{ mb_substr($printData['address'], 100) }}</span>
<span class="bir-field" style="left:105pt;top:231pt;font-size:9pt">{{ $printData['contact'] }}</span>
<span class="bir-field" style="left:105pt;top:249pt;width:482pt;font-size:{{ min(9, 700 / max(1, mb_strlen($printData['email']))) }}pt">{{ $printData['email'] }}</span>
<span class="bir-field" data-bir-field="other-exempt-description" style="left:205pt;top:402pt;width:176pt;overflow:hidden;font-size:{{ min(8, 285 / max(1, mb_strlen($printData['other_description']))) }}pt">{{ $printData['other_description'] }}</span>
@foreach($printData['amounts'] as $field => $value)<span data-bir-amount="{{ $field }}" data-cents="{{ $value }}" hidden></span>@endforeach
@else
<span class="bir-field" data-bir-field="page2-name" style="left:220pt;top:95pt;width:371pt;font-size:{{ min(9, 560 / max(1, mb_strlen($printData['name']))) }}pt">{{ $printData['name'] }}</span>
@endif
</article>
@endforeach
<script>function fitForm(){for(const paper of document.querySelectorAll('.bir-paper'))paper.style.zoom=Math.min(1,(innerWidth-24)/816);}fitForm();addEventListener('resize',fitForm);</script>
</body></html>
