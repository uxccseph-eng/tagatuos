<x-account-layout title="Registration status">
    <section class="account-card password-card">
        <span class="eyebrow">{{ ucfirst(auth()->user()->type) }} REGISTRATION</span>
        <h1>{{ auth()->user()->registration_status === 'rejected' ? 'Registration not approved' : 'Awaiting owner approval' }}</h1>
        <p class="muted">{{ auth()->user()->registration_status === 'rejected' ? 'The system owner reviewed your registration and provided the reason below.' : 'Your registration has been received. The system owner will review it and assign your username before company access is enabled.' }}</p>
        <dl class="registration-details"><dt>Applicant</dt><dd>{{ auth()->user()->name }}</dd><dt>Email</dt><dd>{{ auth()->user()->email }}</dd><dt>Submitted</dt><dd>{{ auth()->user()->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} PHT</dd><dt>Status</dt><dd>{{ ucfirst(auth()->user()->registration_status) }}</dd></dl>
        @if(auth()->user()->registration_status === 'rejected')<div class="notice error"><strong>Reason</strong><p>{{ auth()->user()->rejection_reason }}</p></div>@endif
        <a class="button primary full" href="{{ route('account.registration-status') }}">Check registration status</a>
        <p class="muted small-status-copy">Your password stays private. The system owner assigns your username without changing your password.</p>
    </section>
</x-account-layout>
