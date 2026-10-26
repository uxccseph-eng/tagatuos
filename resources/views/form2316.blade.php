{{-- Official BIR September 2021 ENCS artwork: https://bir-cdn.bir.gov.ph/local/pdf/2316%20Sep%202021%20ENCS_Final_corrected.pdf --}}
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>BIR Form 2316</title><style>
@page{margin:0;size:612pt 936pt}*{box-sizing:border-box}body{margin:0;font-family:Helvetica,Arial,sans-serif;color:#000}.certificate{position:relative;width:612pt;height:935pt}.background{position:absolute;width:612pt;height:936pt;left:0;top:0}.value{position:absolute;font-size:8pt;line-height:9pt;overflow:hidden;overflow-wrap:break-word}.amount{position:absolute;left:485pt;width:98pt;text-align:right;font-size:8pt;line-height:10pt}.summary{left:208pt;width:98pt}.digits{position:absolute;font-family:Courier,monospace;font-size:10pt}.draft{position:absolute;top:425pt;left:110pt;font-size:42pt;color:#bb3333;opacity:.35;transform:rotate(-30deg)}
</style></head><body><div class="certificate"><img class="background" src="data:image/jpeg;base64,{{ $background }}" alt="BIR Form 2316 September 2021 ENCS">
@php
$money = fn (int $value): string => number_format($value / 100, 2, '.', ',');
$tinX = [92,105,118,142,155,168,192,205,218,241,254,267,280,293];
$rightRows = [29=>145,30=>164.5,31=>184,32=>203.5,33=>223,34=>242.5,35=>262,36=>281.5,37=>301,38=>320.5,39=>358,40=>377.5,41=>397,42=>416.5,43=>436,44=>462.5,45=>508,46=>527.5,47=>547,48=>566.5,49=>586,50=>605.5,52=>671.5];
$summaryRows = [19=>515,20=>534.5,21=>554,22=>573.5,23=>593,24=>612.5,25=>632,26=>671.5,27=>691,28=>710.5];
@endphp
<div class="value" style="left:132pt;top:99pt;font-size:11pt;letter-spacing:5pt">{{ $year }}</div><div class="value" style="left:389pt;top:99pt;letter-spacing:10pt">{{ str_replace('/', '', $from) }}</div><div class="value" style="left:516pt;top:99pt;letter-spacing:10pt">{{ str_replace('/', '', $to) }}</div>
@foreach(['employeeTin'=>126,'employerTin'=>339,'previousTin'=>435] as $group=>$top)
@foreach($$group as $index=>$digit)<span class="digits" style="left:{{ $tinX[$index] }}pt;top:{{ $top }}pt">{{ $digit }}</span>@endforeach
@endforeach
<div class="value" style="left:43pt;top:151pt;width:207pt;height:16pt">{{ mb_strtoupper($employeeName) }}</div><div class="value" style="left:267pt;top:151pt;width:35pt">{{ $data['employee_rdo'] ?? '' }}</div>
<div class="value" style="left:43pt;top:180pt;width:207pt;height:15pt;font-size:7pt">{{ mb_strtoupper($employee->address) }}</div><div class="value" style="left:267pt;top:180pt">{{ $data['employee_zip'] ?? '' }}</div>
<div class="value" style="left:48pt;top:259pt;letter-spacing:6pt">{{ $employee->birth_date ? \Carbon\CarbonImmutable::parse($employee->birth_date)->format('mdY') : '' }}</div><div class="value" style="left:168pt;top:259pt;width:136pt">{{ $employee->phone }}</div>
<div class="value" style="left:43pt;top:365pt;width:262pt;height:15pt">{{ mb_strtoupper(($company['registered_name'] ?? '') ?: $company['company_name']) }}</div><div class="value" style="left:43pt;top:390pt;width:207pt;height:17pt;font-size:7pt">{{ mb_strtoupper($employerAddress) }}</div><div class="value" style="left:267pt;top:390pt">{{ $company['zip_code'] ?? '' }}</div>
<div class="value" style="left:{{ $data['employer_type'] === 'main' ? 119 : 203 }}pt;top:409pt">X</div>
@if($data['has_previous'])
<div class="value" style="left:43pt;top:460pt;width:262pt;height:15pt">{{ mb_strtoupper($data['previous_name']) }}</div><div class="value" style="left:43pt;top:486pt;width:207pt;height:16pt;font-size:7pt">{{ mb_strtoupper($data['previous_address']) }}</div><div class="value" style="left:267pt;top:486pt">{{ $data['previous_zip'] ?? '' }}</div>
@endif
@foreach($rightRows as $item=>$top)<div class="amount" style="top:{{ $top }}pt">{{ $money($amounts[$item]) }}</div>@endforeach
<div class="value" style="left:340pt;top:462.5pt;width:145pt;font-size:7pt">Taxable allowances</div>
@foreach($summaryRows as $item=>$top)<div class="amount summary" style="top:{{ $top }}pt">{{ $money($amounts[$item]) }}</div>@endforeach
<div class="amount summary" style="top:651.5pt">{{ $money($priorWithheld) }}</div>
<div class="value" style="left:62pt;top:759pt;width:234pt;text-align:center;font-size:7pt">{{ mb_strtoupper($signatory['name']) }} · {{ mb_strtoupper($signatory['title']) }}</div>
<div class="value" style="left:418pt;top:759pt;letter-spacing:6pt">{{ !empty($data['date_signed']) ? \Carbon\CarbonImmutable::parse($data['date_signed'])->format('mdY') : '' }}</div>
<div class="value" style="left:62pt;top:789pt;width:234pt;text-align:center;font-size:7pt">{{ mb_strtoupper($employeeName) }}</div>
@if($draft)<div class="draft">DRAFT — REVIEW</div>@endif
</div>
@if($preview ?? false)
<script>(()=>{const page=document.querySelector('.certificate');page.style.transformOrigin='top left';document.documentElement.style.overflowX='hidden';document.body.style.background='#e5e7eb';function fit(){const width=document.documentElement.clientWidth,scale=Math.min(1,Math.max(.1,(width-16)/816));page.style.transform='scale('+scale+')';page.style.left=Math.max(0,(width-816*scale)/2)+'px';document.body.style.height=(1248*scale+16)+'px';}window.addEventListener('resize',fit);fit();})();</script>
@endif
</body></html>
