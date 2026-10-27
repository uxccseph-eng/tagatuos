<x-account-layout title="Access not available">
    <section class="account-card">
        <h1>Return to your workspace</h1>
        <p class="muted">Your account is still signed in. Your permissions and company records have not changed.</p>
        <a class="button secondary" href="{{ route('account.dashboard') }}">Company books</a>
        <noscript><p role="alert">Your role: {{ $feedback['role'] }}. {{ $feedback['message'] }}</p></noscript>
    </section>
    <script type="application/json" id="role-access-feedback">@json($feedback)</script>
</x-account-layout>
