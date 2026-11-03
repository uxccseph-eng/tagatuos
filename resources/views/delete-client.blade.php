<x-account-layout title="Delete client">
    <section class="page-heading"><div><span class="eyebrow">Permanent deletion</span><h1>Delete client</h1><p class="muted">{{ $company->name }}</p></div><a class="button secondary" href="{{ route('account.dashboard') }}">Cancel</a></section>
    <section class="account-card delete-client-card">
        <h2>{{ $company->provisioning_status === 'delete_failed' ? 'Retry permanent deletion' : 'Permanently remove this client?' }}</h2>
        <p>This removes the client's accounting database, private data folder, uploaded documents, company profile, and access assignments from this server. This cannot be undone.</p>
        <p class="muted">Other clients and firm user accounts are retained. Copies already saved to external backups are retained. Download any records you need before continuing.</p>
        @if($company->provisioning_status === 'delete_failed')<p class="notice error">An earlier deletion did not finish. The client is blocked while deletion is retried; some resources may already have been removed.</p>@endif
        <form method="POST" action="{{ route('account.companies.destroy', $company->id) }}" class="field-stack">
            @csrf @method('DELETE')
            <div class="field"><label for="delete-client-name">Type the exact client name to confirm: <strong>{{ $company->name }}</strong></label><input id="delete-client-name" name="confirmation_name" value="{{ old('confirmation_name') }}" required autocomplete="off"></div>
            <div class="field"><label for="delete-client-password">Your account password</label><input id="delete-client-password" type="password" name="password" required autocomplete="current-password"></div>
            <button class="button danger" type="submit">Permanently delete client data</button>
        </form>
    </section>
</x-account-layout>
