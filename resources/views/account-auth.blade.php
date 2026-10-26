<x-account-layout :title="$registering ? 'Create your account' : 'Sign in'">
    <div class="auth-grid">
        <section class="auth-intro">
            <span class="eyebrow"><span class="status-dot"></span> YOUR ACCOUNTING WORKSPACE</span>
            <h1>Your books.<br>Your team.<br><span class="accent">One place.</span></h1>
            <p>Keep your own company book or manage client books through a firm account.</p>
            <div class="intro-points">
                <div><span>01</span><p><strong>Submit your registration</strong>Individual for your own book. Firm for multiple clients.</p></div>
                <div><span>02</span><p><strong>Wait for approval</strong>The system owner reviews your registration and assigns your username.</p></div>
                <div><span>03</span><p><strong>Set up your company</strong>After approval, open the books assigned to you.</p></div>
            </div>
        </section>
        <section class="account-card auth-card" aria-labelledby="auth-title">
            <span class="eyebrow">{{ $registering ? 'GET STARTED' : 'WELCOME BACK' }}</span>
            <h2 id="auth-title">{{ $registering ? 'Create your account' : 'Sign in to your books' }}</h2>
            <p class="muted">{{ $registering ? 'Submit your details for the system owner’s approval. Your username will be assigned when approved.' : 'Use your assigned username or registered email and password.' }}</p>
            <form method="POST" action="{{ route($registering ? 'register.store' : 'login.store') }}" class="field-stack">
                @csrf
                @if($registering)
                    <div class="field"><label for="account-name">Full name</label><input id="account-name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" maxlength="255" required autofocus></div>
                    <div class="field"><label for="account-type">Account type</label><select id="account-type" name="type" required><option value="individual" @selected(old('type') === 'individual')>Individual — my own company book</option><option value="firm" @selected(old('type') === 'firm')>Firm — multiple client books</option></select><small>Staff accounts are provided by a company owner.</small></div>
                @endif
                <div class="field"><label for="account-email">{{ $registering ? 'Email address' : 'Username or email address' }}</label><input id="account-email" name="email" type="{{ $registering ? 'email' : 'text' }}" autocomplete="{{ $registering ? 'email' : 'username' }}" value="{{ old('email') }}" maxlength="254" required @if(!$registering) autofocus @endif></div>
                <div class="field"><label for="account-password">Password</label><input id="account-password" name="password" type="password" autocomplete="{{ $registering ? 'new-password' : 'current-password' }}" maxlength="128" @if($registering) minlength="12" aria-describedby="password-help" @endif required>@if($registering)<small id="password-help">At least 12 characters, with uppercase and lowercase letters and a number.</small>@endif</div>
                @if($registering)
                    <div class="field"><label for="account-confirmation">Confirm password</label><input id="account-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></div>
                @else
                    <label class="check-field"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me on this device</label>
                @endif
                <button class="button primary full" type="submit">{{ $registering ? 'Submit registration' : 'Sign in' }} <span aria-hidden="true">→</span></button>
            </form>
            <p class="auth-switch">{{ $registering ? 'Already registered?' : 'New to kontadurya?' }} <a href="{{ route($registering ? 'login' : 'register') }}">{{ $registering ? 'Sign in' : 'Create an account' }}</a></p>
            <div class="preview-link"><a href="{{ route('preview.login') }}">Explore the existing demo</a><small>Demo access is separate from your account and company books.</small></div>
        </section>
    </div>
</x-account-layout>
