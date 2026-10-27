(() => {
    if (window.RoleAccessFeedback) return;
    let dialog;
    let onClose;
    function show(feedback, afterClose = null) {
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'role-access-dialog';
            dialog.setAttribute('aria-labelledby', 'role-access-title');
            dialog.setAttribute('aria-describedby', 'role-access-message');
            dialog.innerHTML = '<button type="button" data-role-close aria-label="Close access message">×</button><p class="role-access-label">ACCOUNT ACCESS</p><h2 id="role-access-title">Action not allowed</h2><p id="role-access-role"></p><p id="role-access-message"></p><p class="role-access-help">Ask your company owner to review your role and allowed tabs.</p><button type="button" data-role-dismiss>Close</button>';
            dialog.querySelectorAll('button').forEach(button => button.addEventListener('click', () => dialog.close()));
            dialog.addEventListener('cancel', event => event.preventDefault());
            dialog.addEventListener('close', () => { const callback = onClose; onClose = null; callback?.(); });
            document.body.appendChild(dialog);
        }
        dialog.querySelector('#role-access-role').textContent = 'Your role: ' + (feedback.role || 'Member');
        dialog.querySelector('#role-access-message').textContent = feedback.message || 'Your role does not allow this action.';
        onClose = afterClose;
        if (!dialog.open) dialog.showModal();
    }
    window.RoleAccessFeedback = {show};
    const initial = document.getElementById('role-access-feedback');
    if (initial) {
        show(JSON.parse(initial.textContent), () => {
            const previous = document.referrer ? new URL(document.referrer, location.href) : null;
            if (previous && previous.origin === location.origin && previous.href !== location.href) {
                location.replace(previous.href);
            } else {
                location.replace(document.querySelector('[data-role-home]').dataset.roleHome);
            }
        });
    }
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input, options) => {
        const response = await originalFetch(input, options);
        const url = new URL(input instanceof Request ? input.url : input, location.href);
        if (url.origin === location.origin && response.status === 403 && response.headers.get('content-type')?.includes('application/json')) {
            try {
                const feedback = await response.clone().json();
                if (feedback.permission_denied === true) show(feedback);
            } catch { /* Preserve the original response for its caller. */ }
        }
        return response;
    };
})();
