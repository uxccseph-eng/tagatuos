<x-account-layout title="Company books">
    <section class="page-heading"><div><span class="eyebrow">{{ ucfirst(auth()->user()->type) }} ACCOUNT</span><h1>Company books</h1><p class="muted">Welcome, {{ auth()->user()->name }}. Choose a book to continue.</p></div><div class="count-badge"><strong>{{ $companies->total() }}</strong><span>{{ $companies->total() === 1 ? 'assigned book' : 'assigned books' }}</span></div></section>
    @if(auth()->user()->username)<p class="muted">Your assigned username: <strong>{{ auth()->user()->username }}</strong></p>@endif
    <div class="account-tabs"><span aria-current="page">My company books</span>@if(in_array(auth()->user()->type, ['individual', 'firm'], true))<a href="{{ route('team.entry') }}">Accountants &amp; access</a>@endif</div>
    @foreach($incomingTransfers as $handover)
        <section class="notice"><strong>Firm ownership handover awaiting your review</strong><p>{{ $handover->scope_summary['owner']['name'] }} has requested to transfer their firm and all client books to you.</p><a class="button secondary" href="{{ route('account.ownership.show', $handover->id) }}">Review handover</a></section>
    @endforeach
    <div class="books-grid" data-client-books data-status-url="{{ route('account.companies.index') }}">
        <section aria-label="Assigned company books">
            @forelse($companies as $companyBook)
                <article class="account-card book-card" data-client-book="{{ $companyBook->id }}" data-setup-status="{{ config('tenancy.enabled') ? $companyBook->provisioning_status : 'shared' }}" data-database-ready="{{ $companyBook->databaseReady() ? 'true' : 'false' }}">
                    <div class="book-avatar" aria-hidden="true">▤</div>
                    <div class="book-copy"><h2>{{ $companyBook->name }}</h2><p><span class="role-badge">{{ ucfirst($companyBook->pivot->role) }}</span><span class="muted">{{ in_array($companyBook->workspaceKey(), $configuredWorkspaces, true) ? 'Setup complete' : 'Setup needed' }}</span><span class="role-badge" data-client-status>{{ $companyBook->deletionPending() ? ($companyBook->provisioning_status === 'deleting' ? 'Deleting client' : 'Deletion needs retry') : (config('tenancy.enabled') ? ucfirst($companyBook->provisioning_status).' database' : 'Shared database') }}</span></p></div>
                    @if(! $companyBook->deletionPending() && $companyBook->pivot->role === 'owner')<a class="team-book-link" data-client-team href="{{ route('team.index', ['companyId' => $companyBook->id]) }}">Manage team</a>@endif
                    @if(config('tenancy.enabled'))
                        @include('client-book-progress', ['status' => $companyBook->provisioning_status, 'message' => $companyBook->provisioning_error])
                        @if(in_array($companyBook->id, $setupRetryIds, true))
                            <form data-client-retry method="POST" action="{{ route('account.companies.provision', $companyBook->id) }}" @if($companyBook->provisioning_status !== 'failed') hidden @endif>@csrf<button class="button secondary" type="submit">Retry setup</button></form>
                        @endif
                    @endif
                    <form data-client-open method="POST" action="{{ route('account.companies.open', $companyBook->id) }}" @if(config('tenancy.enabled') && ! $companyBook->databaseReady()) hidden @endif>@csrf<button class="button secondary" type="submit">Open book <span aria-hidden="true">→</span></button></form>
                    @if(config('tenancy.enabled') && $companyBook->pivot->role === 'owner' && in_array(auth()->user()->type, ['firm', 'individual'], true) && ($companyBook->organization_owner_id === null || (int) $companyBook->organization_owner_id === auth()->id()))
                        <a class="book-delete-link" data-client-delete @if($companyBook->provisioning_status === 'deleting') hidden @endif href="{{ route('account.companies.delete', $companyBook->id) }}">{{ $companyBook->provisioning_status === 'delete_failed' ? 'Retry deletion' : 'Delete client' }}</a>
                    @endif
                </article>
            @empty
                <div class="account-card empty-books"><span class="empty-icon" aria-hidden="true">▤</span><h2>{{ auth()->user()->type === 'accountant' ? 'No books assigned yet' : 'Your first book starts here' }}</h2><p class="muted">{{ auth()->user()->type === 'accountant' ? ($canCreate ? 'Create a client book for your firm to get started.' : 'Your firm owner will assign your access. Client creation requires organization Accountant or Manager access with Settings enabled.') : 'Create a company book, then complete its registration details.' }}</p></div>
            @endforelse
            @if($companies->hasPages())<nav class="pagination" aria-label="Company book pages">@if($companies->previousPageUrl())<a class="button secondary" href="{{ $companies->previousPageUrl() }}">Previous</a>@endif<span>Page {{ $companies->currentPage() }} of {{ $companies->lastPage() }}</span>@if($companies->nextPageUrl())<a class="button secondary" href="{{ $companies->nextPageUrl() }}">Next</a>@endif</nav>@endif
        </section>
        <aside>
            @if($canCreate)
                <section class="account-card create-book"><span class="eyebrow">{{ $companies->total() ? 'ADD A CLIENT' : 'FIRST COMPANY BOOK' }}</span><h2>Create a company book</h2><p class="muted">{{ auth()->user()->type === 'accountant' ? 'Create a client for your firm. The firm remains the owner, and you keep your assigned accountant access.' : (auth()->user()->type === 'firm' ? 'Each client gets a separate company book.' : 'Your individual account includes one company book.') }}</p><form method="POST" action="{{ route('account.companies.store') }}" class="field-stack" data-client-create>@csrf
@if(auth()->user()->type === 'accountant')<div class="field"><label for="client-firm">Firm</label><select id="client-firm" name="firm_id" required>@if($creationFirms->count() > 1)<option value="">Choose a firm</option>@endif @foreach($creationFirms as $firm)<option value="{{ $firm->id }}" @selected((string) old('firm_id') === (string) $firm->id)>{{ $firm->name }}</option>@endforeach</select></div>@endif
<div class="field"><label for="book-name">Company / book name</label><input id="book-name" name="name" value="{{ old('name') }}" maxlength="200" required placeholder="e.g. Sample Trading Inc."></div><button class="button primary full" type="submit">+ Create book</button>@include('client-book-progress', ['status' => 'idle', 'message' => null])</form></section>
            @else
                <section class="account-card"><span class="eyebrow">YOUR ACCESS</span><h2>{{ auth()->user()->type === 'individual' ? 'Your company workspace' : 'Assigned by your owner' }}</h2><p class="muted">{{ auth()->user()->type === 'individual' ? 'Open your book to continue setup or return to your accounting workspace.' : 'Only books assigned to your account appear here.' }}</p></section>
            @endif
            @if(auth()->user()->type === 'firm')
                <section class="account-card"><span class="eyebrow">FIRM ACCOUNT</span><h2>Account security</h2><p class="muted">Manage your password or hand over the entire firm and all client books.</p><p><a class="button secondary" href="{{ route('account.password.edit') }}">Change firm owner password</a></p><a class="button secondary" href="{{ route('account.ownership.index') }}">Transfer firm ownership</a></section>
            @endif
            <p class="storage-note">Company settings, Masters and Sheet transactions are saved in your company database. Each save checks for changes made by other accountants.</p>
        </aside>
    </div>
<script src="{{ asset('js/client-book-progress.js') }}?v={{ filemtime(public_path('js/client-book-progress.js')) }}" defer></script>
</x-account-layout>
