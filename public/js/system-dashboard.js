(() => {
    const dialog = document.getElementById('registration-review-dialog');
    const form = document.getElementById('registration-review-form');
    const username = document.getElementById('assigned-username');
    const reason = document.getElementById('registration-reason');
    document.querySelectorAll('[data-review-action]').forEach(button => button.addEventListener('click', () => {
        form.reset();
        const action = button.dataset.reviewAction;
        const rejecting = action === 'reject';
        const changing = action === 'username';
        form.action = button.dataset.reviewUrl;
        form.elements.namedItem('_method').value = changing ? 'PUT' : 'POST';
        username.value = button.dataset.username || '';
        username.required = !rejecting; username.disabled = rejecting;
        reason.required = rejecting; reason.disabled = !rejecting;
        form.querySelector('[data-username-field]').hidden = rejecting;
        form.querySelector('[data-reason-field]').hidden = !rejecting;
        document.getElementById('review-title').textContent = rejecting ? 'Reject registration' : changing ? 'Assign username' : 'Approve registration';
        document.getElementById('review-applicant').textContent = button.dataset.name + ' · ' + button.dataset.email;
        document.getElementById('review-explanation').textContent = rejecting ? 'The account will remain blocked from company books. Financial records and company memberships will be preserved.' : changing ? 'The new username can be used to sign in. The account password and memberships stay unchanged.' : 'Approval enables account access using this username or the registered email. The applicant keeps their existing password.';
        const submit = document.getElementById('review-submit');
        submit.textContent = rejecting ? 'Reject registration' : changing ? 'Save username' : 'Approve registration';
        submit.classList.toggle('reject-button', rejecting);
        submit.classList.toggle('primary', !rejecting);
        dialog.showModal();
    }));
    document.querySelectorAll('[data-review-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('cancel', event => event.preventDefault());
})();
