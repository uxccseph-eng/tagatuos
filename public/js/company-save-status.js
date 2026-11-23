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
            const indicator = document.createElement('span');
            indicator.className = 'company-save-indicator';
            indicator.setAttribute('aria-hidden', 'true');
            const confirmation = document.createElement('span');
            confirmation.className = 'company-save-confirmation';
            confirmation.setAttribute('aria-hidden', 'true');
            let confirmationTimer = null;
            let current = null;
            let saveStartedAtRevision = null;
            let lastConfirmationAt = -Infinity;
            let confirmationAnimations = [];
            function stopConfirmation() {
                clearTimeout(confirmationTimer);
                confirmationTimer = null;
                banner.dataset.confirmed = 'false';
                for (const animation of confirmationAnimations) animation.cancel();
                confirmationAnimations = [];
            }
            function confirmSave() {
                const now = Date.now();
                if (document.hidden || now - lastConfirmationAt < 1500) return;
                stopConfirmation();
                const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
                const easing = 'cubic-bezier(0.23, 1, 0.32, 1)';
                banner.dataset.confirmed = 'true';
                lastConfirmationAt = now;
                if (typeof indicator.animate === 'function') {
                    try {
                        confirmationAnimations.push(indicator.animate(reduced
                            ? [{opacity: 0.4}, {opacity: 1}]
                            : [{opacity: 0, transform: 'scale(0.95) rotate(-12deg)'}, {opacity: 1, transform: 'none'}],
                        {duration: 200, easing}));
                        confirmationAnimations.push(confirmation.animate(reduced
                            ? [{opacity: 0}, {opacity: 1}]
                            : [{opacity: 0, transform: 'scaleX(0.95)'}, {opacity: 1, transform: 'scaleX(1)'}],
                        {duration: 250, easing}));
                    } catch {
                        for (const animation of confirmationAnimations) animation.cancel();
                        confirmationAnimations = [];
                    }
                }
                confirmationTimer = setTimeout(stopConfirmation, 1400);
            }
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
            banner.append(label, actions, toggle, panel, indicator, confirmation);
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
                    indicator.textContent = current.state === 'saved' ? '✓' : ['error', 'warning'].includes(current.state) ? '!' : current.state === 'pending' ? '↻' : '';
                    banner.dataset.saving = String(current.state === 'pending' && Boolean(value.saving) && !value.recoveryUnavailable);
                    if (current.state === 'saved') label.textContent = toolbar ? 'Saved to company database' : 'Sheet records saved to company database';
                    else if (current.state === 'ready') label.textContent = 'Ready to save. No sheet transactions saved yet.';
                    else if (value.saving && !value.recoveryUnavailable) label.textContent = 'Saving changes…';
                    else label.textContent = value.message;
                    actions.hidden = actions.children.length === 0;
                    if (current.saving && saveStartedAtRevision === null) saveStartedAtRevision = current.revision;
                    if (current.state === 'saved') {
                        if (saveStartedAtRevision !== null && current.revision > saveStartedAtRevision) confirmSave();
                        saveStartedAtRevision = null;
                    } else {
                        stopConfirmation();
                        if (!current.saving) saveStartedAtRevision = null;
                    }
                    if (!panel.hidden) details();
                },
            };
        },
    };
})();
