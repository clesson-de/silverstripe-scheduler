/**
 * "Run all" progress bar for the Scheduler admin.
 *
 * Renders a standard Silverstripe button first and only reveals the coloured
 * progress bar once the user starts the sequence. One segment is created per
 * enabled task, widths are weighted by the last run duration, and each job is
 * dispatched sequentially via the JSON dispatch endpoint.
 */
(function () {
    'use strict';

    const POLL_INTERVAL_MS = 1500;
    const POLL_MAX_MS = 15 * 60 * 1000;
    const TICK_MS = 200;

    function init(root) {
        if (root.dataset.schedulerRunAllInit === '1') return;
        root.dataset.schedulerRunAllInit = '1';

        let tasks = [];
        try {
            tasks = JSON.parse(root.dataset.tasks || '[]');
        } catch (e) {
            tasks = [];
        }

        const segmentsHost = root.querySelector('[data-segments]');
        const statusEl = root.querySelector('[data-status]');
        const triggerBtn = root.querySelector('.scheduler-run-all__trigger');
        const progressEl = root.querySelector('.scheduler-run-all__progress');
        const idleText = statusEl ? statusEl.textContent : '';

        if (!segmentsHost || !triggerBtn || !progressEl) return;

        if (!tasks.length) {
            triggerBtn.disabled = true;
            if (statusEl) statusEl.textContent = 'Keine aktivierten Tasks.';
            return;
        }

        const totalEstimated = tasks.reduce((sum, t) => sum + Math.max(1, t.estimatedMs), 0);
        const segments = tasks.map((task) => {
            const flex = String(Math.max(1, task.estimatedMs));

            const seg = document.createElement('div');
            seg.className = 'scheduler-run-all__segment';
            seg.style.flex = flex;
            seg.style.setProperty('--seg-color', task.color || '#0d6efd');
            seg.title = task.name;
            const fill = document.createElement('div');
            fill.className = 'scheduler-run-all__fill';
            fill.style.background = task.color || '#0d6efd';
            seg.appendChild(fill);
            segmentsHost.appendChild(seg);
            return { el: seg, fill: fill };
        });

        triggerBtn.addEventListener('click', function () {
            if (root.dataset.schedulerRunAllBusy === '1') return;
            root.dataset.schedulerRunAllBusy = '1';
            triggerBtn.disabled = true;
            triggerBtn.hidden = true;
            progressEl.hidden = false;
            segments.forEach(s => { s.fill.style.width = '0%'; s.el.classList.remove('is-done', 'is-error', 'is-active'); });
            runAll(tasks, segments, statusEl, root)
                .catch(() => {})
                .finally(() => {
                    delete root.dataset.schedulerRunAllBusy;
                    triggerBtn.disabled = false;
                    window.setTimeout(() => {
                        segments.forEach(s => { s.fill.style.width = '0%'; s.el.classList.remove('is-done', 'is-error', 'is-active'); });
                        progressEl.hidden = true;
                        triggerBtn.hidden = false;
                        if (statusEl) statusEl.textContent = idleText;
                    }, 3000);
                });
        });
    }

    async function runAll(tasks, segments, statusEl, root) {
        const failedIds = [];
        for (let i = 0; i < tasks.length; i++) {
            const task = tasks[i];
            const seg = segments[i];
            const row = findGridRow(task.id);
            seg.el.classList.add('is-active');
            if (row) row.classList.add('scheduler-row-active');
            if (statusEl) statusEl.textContent = `(${i + 1}/${tasks.length}) ${task.name} …`;

            let finalPayload = null;
            let taskFailed = false;
            try {
                await dispatchTask(task);
                finalPayload = await pollWithProgress(task, seg);
                seg.fill.style.width = '100%';
                seg.el.classList.add('is-done');
            } catch (err) {
                taskFailed = true;
                seg.fill.style.width = '100%';
                seg.el.classList.add('is-error');
                if (err && err.payload) finalPayload = err.payload;
                if (statusEl) statusEl.textContent = `Fehler bei "${task.name}": ${err && err.message ? err.message : err}`;
            }
            if (taskFailed) failedIds.push(task.id);
            if (row) {
                row.classList.remove('scheduler-row-active');
                if (finalPayload) updateRowCells(row, finalPayload);
            }
            seg.el.classList.remove('is-active');
        }
        if (statusEl) statusEl.textContent = 'Fertig.';

        if (failedIds.length && root) {
            const notifyUrl = root.dataset.notifyUrl;
            if (notifyUrl) {
                const body = new FormData();
                failedIds.forEach(id => body.append('jobIDs[]', String(id)));
                try {
                    await fetch(notifyUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: body,
                    });
                } catch (e) { /* silent — user already sees failure state */ }
            }
        }
    }

    /**
     * Locates the GridField <tr> for a given ScheduledJob ID. SS6 renders
     * rows with `data-id="<recordID>"`; we fall back to scanning any anchor
     * pointing at the record's edit URL.
     */
    function findGridRow(jobId) {
        const idStr = String(jobId);
        let row = document.querySelector(
            '.grid-field[data-name="Jobs"] tr[data-id="' + idStr + '"],'
            + ' .ss-gridfield[data-name="Jobs"] tr[data-id="' + idStr + '"]'
        );
        if (row) return row;
        const link = document.querySelector(
            '.grid-field[data-name="Jobs"] a[href*="/item/' + idStr + '/"],'
            + ' .ss-gridfield[data-name="Jobs"] a[href*="/item/' + idStr + '/"]'
        );
        if (link) {
            let node = link;
            while (node && node.tagName !== 'TR') node = node.parentElement;
            return node;
        }
        return null;
    }

    function updateRowCells(row, payload) {
        const setCell = (columnName, value) => {
            const cell = row.querySelector('.col-' + columnName);
            if (cell) cell.textContent = value;
        };
        if (payload.lastRunAt) setCell('LastRunAt', payload.lastRunAt);
        if (payload.lastStatusLabel) setCell('LastStatus', payload.lastStatusLabel);
    }

    function dispatchTask(task) {
        return fetch(task.dispatchUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        }).then(r => {
            if (!r.ok) throw new Error('dispatch failed (' + r.status + ')');
            return r.json();
        });
    }

    /**
     * Fills the segment gradually based on the estimated duration while
     * polling the status endpoint. Resolves once the job stops running.
     */
    function pollWithProgress(task, seg) {
        const started = Date.now();
        const estimated = Math.max(500, task.estimatedMs);

        return new Promise((resolve, reject) => {
            let stopped = false;

            const tickTimer = window.setInterval(() => {
                if (stopped) return;
                const elapsed = Date.now() - started;
                // Cap the visual fill at 95 % until the poll confirms completion.
                const pct = Math.min(95, (elapsed / estimated) * 100);
                seg.fill.style.width = pct + '%';
            }, TICK_MS);

            const cleanup = () => {
                stopped = true;
                window.clearInterval(tickTimer);
            };

            const pollTick = () => {
                if (stopped) return;
                if (Date.now() - started > POLL_MAX_MS) {
                    cleanup();
                    reject(new Error('timeout'));
                    return;
                }
                fetch(task.statusUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                })
                .then(r => r.ok ? r.json() : null)
                .then(payload => {
                    if (stopped) return;
                    if (!payload || payload.running) {
                        window.setTimeout(pollTick, POLL_INTERVAL_MS);
                        return;
                    }
                    cleanup();
                    const ok = (payload.lastStatus || '').toUpperCase() === 'SUCCESS';
                    if (ok) {
                        resolve(payload);
                    } else {
                        const err = new Error(payload.lastStatusLabel || 'failed');
                        err.payload = payload;
                        reject(err);
                    }
                })
                .catch(() => {
                    if (stopped) return;
                    window.setTimeout(pollTick, POLL_INTERVAL_MS);
                });
            };
            window.setTimeout(pollTick, POLL_INTERVAL_MS);
        });
    }

    function scan(scope) {
        (scope || document).querySelectorAll('[data-scheduler-run-all]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => scan(document));
    } else {
        scan(document);
    }

    const observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
            m.addedNodes && m.addedNodes.forEach((node) => {
                if (node.nodeType !== 1) return;
                if (node.matches && node.matches('[data-scheduler-run-all]')) init(node);
                if (node.querySelectorAll) scan(node);
            });
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
