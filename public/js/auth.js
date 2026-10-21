const authDialog = document.getElementById('auth-help');
const authMessages = {
    terms: ['Terms of Service', 'Service terms will be published before real account registration opens. This demo does not create an account or start a subscription.'],
    privacy: ['Privacy Policy', 'This demo uses a local session. No identity is verified and nothing is sent to Google or an email provider. Use sample data.'],
    security: ['Security architecture', 'This is an onboarding preview, not an authenticated account. Real Google and email authentication will be connected separately.'],
    support: ['Enterprise support', 'Assisted onboarding and the help center are not connected yet. You can explore company setup in demo mode.'],
};
document.querySelectorAll('[data-auth-info]').forEach((link) => {
    link.addEventListener('click', (event) => {
        event.preventDefault();
        const [title, description] = authMessages[link.dataset.authInfo];
        document.getElementById('auth-help-title').textContent = title;
        document.getElementById('auth-help-description').textContent = description;
        authDialog.showModal();
    });
});
const emailInput = document.getElementById('email-input');
document.querySelector('[data-email-form]')?.addEventListener('submit', (event) => {
    emailInput.value = emailInput.value.trim();
    emailInput.setCustomValidity('');
    const validEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value);
    const validUsername = /^[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}$/.test(emailInput.value);
    if (!validEmail && !validUsername) {
        emailInput.setCustomValidity('Enter a valid email address or a username with 3–64 letters, numbers, dots, underscores, or hyphens.');
        emailInput.reportValidity();
        event.preventDefault();
    }
});
emailInput?.addEventListener('input', () => emailInput.setCustomValidity(''));
authDialog?.addEventListener('click', (event) => {
    if (event.target !== authDialog) return;
    const rect = authDialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) authDialog.close();
});
