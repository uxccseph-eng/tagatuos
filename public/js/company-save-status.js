(() => {
    'use strict';
    window.CompanySaveStatus = {
        create(database) {
            const banner = document.createElement('aside');
            banner.className = 'company-database-status company-save-status';
            banner.setAttribute('aria-label', 'Company database save status');
            const label = document.createElement('span');
            label.className = 'company-save-message';
            label.setAttribute('role', 'status');
            label.setAttribute('aria-live', 'polite');
            label.setAttribute('aria-atomic', 'true');
            const actions = document.createElement('span');
            actions.className = 'company-save-actions';
            const toggle = document.createElement('button');
            toggle.type = 'button'; toggle.className = 'company-save-details-toggle';
            toggle.textContent = 'Save details';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-controls', 'company-save-details');
            const panel = document.createElement('div');
            panel.id = 'company-save-details'; panel.className = 'company-save-details'; panel.hidden = true;
            let current = null;
            const list = document.createElement('dl');
            const row = (parent, title) => {
                const term = document.createElement('dt'); term.textContent = title;
                const description = document.createElement('dd');
                parent.append(term, description);
                return description;
            };
            const fields = {
                status: row(list, 'Status'),
                company: row(list, 'Company book'),
                scope: row(list, 'Records'),
                date: row(list, 'Last successful save (PHT)'),
                author: row(list, 'Saved by'),
            };
            const reason = document.createElement('p');
            const technical = document.createElement('details');
            const summary = document.createElement('summary'); summary.textContent = 'Technical details';
            const versions = document.createElement('dl');
            const version = row(versions, 'Confirmed database version');
            technical.append(summary, versions);
            panel.append(list, reason, technical);
            function details() {
                if (!current) return;
                const information = database.save_info || {};
                fields.status.textContent = current.state === 'saved' ? 'Saved' : current.state === 'ready' ? 'Ready to save' : current.state === 'pending' ? 'Awaiting confirmation or review' : 'Not saved';
                fields.company.textContent = information.company_name || 'Current company book';
                fields.scope.textContent = 'Sheet transactions and payroll';
                const confirmed = information.revision === current.revision;
                const date = confirmed && information.saved_at ? new Date(information.saved_at) : null;
                fields.date.textContent = date && Number.isFinite(date.getTime()) ? date.toLocaleString('en-PH', {timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short'}) : (current.initialized ? 'Not available for this saved version' : 'No save recorded yet');
                fields.author.textContent = (confirmed && information.saved_by) || (current.initialized ? 'Not available for this saved version' : 'No save recorded yet');
                reason.hidden = current.state === 'saved';
                reason.textContent = current.message;
                version.textContent = String(current.revision);
            }
            toggle.addEventListener('click', () => {
                panel.hidden = !panel.hidden;
                toggle.setAttribute('aria-expanded', String(!panel.hidden));
                if (!panel.hidden) details();
            });
            banner.append(label, actions, toggle, panel);
            const main = document.querySelector('main');
            const toolbar = main?.querySelector('.sheet-toolbar');
            const context = toolbar || main?.querySelector('.books-toolbar, .masters-toolbar, .returns-period-bar, .tax-summary-controls, .settings-heading, .sheet-heading, .books-heading, .page-heading');
            if (context) context.after(banner);
            else if (main) main.append(banner);
            else document.body.append(banner);
            return {
                banner, label, actions,
                update(value) {
                    current = {...value};
                    if (value.state === 'saved' && !value.initialized) current.state = 'ready';
                    banner.dataset.state = current.state;
                    if (current.state === 'saved') label.textContent = toolbar ? 'Saved to company database' : 'Sheet records saved to company database';
                    else if (current.state === 'ready') label.textContent = 'Ready to save. No sheet transactions saved yet.';
                    else if (value.saving && !value.recoveryUnavailable) label.textContent = 'Saving changes…';
                    else label.textContent = value.message;
                    actions.hidden = actions.children.length === 0;
                    if (!panel.hidden) details();
                },
            };
        },
    };
})();
