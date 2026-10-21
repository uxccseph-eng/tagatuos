<div class="relative w-full overflow-x-auto no-scrollbar rounded-xl bg-surface-container-low/80 backdrop-blur-xl p-1.5 shadow-sm books-tabs-wrap"><nav class="flex items-center gap-1 min-w-max" aria-label="Financial Reports navigation">
@foreach($reportTitles as $key => $label)
<a href="{{ route('reports.show', ['report' => $key]) }}" @if($report === $key) aria-current="page" class="relative px-space-md py-2 rounded-lg bg-surface-container-high text-primary font-label-md text-label-md font-semibold shadow-[0_0_16px_rgba(0,245,160,0.16)] flex items-center gap-1.5" @else class="px-space-md py-2 rounded-lg text-on-surface-variant hover:text-on-surface hover:bg-surface-container font-label-md text-label-md transition-all" @endif>@if($report === $key)<span class="w-2 h-2 rounded-full bg-primary-container shadow-[0_0_8px_rgba(0,245,160,0.9)]" aria-hidden="true"></span>@endif{{ $label }}</a>
@endforeach
</nav></div>
