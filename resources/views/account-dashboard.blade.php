<x-account-layout title="Company books">
    <section class="page-heading"><div><span class="eyebrow">{{ ucfirst(auth()->user()->type) }} ACCOUNT</span><h1>Company books</h1><p class="muted">Welcome, {{ auth()->user()->name }}. Choose a book to continue.</p></div><div class="count-badge"><strong>{{ $companies->total() }}</strong><span>{{ $companies->total() === 1 ? 'assigned book' : 'assigned books' }}</span></div></section>
    @if(auth()->user()->username)<p class="muted">Your assigned username: <strong>{{ auth()->user()->username }}</strong></p>@endif
    <div class="account-tabs"><span aria-current="page">My company books</span>@if(in_array(auth()->user()->type, ['individual', 'firm'], true))<a href="{{ route('team.entry') }}">Accountants &amp; access</a>@endif</div>
    <div class="books-grid">
        <section aria-label="Assigned company books">
            @forelse($companies as $companyBook)
                <article class="account-card book-card">
                    <div class="book-avatar" aria-hidden="true">▤</div>
                    <div class="book-copy"><h2>{{ $companyBook->name }}</h2><p><span class="role-badge">{{ ucfirst($companyBook->pivot->role) }}</span><span class="muted">{{ in_array($companyBook->workspaceKey(), $configuredWorkspaces, true) ? 'Setup complete' : 'Setup needed' }}</span><span class="role-badge">Shared database</span></p></div>
                    @if($companyBook->pivot->role === 'owner')<a class="team-book-link" href="{{ route('team.index', ['companyId' => $companyBook->id]) }}">Manage team</a>@endif
                    <form method="POST" action="{{ route('account.companies.open', $companyBook->id) }}">@csrf<button class="button secondary" type="submit">Open book <span aria-hidden="true">→</span></button></form>
                </article>
            @empty
                <div class="account-card empty-books"><span class="empty-icon" aria-hidden="true">▤</span><h2>{{ auth()->user()->type === 'accountant' ? 'No books assigned yet' : 'Your first book starts here' }}</h2><p class="muted">{{ auth()->user()->type === 'accountant' ? 'Your company owner will assign the books you can work on.' : 'Create a company book, then complete its registration details.' }}</p></div>
            @endforelse
            @if($companies->hasPages())<nav class="pagination" aria-label="Company book pages">@if($companies->previousPageUrl())<a class="button secondary" href="{{ $companies->previousPageUrl() }}">Previous</a>@endif<span>Page {{ $companies->currentPage() }} of {{ $companies->lastPage() }}</span>@if($companies->nextPageUrl())<a class="button secondary" href="{{ $companies->nextPageUrl() }}">Next</a>@endif</nav>@endif
        </section>
        <aside>
            @if($canCreate)
                <section class="account-card create-book"><span class="eyebrow">{{ $companies->total() ? 'ADD A CLIENT' : 'FIRST COMPANY BOOK' }}</span><h2>Create a company book</h2><p class="muted">{{ auth()->user()->type === 'firm' ? 'Each client gets a separate company book.' : 'Your individual account includes one company book.' }}</p><form method="POST" action="{{ route('account.companies.store') }}" class="field-stack">@csrf<div class="field"><label for="book-name">Company / book name</label><input id="book-name" name="name" value="{{ old('name') }}" maxlength="200" required placeholder="e.g. Sample Trading Inc."></div><button class="button primary full" type="submit">+ Create book</button></form></section>
            @else
                <section class="account-card"><span class="eyebrow">YOUR ACCESS</span><h2>{{ auth()->user()->type === 'individual' ? 'Your company workspace' : 'Assigned by your owner' }}</h2><p class="muted">{{ auth()->user()->type === 'individual' ? 'Open your book to continue setup or return to your accounting workspace.' : 'Only books assigned to your account appear here.' }}</p></section>
            @endif
            <p class="storage-note">Company settings, Masters and Sheet transactions are saved in your company database. Each save checks for changes made by other accountants.</p>
        </aside>
    </div>
</x-account-layout>
