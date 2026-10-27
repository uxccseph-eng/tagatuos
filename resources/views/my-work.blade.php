<x-account-layout :preview-mode="$previewMode ?? false" title="My work">
    <link rel="stylesheet" href="{{ asset('css/my-work.css') }}?v={{ filemtime(public_path('css/my-work.css')) }}">
    <section class="page-heading work-heading">
        <div><span class="eyebrow"><span class="status-dot"></span>YOUR WORKSPACE</span><h1>My work<span class="accent">.</span></h1><p class="muted">Welcome, {{ ($previewMode ?? false) ? 'Sample Manager' : auth()->user()->name }}. Pick up where your team left off.</p></div>
        <div class="work-date"><span>{{ now('Asia/Manila')->format('l, F j, Y') }}</span><a href="{{ route('my-work', request()->only('q', 'page', 'month')) }}">↻ Refresh overview</a></div>
    </section>

    <div class="work-metrics" aria-label="Work summary">
        @foreach(['books' => ['Client books', 'Available to your account'], 'ready' => ['Ready for work', 'Company setup complete'], 'drafts' => ['Drafts to review', 'Across your enabled Sheets'], 'posted' => ['Entries posted', 'Last 7 days · enabled modules']] as $key => [$label, $hint])
        <section class="account-card work-metric {{ $key === 'drafts' ? 'work-metric-highlight' : '' }}"><span class="eyebrow">{{ $label }}</span><strong>{{ number_format($summary[$key]) }}</strong><span class="muted">{{ $hint }}</span></section>
        @endforeach
    </div>

    <section class="account-card closing-board-summary" aria-label="Monthly closing progress"><div><span class="eyebrow">MONTHLY CLIENT CLOSING</span><h2>{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $closingMonth)->format('F Y') }}</h2><p class="muted">Assign the work, review the checklist, and track completion for each client.</p></div><div class="closing-status-counts">@foreach(\App\Models\MonthlyClosing::STATUSES as $key => $label)<span class="closing-state-{{ $key }}"><strong>{{ $closingCounts[$key] }}</strong>{{ $label }}</span>@endforeach</div></section>

    <div class="work-columns">
        <section class="account-card work-books">
            <div class="work-section-heading"><div><span class="eyebrow">CLIENT WORKSPACE</span><h2>Your company books</h2></div><a href="{{ route('account.dashboard') }}">Manage books ↗</a></div>
            <form method="GET" action="{{ route('my-work') }}" class="work-search closing-board-search" role="search"><label class="closing-month-label" for="closing-month">Closing month<input type="month" id="closing-month" name="month" value="{{ $closingMonth }}" min="1900-01" max="2199-12" required></label><label for="work-search" class="sr-only">Search client books</label><input type="search" id="work-search" name="q" value="{{ request('q') }}" placeholder="Search client name…" maxlength="100"><button class="button secondary compact" type="submit">View</button>@if(request('q'))<a href="{{ route('my-work', ['month' => $closingMonth]) }}">Clear</a>@endif</form>
            <div class="work-table-scroll"><table class="work-table"><thead><tr><th>Company book</th><th>Work status</th><th>Last posting</th><th><span class="sr-only">Open book</span></th></tr></thead><tbody>
                @forelse($companies as $book)
                @php($ready = in_array($book->workspaceKey(), $configuredWorkspaces, true))
                @php($draftCount = $draftCounts[$book->id] ?? 0)
                @php($closing = $closings->get($book->id))
                <tr><td><div class="work-client"><span class="work-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($book->name, 0, 1)) }}</span><div><strong>{{ $book->name }}</strong><span class="muted">{{ ucfirst($book->pivot->role) }}</span></div></div></td>
                    <td>@if(!$ready)<span class="work-pill work-warning">Setup needed</span>@else<span class="work-pill closing-state-{{ $closing?->status ?? 'not_started' }}">{{ \App\Models\MonthlyClosing::STATUSES[$closing?->status ?? 'not_started'] }}</span><small class="closing-row-assignee">{{ $closing?->assignee?->name ?? 'Unassigned' }} · {{ count(array_filter($closing?->checklist ?? [])) }}/{{ count(\App\Models\MonthlyClosing::CHECKLIST) }}</small>@endif @if(in_array($book->id, $sheetCompanyIds) && $draftCount > 0)<small class="closing-row-drafts">{{ $draftCount }} {{ $draftCount === 1 ? 'draft' : 'drafts' }} to review</small>@endif @if(in_array($book->id, $sheetCompanyIds))<a class="closing-row-link" href="{{ route('documents.index', ['companyId' => $book->id]) }}">Document Inbox →</a><a class="closing-row-link" href="{{ route('follow-ups.index', ['companyId' => $book->id]) }}">Client follow-ups ({{ $followUpCounts[$book->id] ?? 0 }}) →</a>@endif @if(in_array($book->id, $activityCompanyIds))<a class="closing-row-link" href="{{ route('closing.show', ['companyId' => $book->id, 'month' => $closingMonth]) }}">Open checklist →</a>@endif</td>
                    <td class="muted">@if(isset($lastPosted[$book->id]))<time datetime="{{ \Carbon\CarbonImmutable::parse($lastPosted[$book->id])->toIso8601String() }}">{{ \Carbon\CarbonImmutable::parse($lastPosted[$book->id])->timezone('Asia/Manila')->format('M j, Y') }}</time>@elseif(in_array($book->id, $activityCompanyIds))No postings yet@else—@endif</td>
                    <td><form method="POST" action="{{ route('account.companies.open', $book->id) }}">@csrf<button class="button secondary compact" type="submit" aria-label="Open {{ $book->name }}">Open <span aria-hidden="true">→</span></button></form></td>
                </tr>
                @empty<tr><td colspan="4"><div class="work-empty"><span class="work-empty-icon" aria-hidden="true">▤</span><h2>{{ request('q') ? 'No matching client books' : 'Your workspace is ready to begin' }}</h2><p class="muted">{{ request('q') ? 'Try another client name or clear your search.' : (auth()->user()->type === 'accountant' ? 'Your organization owner can add you to the team. Assigned client books will appear here.' : 'Create your first company book to start organizing your accounting work.') }}</p><a class="button primary" href="{{ route('account.dashboard') }}">{{ request('q') ? 'View company books' : 'Go to company books' }} →</a></div></td></tr>@endforelse
            </tbody></table></div>
            <div class="work-table-footer"><span class="muted">{{ $companies->total() }} {{ $companies->total() === 1 ? 'book' : 'books' }}{{ request('q') ? ' matching your search' : ' in your workspace' }}</span><nav aria-label="Client book pages">@if($companies->previousPageUrl())<a href="{{ $companies->previousPageUrl() }}">← Previous</a>@endif @if($companies->nextPageUrl())<a href="{{ $companies->nextPageUrl() }}">Next →</a>@endif</nav></div>
        </section>

        <aside class="work-aside">
            <a class="account-card work-team-link" href="{{ route('follow-ups.entry') }}"><div><span class="eyebrow">WAITING ON CLIENTS</span><h2>Client follow-up checklist</h2><p class="muted">{{ array_sum($followUpCounts ?? []) }} open requests across your enabled books. Download one list per client.</p></div><span aria-hidden="true">↗</span></a>
            <section class="account-card work-attention"><span class="eyebrow">NEXT UP</span><h2>Needs attention</h2>
                @if($pendingDocuments)<div class="work-attention-row"><span class="work-attention-dot"></span><div><strong>{{ $pendingDocuments }} source {{ $pendingDocuments === 1 ? 'document needs' : 'documents need' }} attention</strong><p class="muted">Review received files and clarification requests in the <a href="{{ route('documents.entry') }}">Document Inbox</a>.</p></div></div>@endif
                @if($summary['drafts'])<div class="work-attention-row"><span class="work-attention-dot"></span><div><strong>{{ $summary['drafts'] }} saved {{ $summary['drafts'] === 1 ? 'draft' : 'drafts' }}</strong><p class="muted">Open the marked client books to review and post completed entries.</p></div></div>@endif
                @if($setupNeeded)<div class="work-attention-row"><span class="work-attention-dot"></span><div><strong>{{ $setupNeeded }} {{ $setupNeeded === 1 ? 'book needs' : 'books need' }} setup</strong><p class="muted">The book owner must complete company registration before work can begin.</p></div></div>@endif
                @if(!$summary['drafts'] && !$setupNeeded && !$pendingDocuments)<div class="work-clear"><span aria-hidden="true">✓</span><strong>Nothing waiting for review</strong><p class="muted">No source documents, saved drafts in your enabled Sheets, or incomplete company setups need attention.</p></div>@endif
                <p class="work-footnote">Draft counts include database records in your enabled Sheets. Browser entries awaiting transfer are not included.</p>
            </section>
            @if($canManageTeam)<a class="account-card work-team-link" href="{{ route('team.entry') }}"><div><span class="eyebrow">WORK TOGETHER</span><h2>Your organization team</h2><p class="muted">Manage accountants and access across client books.</p></div><span aria-hidden="true">↗</span></a>@endif
            <section class="account-card work-activity"><span class="eyebrow">TEAM ACTIVITY</span><h2>Recent postings</h2>
                @forelse($recentActivity as $entry)<div class="work-activity-item"><span class="work-activity-dot" aria-hidden="true"></span><div><strong>{{ $entry->number }}</strong><span>{{ $entry->company_name }}</span><small>{{ $entry->actor_name ?? 'Team member' }} · {{ \Carbon\CarbonImmutable::parse($entry->created_at)->timezone('Asia/Manila')->format('M j, g:i A') }}</small></div></div>@empty<p class="muted">No posted entries to show in your enabled modules yet.</p>@endforelse
            </section>
        </aside>
    </div>
</x-account-layout>
