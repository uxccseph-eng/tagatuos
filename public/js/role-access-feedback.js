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
            dialog.innerHTML = '<button type="button" data-role-close aria-label="Close access message">×</button><p class="role-access-label">ACCOUNT ACCESS</p><h2 id="role-access-title">Action not allowed</h2><p id="role-access-role"></p><p id="role-access-message"></p><p class="role-access-help">Ask your company owner to review your role and allowed tabs.</p><button type="button" data-role-verify hidden>Verify browser in a new tab</button><button type="button" data-role-dismiss>Close</button>';
            dialog.querySelectorAll('[data-role-close],[data-role-dismiss]').forEach(button => button.addEventListener('click', () => dialog.close()));
            dialog.querySelector('[data-role-verify]').addEventListener('click', () => {
                window.open(location.href, '_blank', 'noopener,noreferrer');
            });
            dialog.addEventListener('cancel', event => event.preventDefault());
            dialog.addEventListener('close', () => { const callback = onClose; onClose = null; callback?.(); });
            document.body.appendChild(dialog);
        }
        const bot = feedback.kind === 'bot';
        dialog.querySelector('.role-access-label').textContent = bot ? 'BROWSER VERIFICATION' : 'ACCOUNT ACCESS';
        dialog.querySelector('#role-access-title').textContent = bot ? 'Hosting security blocked this request' : 'Action not allowed';
        dialog.querySelector('#role-access-role').hidden = bot;
        dialog.querySelector('#role-access-role').textContent = 'Your role: ' + (feedback.role || 'Member');
        dialog.querySelector('#role-access-message').textContent = feedback.message || 'Your role does not allow this action.';
        dialog.querySelector('.role-access-help').textContent = bot
            ? 'Keep this window open. Verify the site in the new tab, then return here, close this message and retry Save once. If it is still blocked, Cloudways Support must review the bot-protection rule.'
            : 'Ask your company owner to review your role and allowed tabs.';
        dialog.querySelector('[data-role-verify]').hidden = !bot;
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
    const isBotBlock = value => /access denied by imunify360|imunify360[\s\S]{0,120}(?:bot[- ]protection|anti[- ]bot|captcha)/i.test(value);
    const showBotBlock = () => show({kind: 'bot', message: 'Imunify360 has temporarily blocked the request. Your open form has not been cleared. No automatic save retry will be sent.'});
    window.fetch = async (input, options) => {
        const response = await originalFetch(input, options);
        const url = new URL(input instanceof Request ? input.url : input, location.href);
        if (url.origin === location.origin && response.status === 403) {
            try {
                if (response.headers.get('content-type')?.includes('application/json')) {
                    const feedback = await response.clone().json();
                    if (isBotBlock(String(feedback.message || ''))) showBotBlock();
                    else if (feedback.permission_denied === true) show(feedback);
                } else if (isBotBlock((await response.clone().text()).slice(0, 32768))) {
                    showBotBlock();
                }
            } catch { /* Preserve the original response for its caller. */ }
        }
        return response;
    };
})();
