/**
 * Intercepts clicks on the Scheduler's per-row "Run now" GridField button and
 * submits them via fetch() instead of a synchronous form submit. This keeps
 * the CMS UI responsive: the button briefly shows a spinner, then a success
 * or error indicator, without a full page reload.
 */
(function () {
    'use strict';

    const SPINNER_CHAR = '⏳';
    const SUCCESS_CHAR = '✓';
    const ERROR_CHAR = '✗';
    const RESET_DELAY_MS = 1800;
    const POLL_INTERVAL_MS = 1500;
    const POLL_MAX_MS = 15 * 60 * 1000; // safety cap: stop polling after 15 min

    function findForm(button) {
        let node = button.parentElement;
        while (node) {
            if (node.tagName === 'FORM') return node;
            node = node.parentElement;
        }
        return null;
    }

    /**
     * Locate the GridField <tr> for the given button and refresh the
     * "Letzter Lauf" and "Letzter Status" cells from a JSON payload.
     */
    function updateRowCells(button, payload) {
        let row = button.parentElement;
        while (row && row.tagName !== 'TR') row = row.parentElement;
        if (!row) return;

        const setCell = (columnName, value) => {
            const cell = row.querySelector('.col-' + columnName);
            if (cell) cell.textContent = value;
        };
        if (payload.lastRunAt) setCell('LastRunAt', payload.lastRunAt);
        if (payload.lastStatusLabel) setCell('LastStatus', payload.lastStatusLabel);
    }

    /**
     * Poll the job status endpoint until the job is no longer running.
     * Resolves with the final payload, or with null on timeout.
     */
    function pollUntilDone(statusUrl) {
        const started = Date.now();
        return new Promise((resolve) => {
            const tick = () => {
                if (Date.now() - started > POLL_MAX_MS) {
                    resolve(null);
                    return;
                }
                fetch(statusUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                })
                .then(r => r.ok ? r.json() : null)
                .then(payload => {
                    if (!payload) {
                        window.setTimeout(tick, POLL_INTERVAL_MS);
                        return;
                    }
                    if (payload.running) {
                        window.setTimeout(tick, POLL_INTERVAL_MS);
                        return;
                    }
                    resolve(payload);
                })
                .catch(() => window.setTimeout(tick, POLL_INTERVAL_MS));
            };
            window.setTimeout(tick, POLL_INTERVAL_MS);
        });
    }

    function onClick(event) {
        const button = event.currentTarget;
        if (button.dataset.schedulerBusy === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        const form = findForm(button);
        if (!form) return; // let default handler run

        event.preventDefault();
        event.stopImmediatePropagation();

        const statusUrl = button.getAttribute('data-scheduler-status-url');
        const originalLabel = button.innerHTML;
        button.dataset.schedulerBusy = '1';
        button.disabled = true;
        button.innerHTML = SPINNER_CHAR;
        button.classList.add('scheduler-runnow-action--busy');

        const data = new FormData(form);
        if (button.name) {
            data.append(button.name, button.value || '1');
        }

        const dispatch = fetch(form.getAttribute('action') || window.location.href, {
            method: form.method || 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json, text/html;q=0.9, */*;q=0.5',
            },
        });

        dispatch
        .then(response => {
            if (!response.ok) throw new Error('dispatch failed');
            // Now poll the status endpoint until the job finishes.
            if (!statusUrl) return null;
            return pollUntilDone(statusUrl);
        })
        .then(payload => {
            if (payload) {
                updateRowCells(button, payload);
                const success = (payload.lastStatus || '').toUpperCase() === 'SUCCESS';
                button.innerHTML = success ? SUCCESS_CHAR : ERROR_CHAR;
                button.classList.toggle('scheduler-runnow-action--success', success);
                button.classList.toggle('scheduler-runnow-action--error', !success);
            } else {
                button.innerHTML = SUCCESS_CHAR;
                button.classList.add('scheduler-runnow-action--success');
            }
        })
        .catch(() => {
            button.innerHTML = ERROR_CHAR;
            button.classList.add('scheduler-runnow-action--error');
        })
        .finally(() => {
            window.setTimeout(() => {
                button.innerHTML = originalLabel;
                button.disabled = false;
                delete button.dataset.schedulerBusy;
                button.classList.remove(
                    'scheduler-runnow-action--busy',
                    'scheduler-runnow-action--success',
                    'scheduler-runnow-action--error'
                );
            }, RESET_DELAY_MS);
        });
    }

    function attach(root) {
        (root || document).querySelectorAll('.scheduler-runnow-action').forEach(btn => {
            if (btn.dataset.schedulerBound === '1') return;
            btn.dataset.schedulerBound = '1';
            btn.addEventListener('click', onClick, true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => attach(document));
    } else {
        attach(document);
    }

    const mo = new MutationObserver(muts => {
        muts.forEach(m => m.addedNodes.forEach(n => {
            if (n.nodeType === 1) attach(n);
        }));
    });
    mo.observe(document.body, { childList: true, subtree: true });
})();
