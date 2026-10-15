/**
 * CronScheduleField — progressive-enhancement UI for a standard 5-field cron
 * expression. The underlying <input> keeps its cron string; the widget parses
 * it on init and rebuilds it on every UI change.
 *
 * Locale strings are optional; German fallback is used if the CMS locale is
 * de_* or if no <html lang> is set.
 */
(function () {
    'use strict';

    const isGerman = () => {
        const lang = (document.documentElement.getAttribute('lang') || '').toLowerCase();
        return lang.startsWith('de');
    };

    const T = isGerman()
        ? {
            mode_simple: 'Einfach',
            mode_advanced: 'Erweitert',
            preset: 'Wiederholung',
            preset_minutes: 'Alle N Minuten',
            preset_hourly: 'Stündlich',
            preset_daily: 'Täglich',
            preset_weekly: 'Wöchentlich',
            preset_monthly: 'Monatlich',
            every: 'Alle',
            minutes: 'Minuten',
            at_minute: 'Minute',
            at_time: 'Uhrzeit',
            weekdays: 'Wochentage',
            day_of_month: 'Tag des Monats',
            preview: 'Cron-Ausdruck',
            advanced_note: 'Freier Cron-Ausdruck (5 Felder: Minute Stunde Tag Monat Wochentag).',
            weekday_short: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'],
        }
        : {
            mode_simple: 'Simple',
            mode_advanced: 'Advanced',
            preset: 'Repeat',
            preset_minutes: 'Every N minutes',
            preset_hourly: 'Hourly',
            preset_daily: 'Daily',
            preset_weekly: 'Weekly',
            preset_monthly: 'Monthly',
            every: 'Every',
            minutes: 'minutes',
            at_minute: 'Minute',
            at_time: 'Time',
            weekdays: 'Weekdays',
            day_of_month: 'Day of month',
            preview: 'Cron expression',
            advanced_note: 'Raw cron expression (5 fields: minute hour day month weekday).',
            weekday_short: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        };

    /**
     * Parse a cron string into a preset descriptor. Returns null when the
     * expression does not match any of the presets we know about.
     */
    function parseCron(str) {
        const parts = (str || '').trim().split(/\s+/);
        if (parts.length !== 5) return null;
        const [min, hour, dom, mon, dow] = parts;
        if (mon !== '*') return null;

        // */N * * * *
        let m = min.match(/^\*\/(\d+)$/);
        if (m && hour === '*' && dom === '*' && dow === '*') {
            return { type: 'minutes', minutes: parseInt(m[1], 10) };
        }
        // N * * * *   (hourly at minute N)
        if (/^\d+$/.test(min) && hour === '*' && dom === '*' && dow === '*') {
            return { type: 'hourly', minute: parseInt(min, 10) };
        }
        // MM HH * * *   (daily at HH:MM)
        if (/^\d+$/.test(min) && /^\d+$/.test(hour) && dom === '*' && dow === '*') {
            return { type: 'daily', minute: parseInt(min, 10), hour: parseInt(hour, 10) };
        }
        // MM HH * * day-list   (weekly)
        if (/^\d+$/.test(min) && /^\d+$/.test(hour) && dom === '*' && /^[\d,]+$/.test(dow)) {
            const days = dow.split(',').map(Number).filter(n => n >= 0 && n <= 6);
            return {
                type: 'weekly',
                minute: parseInt(min, 10),
                hour: parseInt(hour, 10),
                days: days,
            };
        }
        // MM HH DOM * *   (monthly)
        if (/^\d+$/.test(min) && /^\d+$/.test(hour) && /^\d+$/.test(dom) && dow === '*') {
            return {
                type: 'monthly',
                minute: parseInt(min, 10),
                hour: parseInt(hour, 10),
                day: parseInt(dom, 10),
            };
        }
        return null;
    }

    /** Build a cron string from a preset descriptor. */
    function buildCron(p) {
        switch (p.type) {
            case 'minutes':
                return `*/${Math.max(1, p.minutes)} * * * *`;
            case 'hourly':
                return `${p.minute} * * * *`;
            case 'daily':
                return `${p.minute} ${p.hour} * * *`;
            case 'weekly': {
                const days = (p.days && p.days.length) ? [...p.days].sort((a, b) => a - b).join(',') : '*';
                return `${p.minute} ${p.hour} * * ${days}`;
            }
            case 'monthly':
                return `${p.minute} ${p.hour} ${p.day} * *`;
            default:
                return '';
        }
    }

    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        if (attrs) {
            for (const [k, v] of Object.entries(attrs)) {
                if (k === 'class') node.className = v;
                else if (k === 'text') node.textContent = v;
                else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
                else node.setAttribute(k, v);
            }
        }
        if (children) children.forEach(c => c && node.appendChild(c));
        return node;
    }

    function numberInput(value, min, max, onChange) {
        const inp = el('input', { type: 'number', class: 'cron-sched__num', value: String(value) });
        if (min != null) inp.min = String(min);
        if (max != null) inp.max = String(max);
        inp.addEventListener('input', () => onChange(inp.value));
        return inp;
    }

    function enhance(input) {
        if (input.dataset.cronEnhanced === '1') return;
        input.dataset.cronEnhanced = '1';

        // Hide the underlying text input; still submitted with the form.
        input.style.display = 'none';

        // State
        let mode = 'simple'; // or 'advanced'
        let preset = parseCron(input.value);
        if (!preset) {
            // Unrecognisable expression → advanced mode; but still show a default preset.
            if ((input.value || '').trim() !== '') mode = 'advanced';
            preset = { type: 'minutes', minutes: 15 };
        }

        // Root container
        const wrap = el('div', { class: 'cron-sched' });

        // Mode toggle
        const btnSimple = el('button', { type: 'button', class: 'cron-sched__mode', text: T.mode_simple });
        const btnAdvanced = el('button', { type: 'button', class: 'cron-sched__mode', text: T.mode_advanced });
        const modeRow = el('div', { class: 'cron-sched__mode-row' }, [btnSimple, btnAdvanced]);
        wrap.appendChild(modeRow);

        // Simple mode container
        const simple = el('div', { class: 'cron-sched__simple' });
        wrap.appendChild(simple);

        // Advanced mode container: raw input + note
        const advanced = el('div', { class: 'cron-sched__advanced' });
        const rawInput = el('input', { type: 'text', class: 'cron-sched__raw text', value: input.value });
        const note = el('p', { class: 'cron-sched__note', text: T.advanced_note });
        advanced.appendChild(rawInput);
        advanced.appendChild(note);
        wrap.appendChild(advanced);

        // Preview
        const preview = el('div', { class: 'cron-sched__preview' });
        wrap.appendChild(preview);

        input.parentNode.insertBefore(wrap, input.nextSibling);

        function commit(cron) {
            input.value = cron;
            preview.textContent = `${T.preview}: ${cron}`;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function renderMode() {
            btnSimple.classList.toggle('is-active', mode === 'simple');
            btnAdvanced.classList.toggle('is-active', mode === 'advanced');
            simple.style.display = mode === 'simple' ? '' : 'none';
            advanced.style.display = mode === 'advanced' ? '' : 'none';
        }

        function onPresetChange() {
            commit(buildCron(preset));
        }

        function renderSimple() {
            simple.innerHTML = '';

            // Preset dropdown
            const select = el('select', { class: 'cron-sched__preset' });
            const options = [
                ['minutes', T.preset_minutes],
                ['hourly', T.preset_hourly],
                ['daily', T.preset_daily],
                ['weekly', T.preset_weekly],
                ['monthly', T.preset_monthly],
            ];
            options.forEach(([v, l]) => {
                const opt = el('option', { value: v, text: l });
                if (preset.type === v) opt.selected = true;
                select.appendChild(opt);
            });
            select.addEventListener('change', () => {
                switch (select.value) {
                    case 'minutes':
                        preset = { type: 'minutes', minutes: preset.minutes || 15 };
                        break;
                    case 'hourly':
                        preset = { type: 'hourly', minute: preset.minute ?? 0 };
                        break;
                    case 'daily':
                        preset = { type: 'daily', hour: preset.hour ?? 3, minute: preset.minute ?? 0 };
                        break;
                    case 'weekly':
                        preset = { type: 'weekly', hour: preset.hour ?? 9, minute: preset.minute ?? 0, days: preset.days || [1] };
                        break;
                    case 'monthly':
                        preset = { type: 'monthly', hour: preset.hour ?? 3, minute: preset.minute ?? 0, day: preset.day || 1 };
                        break;
                }
                renderSimple();
                onPresetChange();
            });

            simple.appendChild(el('div', { class: 'cron-sched__row' }, [
                el('label', { text: T.preset, class: 'cron-sched__label' }),
                select,
            ]));

            // Preset-specific fields
            const detail = el('div', { class: 'cron-sched__row' });

            if (preset.type === 'minutes') {
                detail.appendChild(el('label', { text: T.every, class: 'cron-sched__label' }));
                detail.appendChild(numberInput(preset.minutes, 1, 59, v => {
                    preset.minutes = Math.max(1, parseInt(v, 10) || 1);
                    onPresetChange();
                }));
                detail.appendChild(el('span', { text: T.minutes }));
            } else if (preset.type === 'hourly') {
                detail.appendChild(el('label', { text: T.at_minute, class: 'cron-sched__label' }));
                detail.appendChild(numberInput(preset.minute, 0, 59, v => {
                    preset.minute = Math.max(0, Math.min(59, parseInt(v, 10) || 0));
                    onPresetChange();
                }));
            } else if (preset.type === 'daily' || preset.type === 'weekly' || preset.type === 'monthly') {
                if (preset.type === 'monthly') {
                    detail.appendChild(el('label', { text: T.day_of_month, class: 'cron-sched__label' }));
                    detail.appendChild(numberInput(preset.day, 1, 31, v => {
                        preset.day = Math.max(1, Math.min(31, parseInt(v, 10) || 1));
                        onPresetChange();
                    }));
                }
                detail.appendChild(el('label', { text: T.at_time, class: 'cron-sched__label' }));
                const hh = numberInput(preset.hour, 0, 23, v => {
                    preset.hour = Math.max(0, Math.min(23, parseInt(v, 10) || 0));
                    onPresetChange();
                });
                const mm = numberInput(preset.minute, 0, 59, v => {
                    preset.minute = Math.max(0, Math.min(59, parseInt(v, 10) || 0));
                    onPresetChange();
                });
                detail.appendChild(hh);
                detail.appendChild(el('span', { text: ':' }));
                detail.appendChild(mm);
            }
            simple.appendChild(detail);

            // Weekday row (weekly only)
            if (preset.type === 'weekly') {
                const wdRow = el('div', { class: 'cron-sched__row' });
                wdRow.appendChild(el('label', { text: T.weekdays, class: 'cron-sched__label' }));
                // Mon..Sun; cron uses 0=Sun, so index 1..6, 0 for Sunday.
                const order = [1, 2, 3, 4, 5, 6, 0];
                order.forEach(d => {
                    const id = `cron-wd-${input.id || Math.random().toString(36).slice(2)}-${d}`;
                    const cb = el('input', { type: 'checkbox', id });
                    if ((preset.days || []).includes(d)) cb.checked = true;
                    cb.addEventListener('change', () => {
                        const days = new Set(preset.days || []);
                        if (cb.checked) days.add(d);
                        else days.delete(d);
                        preset.days = [...days];
                        onPresetChange();
                    });
                    const lbl = el('label', { class: 'cron-sched__wd', for: id, text: T.weekday_short[d] });
                    lbl.insertBefore(cb, lbl.firstChild);
                    wdRow.appendChild(lbl);
                });
                simple.appendChild(wdRow);
            }
        }

        btnSimple.addEventListener('click', () => {
            // Try to parse the raw value; if not parseable, keep current preset.
            const parsed = parseCron(rawInput.value);
            if (parsed) preset = parsed;
            mode = 'simple';
            renderMode();
            renderSimple();
            commit(buildCron(preset));
        });
        btnAdvanced.addEventListener('click', () => {
            rawInput.value = input.value;
            mode = 'advanced';
            renderMode();
        });
        rawInput.addEventListener('input', () => {
            commit(rawInput.value);
        });

        renderMode();
        renderSimple();
        commit(mode === 'advanced' ? rawInput.value : buildCron(preset));
    }

    function scan(root) {
        (root || document).querySelectorAll('input[data-cron-schedule-field]').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => scan(document));
    } else {
        scan(document);
    }

    // The CMS re-renders forms via Entwine/PJAX-like flows; observe DOM mutations
    // so newly injected forms also get enhanced.
    const mo = new MutationObserver(muts => {
        muts.forEach(m => m.addedNodes.forEach(n => {
            if (n.nodeType === 1) scan(n);
        }));
    });
    mo.observe(document.body, { childList: true, subtree: true });
})();
