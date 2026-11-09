<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>0619-E - Preparation {{ $record->id }}</title>
<style>
@page { size: letter portrait; margin: 0; }
* { box-sizing: border-box; } body { margin: 12px; background: #e8edf2; color: #000; font-family: Arial, sans-serif; }
.bir-paper { position: relative; width: 612pt; height: 792pt; background: #fff; margin: auto; overflow: hidden; }
.bir-background { position: absolute; inset: 0; width: 612pt; height: 792pt; }
.bir-field { position: absolute; margin: 0; font: 10pt/14pt "Courier New", monospace; white-space: nowrap; }
.bir-digit { width: 14.4pt; text-align: center; }
.bir-text { white-space: normal; line-height: 12pt; }
.copy-label { position: absolute; left: 17pt; top: 10pt; font-size: 6pt; color: #333; }
@media print { html, body { margin: 0; padding: 0; background: #fff; } .bir-paper { zoom: 1 !important; margin: 0; break-inside: avoid; } }
</style></head><body>
@php
    $digits = [];
    $addDigits = function (string $text, float $x, float $y) use (&$digits): void {
        foreach (mb_str_split($text) as $index => $character) {
            $digits[] = ['value' => $character, 'x' => $x + $index * 14.4, 'y' => $y];
        }
    };
    $addDigits(substr($printData['month'], 0, 2), 46.3, 133);
    $addDigits(substr($printData['month'], 2), 89.3, 133);
    $addDigits($printData['due'], 146.2, 133);
    foreach ([0 => 218.3, 3 => 275.9, 6 => 333.5] as $offset => $x) {
        $addDigits(substr($printData['tin'], $offset, 3), $x, 167);
    }
    $addDigits($printData['branch'], 391.1, 167);
    $addDigits($printData['rdo'], 536.4, 167);
    $addDigits($printData['zip'], 536.4, 242);
    $amountTops = ['14' => 323, '15' => 341.5, '16' => 359.75, '17A' => 388.5, '17B' => 406.75, '17C' => 425, '17D' => 443.5, '18' => 461.75];
    foreach ($printData['amounts'] as $field => $value) {
        $whole = (string) intdiv($value, 100);
        $addDigits($whole, 550.8 - strlen($whole) * 14.4, $amountTops[$field]);
        $addDigits(str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT), 565.2, $amountTops[$field]);
    }
@endphp
<article class="bir-paper" aria-label="0619-E January 2018 prepared copy">
<img class="bir-background" src="{{ asset('bir-0619e-jan2018.png') }}" alt="Blank BIR Form 0619-E January 2018 layout">
<span class="copy-label">PREPARED COPY - NOT PROOF OF BIR FILING OR PAYMENT</span>
@foreach($digits as $digit)<span class="bir-field bir-digit" style="left:{{ $digit['x'] }}pt;top:{{ $digit['y'] }}pt">{{ $digit['value'] }}</span>@endforeach
<span class="bir-field" style="left:{{ $printData['amended'] ? 276 : 319 }}pt;top:133pt">X</span>
<span class="bir-field" style="left:{{ $printData['withheld'] ? 376 : 419 }}pt;top:133pt">X</span>
@if(in_array($printData['category'], ['private', 'government'], true))<span class="bir-field" style="left:{{ $printData['category'] === 'private' ? 444 : 514 }}pt;top:260pt">X</span>@endif
<span class="bir-field bir-text" data-bir-field="name" style="left:19pt;top:194pt;width:566pt;font-size:{{ min(10, 900 / max(1, mb_strlen($printData['name']))) }}pt">{{ $printData['name'] }}</span>
<span class="bir-field" style="left:19pt;top:222pt;font-size:8pt">{{ mb_substr($printData['address'], 0, 100) }}</span>
<span class="bir-field" style="left:19pt;top:241pt;font-size:{{ min(8, 700 / max(1, mb_strlen(mb_substr($printData['address'], 100)))) }}pt">{{ mb_substr($printData['address'], 100) }}</span>
<span class="bir-field" style="left:104pt;top:263pt;font-size:9pt">{{ $printData['contact'] }}</span>
<span class="bir-field" style="left:19pt;top:287pt;width:566pt;font-size:{{ min(10, 900 / max(1, mb_strlen($printData['email']))) }}pt">{{ $printData['email'] }}</span>
@foreach($printData['amounts'] as $field => $value)<span data-bir-amount="{{ $field }}" data-cents="{{ $value }}" hidden></span>@endforeach
</article>
<script>function fitForm(){document.querySelector('.bir-paper').style.zoom=Math.min(1,(innerWidth-24)/816);}fitForm();addEventListener('resize',fitForm);</script>
</body></html>
