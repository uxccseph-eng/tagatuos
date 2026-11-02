<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $headings['title'] }}</title>
<style>
@page { size: A4 landscape; margin: 100pt 28pt 34pt; }
body { font-family: DejaVu Sans, sans-serif; font-size: {{ count($columns) > 12 ? '6' : '6.5' }}pt; color: #333; }
header { position: fixed; top: -74pt; left: 0; right: 0; height: 64pt; border-bottom: .5pt solid #999; }
.company { width: 52%; } .report { position: absolute; top: 0; right: 0; width: 46%; text-align: right; }
header strong { font-size: 10pt; color: #111; } header p { margin: 1pt 0; font-size: 7pt; }
footer { position: fixed; bottom: -22pt; left: 0; right: 0; font-size: 6pt; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; }
th { background: #dedede; border: .4pt solid #aaa; text-align: left; padding: 3pt 2pt; vertical-align: top; }
td { padding: 3pt 2pt; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
tr:nth-child(even) td { background: #f5f5f5; }
.money { text-align: right; } .group td { background: #e7e7e7 !important; font-weight: bold; padding-top: 6pt; }
.subtotal td, .total td, .closing td { font-weight: bold; background: #eee !important; }
.opening td { font-style: italic; } .total td { border-top: .6pt solid #999; }
</style>
</head>
<body>
<header><div class="company"><strong>{{ $headings['company'] }}</strong><p>{{ $headings['trade'] }}</p><p>{{ $headings['tin'] }}</p><p>{{ $headings['address'] }}</p></div><div class="report"><strong>{{ $headings['title'] }}</strong><p>{{ $headings['period'] }}</p><p>{{ $headings['range'] }}</p><p>{{ $headings['branch'] }}</p></div></header>
<footer>{{ $headings['footer'] }}</footer>
<table>
<colgroup>@foreach($widths as $width)<col style="width: {{ $width / array_sum($widths) * 100 }}%">@endforeach</colgroup>
<thead><tr>@foreach($columns as $index => $column)<th style="width: {{ $widths[$index] / array_sum($widths) * 100 }}%" @class(['money' => ($column['format'] ?? '') === 'money'])>{{ $column['title'] }}</th>@endforeach</tr></thead>
<tbody>
@if(count($rows) === 1)<tr><td colspan="{{ count($columns) }}">No posted transactions match this period and filter.</td></tr>@endif
@foreach($rows as $row)
<tr class="{{ $row['kind'] }}">
@if($row['kind'] === 'group')<td colspan="{{ count($columns) }}">{{ $row['cells'][0] }}</td>
@else
@foreach($row['cells'] as $index => $value)
<td @class(['money' => ($columns[$index]['format'] ?? '') === 'money'])>@if($value !== null && ($columns[$index]['format'] ?? '') === 'money'){{ $value < 0 ? '('.number_format(-$value, 2).')' : number_format($value, 2) }}@else{{ $value }}@endif</td>
@endforeach
@endif
</tr>
@endforeach
</tbody>
</table>
</body>
</html>
