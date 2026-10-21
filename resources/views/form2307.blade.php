{{-- Official January 2018 ENCS artwork (page 1): https://bir-cdn.bir.gov.ph/local/pdf/2307%20Jan%202018%20ENCS%20v3.pdf --}}
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>BIR Form 2307</title>
<style>
@page{margin:0;size:612pt 936pt}*{box-sizing:border-box}body{margin:0;padding:0;font-family:Helvetica,Arial,sans-serif;color:#000;font-size:9px}.certificate{position:relative;width:612pt;height:935pt;page-break-after:always}.certificate.last{page-break-after:auto}.official-form{position:absolute;top:0;left:0;width:612pt;height:936pt}.value{position:absolute;font-size:10px;line-height:9pt;overflow-wrap:break-word}.address{font-size:8px;line-height:6.5pt}.tin{position:absolute;font-family:Courier,monospace;font-size:12px;letter-spacing:6.8pt;white-space:nowrap}.income{position:absolute;top:365.33pt;left:18.96pt;width:578.4pt;table-layout:fixed;border-collapse:collapse;border-spacing:0}.income td{border:0;padding:0;vertical-align:middle;height:13.682pt;line-height:6.5pt;font-size:8px}.income .description{line-height:6pt}.income .number{text-align:right;white-space:nowrap}.totals{font-weight:bold}.signature{text-align:center;font-size:9px;line-height:9pt}.page-note{position:absolute;top:889pt;left:20pt;width:575pt;font-size:8px;text-align:right}
</style></head><body>
@php
$money = fn (int $value): string => number_format(intdiv($value, 100), 0, '.', ',').'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
@endphp
@foreach($pages as $page)
<div class="certificate {{ $loop->last ? 'last' : '' }}">
<img class="official-form" src="data:image/jpeg;base64,{{ $background }}" alt="BIR Form 2307 January 2018 ENCS">
<div class="value" style="left:155pt;top:107pt;width:110pt;letter-spacing:9pt">{{ $startDate->format('mdY') }}</div>
<div class="value" style="left:403pt;top:107pt;width:110pt;letter-spacing:9pt">{{ $endDate->format('mdY') }}</div>
@foreach(['payee' => 139, 'payor' => 254] as $kind => $top)
@foreach(${$kind}['tin']['groups'] as $part)
<div class="tin" style="left:{{ [210, 262, 314, 365][$loop->index] }}pt;top:{{ $top }}pt">{{ $part }}</div>
@endforeach
@endforeach
<div class="value" style="left:36pt;top:167pt;width:550pt">{{ mb_strtoupper($payee['name']) }}</div>
<div class="value address" style="left:36pt;top:195pt;width:493pt">{{ mb_strtoupper($payee['address']) }}</div>
<div class="value" style="left:544pt;top:196pt;width:40pt;letter-spacing:3pt">{{ $payee['zip'] }}</div>
<div class="value" style="left:36pt;top:282pt;width:550pt">{{ mb_strtoupper($payor['name']) }}</div>
<div class="value address" style="left:36pt;top:310pt;width:493pt">{{ mb_strtoupper($payor['address']) }}</div>
<div class="value" style="left:544pt;top:311pt;width:40pt;letter-spacing:3pt">{{ $payor['zip'] }}</div>
@php
$columns = [[18.96, 157.94], [176.9, 43.23], [220.13, 72], [292.13, 74.3], [366.43, 72.03], [438.46, 72], [510.46, 86.9]];
@endphp
@foreach($columns as $columnIndex => [$left, $width])
<table class="income" style="left:{{ $left }}pt;width:{{ $width }}pt" aria-label="{{ ['Income description', 'ATC', 'Month 1', 'Month 2', 'Month 3', 'Total income', 'Tax withheld'][$columnIndex] }}"><tbody>
@for($index = 0; $index < 10; $index++)
@php
$row = $page[$index] ?? null;
@endphp
<tr><td style="text-align:{{ $columnIndex < 2 ? ($columnIndex === 1 ? 'center' : 'left') : 'right' }}"><div style="padding:0 3pt;line-height:{{ $columnIndex === 0 && $row ? max(4.5, min(8, 1100 / max(1, mb_strlen($row['description'])))) * 1.1 : 8.666 }}px;font-size:{{ $columnIndex === 0 && $row ? max(4.5, min(8, 1100 / max(1, mb_strlen($row['description'])))) : 8 }}px">
@if($row)
@if($columnIndex === 0){{ $row['description'] }}@elseif($columnIndex === 1){{ $row['code'] }}@elseif($columnIndex < 5){{ $money($row['months'][$columnIndex - 2]) }}@elseif($columnIndex === 5){{ $money($row['total']) }}@else{{ $money($row['withheld']) }}@endif
@endif
</div></td></tr>
@endfor
<tr class="totals"><td class="number"><div style="padding:0 3pt">@if($columnIndex >= 2 && $columnIndex < 5){{ $money(array_sum(array_map(fn (array $row): int => $row['months'][$columnIndex - 2], $page))) }}@elseif($columnIndex === 5){{ $money(array_sum(array_column($page, 'total'))) }}@elseif($columnIndex === 6){{ $money(array_sum(array_column($page, 'withheld'))) }}@endif</div></td></tr>
</tbody></table>
@endforeach
<div class="value signature" style="top:732pt;left:37pt;width:550pt">{{ mb_strtoupper($signatory['name']) }}<br>{{ mb_strtoupper($signatory['title']) }}{{ $signatory['tin'] ? ' | TIN: '.$signatory['tin'] : '' }}</div>
@if(count($pages) > 1)<div class="page-note">Form {{ $loop->iteration }} of {{ count($pages) }} · Totals apply to ATCs on this form.</div>@endif
</div>
@endforeach
@if($preview ?? false)
<script>
(() => {
    const wrapper=document.createElement('div');wrapper.style.cssText='position:absolute;top:8px;width:816px;background:#fff;transform-origin:top left';
    document.querySelectorAll('.certificate').forEach(page=>wrapper.append(page));document.body.append(wrapper);document.documentElement.style.overflowX='hidden';document.body.style.background='#e5e7eb';
    function fit(){const width=document.documentElement.clientWidth,scale=Math.min(1,Math.max(0.1,(width-16)/816));wrapper.style.transform='scale('+scale+')';wrapper.style.left=Math.max(0,(width-816*scale)/2)+'px';document.body.style.height=(wrapper.scrollHeight*scale+16)+'px';}
    window.addEventListener('resize',fit);fit();
})();
</script>
@endif
</body></html>
