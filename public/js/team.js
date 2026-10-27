(() => {
    const members = JSON.parse(document.getElementById('team-members-data').textContent);
    const templates = JSON.parse(document.getElementById('team-templates-data').textContent);
    const dialog = document.getElementById('member-dialog');
    const form = document.getElementById('member-form');
    const field = name => form.elements.namedItem(name);
    function setTabs(tabs) { form.querySelectorAll('[name="tabs[]"]').forEach(input => { input.checked = input.value === 'dashboard' || tabs.includes(input.value); }); }
    function applyTemplate(key) { const template = templates[key]; if (template) { field('role').value = template.role; setTabs(template.tabs); document.getElementById('member-template').value = key; } }
    function openMember(mode, member = null, template = 'accountant') {
        form.reset();
        const editing = mode === 'edit';
        const organization = editing ? !!member.organization : form.dataset.organization === 'true';
        const scope = organization ? 'all client books in this organization' : 'this company book';
        document.getElementById('member-access-scope').textContent = 'Access changes, suspension and removal affect ' + scope + '. User accounts and financial records are preserved.';
        form.action = editing ? form.dataset.updateUrl.replace('/0', '/' + member.id) : form.dataset.createUrl;
        field('_method').value = editing ? 'PUT' : 'POST';
        field('mode').value = editing ? 'new' : mode;
        field('mode').disabled = editing;
        form.querySelector('[data-new-name]').hidden = mode !== 'new';
        form.querySelector('[data-member-email]').hidden = editing;
        field('name').disabled = mode !== 'new'; field('name').required = mode === 'new';
        field('email').disabled = editing; field('email').required = !editing;
        form.querySelector('[data-suspended-field]').hidden = !editing;
        field('is_suspended').disabled = !editing;
        document.getElementById('member-dialog-title').textContent = editing ? 'Edit access — ' + member.name : mode === 'existing' ? 'Add existing member' : 'Create company login';
        document.getElementById('member-dialog-description').textContent = editing ? 'Choose this member’s role and module access for ' + scope + '.' : mode === 'existing' ? 'Use the email of an existing accountant who has finished password setup. No email is sent.' : 'A secure 10-character temporary password and a one-time setup QR will be generated.';
        if (editing) { field('role').value = member.role; field('is_suspended').checked = member.suspended; setTabs(member.tabs); document.getElementById('member-template').value = ''; }
        else { applyTemplate(template); }
        dialog.showModal();
    }
    document.getElementById('member-template').addEventListener('change', event => applyTemplate(event.target.value));
    document.querySelectorAll('[data-add-mode]').forEach(button => button.addEventListener('click', () => openMember(button.dataset.addMode)));
    document.querySelectorAll('[data-template]').forEach(button => button.addEventListener('click', () => openMember('new', null, button.dataset.template)));
    document.querySelectorAll('[data-edit-member]').forEach(button => button.addEventListener('click', () => openMember('edit', members.find(member => String(member.id) === button.dataset.editMember))));
    document.querySelectorAll('[data-reset-password]').forEach(button => button.addEventListener('click', () => {
        const member = members.find(member => String(member.id) === button.dataset.resetPassword);
        const recovery = document.getElementById('password-recovery-form');
        recovery.reset();
        recovery.action = recovery.dataset.recoveryUrl.replace('/0', '/' + member.id);
        document.getElementById('password-recovery-member').textContent = member.name;
        document.getElementById('password-recovery-dialog').showModal();
    }));
    document.getElementById('password-recovery-dialog')?.addEventListener('close', () => document.getElementById('password-recovery-form').reset());
    document.querySelectorAll('[data-remove-member]').forEach(button => button.addEventListener('click', () => {
        const member = members.find(member => String(member.id) === button.dataset.removeMember);
        const remove = document.getElementById('remove-member-form'); remove.reset();
        remove.action = remove.dataset.removeUrl.replace('/0', '/' + member.id);
        document.getElementById('remove-member-description').textContent = member.name + ' will lose access to ' + (member.organization ? 'all client books in this organization' : 'this company book') + '. Their account and financial records will be preserved.';
        document.getElementById('remove-scope-confirmation').textContent = member.organization ? 'I confirm removal from all client books in this organization.' : 'I confirm removal from this company book.';
        document.getElementById('remove-member-dialog').showModal();
    }));
    document.querySelectorAll('[data-dialog-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen).showModal()));
    document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('dialog').forEach(modal => modal.addEventListener('cancel', event => event.preventDefault()));
    document.querySelectorAll('[data-reveal]').forEach(button => button.addEventListener('click', () => { const input = document.getElementById(button.dataset.reveal); input.type = input.type === 'password' ? 'text' : 'password'; }));
    document.querySelectorAll('[data-copy-target]').forEach(button => button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget);
        try { await navigator.clipboard.writeText(target.value || target.textContent.trim()); document.getElementById('team-copy-status').textContent = 'Link copied.'; const old = button.textContent; button.textContent = 'Copied'; setTimeout(() => { button.textContent = old; }, 1800); }
        catch { document.getElementById('team-copy-status').textContent = 'Could not copy automatically. Select and copy the displayed link.'; }
    }));
    document.querySelector('[data-auto-open]')?.showModal();
    if (document.getElementById('team-preview-notice')) {
        document.querySelectorAll('.account-main form[method="POST"]').forEach(previewForm => {
            previewForm.addEventListener('submit', event => event.preventDefault());
            previewForm.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; button.title = 'Read-only design preview'; });
        });
    }
})();
