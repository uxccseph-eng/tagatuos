@auth
@php($switchBooks = app(\App\Services\CompanyBookService::class)->accessibleBooks(auth()->user())->get())
<details class="company-switcher">
    <summary aria-label="Switch company book"><span aria-hidden="true">▤</span><span class="company-switcher-name">{{ $company['company_name'] }}</span><span aria-hidden="true">⌄</span></summary>
    <nav class="company-switcher-menu" aria-label="Available client books">
        <strong>Choose client book</strong>
        @foreach($switchBooks as $switchBook)
            @if(! $switchBook->deletionPending() && (! config('tenancy.enabled') || $switchBook->databaseReady()))
                <a data-company-switch href="{{ route('dashboard', ['company_id' => $switchBook->id]) }}" @if((int) ($company['company_id'] ?? 0) === $switchBook->id) aria-current="true" @endif>{{ $switchBook->name }}@if((int) ($company['company_id'] ?? 0) === $switchBook->id)<span>Current</span>@endif</a>
            @else
                <span class="company-switcher-unavailable">{{ $switchBook->name }} · Setup {{ $switchBook->provisioning_status }}</span>
            @endif
        @endforeach
        <a class="company-switcher-manage" href="{{ route('account.dashboard') }}">Manage company books →</a>
    </nav>
</details>
@else
<span class="company-switcher-preview">{{ $company['company_name'] }}</span>
@endauth
