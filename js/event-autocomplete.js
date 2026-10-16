/** Shared, session-authenticated suggestions for existing OSIRIS events. */
(function (window, document, $) {
    'use strict';

    const instances = new WeakMap();
    let nextId = 0;
    const translate = (en, de) => typeof window.lang === 'function' ? window.lang(en, de) : en;

    // Selection equivalence only; all fuzzy matching and ranking happen on the server.
    function normalize(value) {
        return String(value || '').normalize('NFC').toLowerCase()
            .replace(/ς/g, 'σ')
            .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
            .replace(/æ/g, 'ae').replace(/œ/g, 'oe')
            .replace(/[łøđðþı]/g, character => ({ ł: 'l', ø: 'o', đ: 'd', ð: 'd', þ: 'th', ı: 'i' }[character]))
            .normalize('NFKD').replace(/\p{M}/gu, '')
            .replace(/[^\p{L}\p{N}]+/gu, ' ').trim().replace(/\s+/g, ' ');
    }

    function validEvent(event) {
        return event && typeof event.id === 'string' && /^[a-f\d]{24}$/i.test(event.id)
            && typeof event.title === 'string';
    }

    function invalidate(state) {
        // Invalidate before abort: jQuery can synchronously invoke callbacks on abort.
        state.sequence++;
        window.clearTimeout(state.timer);
        state.timer = null;
        if (state.request) state.request.abort();
        state.request = null;
        state.input.removeAttribute('aria-busy');
    }

    function close(state) {
        state.panel.hidden = true;
        state.active = -1;
        state.input.setAttribute('aria-expanded', 'false');
        state.input.removeAttribute('aria-activedescendant');
    }

    function announce(state, message) {
        state.status.textContent = message;
        state.panel.hidden = false;
        state.input.setAttribute('aria-expanded', state.matches.length ? 'true' : 'false');
    }

    function linkedField(input, id) {
        return (input.form && input.form.querySelector('#' + id)) || document.getElementById(id);
    }

    function clearChangedSelection(state) {
        if (!state.selected || state.selected.titles.includes(normalize(state.input.value))) return;
        state.selected = null;
        delete state.input.dataset.eventId;
        delete state.input.dataset.eventTitle;
        delete state.input.dataset.eventTitleFull;
        const id = linkedField(state.input, 'conference_id');
        const label = linkedField(state.input, 'connected-conference');
        if (id) id.value = '';
        if (label) label.textContent = translate('No event connected', 'Kein Event verknüpft');
        state.input.classList.remove('is-valid');
    }

    function setSelection(input, event) {
        if (!input || !validEvent(event)) return;
        const state = attach(input);
        invalidate(state);
        close(state);
        input.value = event.title;
        input.dataset.eventId = event.id;
        input.dataset.eventTitle = event.title;
        input.dataset.eventTitleFull = event.title_full || '';
        state.selected = { id: event.id, titles: [event.title, event.title_full].filter(Boolean).map(normalize) };
        const id = linkedField(input, 'conference_id');
        const label = linkedField(input, 'connected-conference');
        if (id) id.value = event.id;
        if (label) label.textContent = translate('Connected to ', 'Verknüpft mit ') + event.title;
    }

    function select(state, event) {
        invalidate(state);
        close(state);
        if (state.input.dataset.eventAutocomplete === 'link') {
            window.location.assign(ROOTPATH + '/conferences/view/' + encodeURIComponent(event.id));
            return;
        }
        if (typeof window.selectEvent === 'function') {
            window.selectEvent(event.id, event.title, event.start || '', event.end || '',
                event.location || '', event.country || '', event.title_full || '');
        } else if (state.input.dataset.eventAutocomplete === 'conference') {
            setSelection(state.input, event);
        }
        const modal = state.input.closest('#add-event');
        if (modal) {
            modal.classList.remove('show');
            if (window.location.hash === '#add-event') window.location.hash = '#close-modal';
        }
        if (state.input.dataset.eventAutocomplete === 'select') {
            const target = document.getElementById('conference') || document.getElementById('event-select-button');
            if (target) {
                target.focus();
                const targetState = instances.get(target);
                if (targetState) { invalidate(targetState); close(targetState); }
            }
        }
    }

    function details(event) {
        const dates = [event.start, event.end && event.end !== event.start ? event.end : ''].filter(Boolean).join(' – ');
        return [dates, event.location, event.country].filter(Boolean).join(' · ');
    }

    function render(state, matches) {
        state.list.textContent = '';
        state.matches = Array.isArray(matches) ? matches.filter(validEvent).slice(0, 10) : [];
        state.active = -1;
        state.input.removeAttribute('aria-activedescendant');
        state.matches.forEach(function (event, index) {
            const link = state.input.dataset.eventAutocomplete === 'link';
            const option = document.createElement(link ? 'a' : 'button');
            option.id = state.list.id + '-' + index;
            option.className = 'event-autocomplete-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.tabIndex = -1;
            if (link) option.href = ROOTPATH + '/conferences/view/' + encodeURIComponent(event.id);
            else option.type = 'button';
            const title = document.createElement('strong');
            title.textContent = event.title;
            option.appendChild(title);
            if (event.title_full && event.title_full !== event.title) {
                const fullTitle = document.createElement('span');
                fullTitle.textContent = event.title_full;
                option.appendChild(fullTitle);
            }
            const metadata = document.createElement('small');
            metadata.textContent = details(event);
            option.appendChild(metadata);
            // Keep focus on the combobox until click is delivered.
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', function (click) {
                if (link && (click.ctrlKey || click.metaKey || click.shiftKey || click.altKey)) return;
                click.preventDefault();
                select(state, event);
            });
            state.list.appendChild(option);
        });
        announce(state, state.matches.length
            ? translate('Existing events: use ↑/↓ and Enter to select.', 'Vorhandene Veranstaltungen: mit ↑/↓ und Enter auswählen.')
            : translate('No matching events found. You can enter a new event.', 'Keine passenden Veranstaltungen gefunden. Eine neue Veranstaltung kann eingegeben werden.'));
    }

    function contextValue(input, name) {
        const selector = input.getAttribute('data-event-' + name);
        if (!selector) return '';
        const source = document.querySelector(selector);
        return source ? source.value : '';
    }

    function queueSearch(state) {
        invalidate(state);
        close(state);
        state.matches = [];
        state.list.textContent = '';
        const query = state.input.value.trim();
        if (normalize(query).length < 2) {
            announce(state, translate('Type at least two characters to find an existing event.', 'Mindestens zwei Zeichen eingeben, um eine vorhandene Veranstaltung zu finden.'));
            return;
        }
        if (Array.from(query).length > 200) {
            announce(state, translate('Use up to 200 characters to search.', 'Für die Suche höchstens 200 Zeichen eingeben.'));
            return;
        }
        const sequence = state.sequence;
        state.timer = window.setTimeout(function () {
            state.timer = null;
            state.input.setAttribute('aria-busy', 'true');
            const data = { q: query, limit: 10 };
            if (state.input.dataset.eventExclude) data.exclude = state.input.dataset.eventExclude;
            for (const key of ['start', 'location']) {
                const value = contextValue(state.input, key);
                if (value) data[key] = value;
            }
            const current = () => sequence === state.sequence && state.input.isConnected
                && state.input.value.trim() === query;
            state.request = $.ajax({
                url: ROOTPATH + '/ajax/conferences/suggest',
                type: 'GET', dataType: 'json', data: data, timeout: 10000,
                success: function (response) {
                    if (!current()) return;
                    state.request = null;
                    state.input.removeAttribute('aria-busy');
                    render(state, response && response.results);
                },
                error: function (xhr, status) {
                    if (status === 'abort' || !current()) return;
                    state.request = null;
                    state.input.removeAttribute('aria-busy');
                    announce(state, translate('Event suggestions could not be loaded. Please try again.', 'Veranstaltungsvorschläge konnten nicht geladen werden. Bitte erneut versuchen.'));
                }
            });
        }, 250);
    }

    function attach(input) {
        if (instances.has(input)) return instances.get(input);
        const panel = document.createElement('div');
        panel.className = 'event-autocomplete';
        panel.hidden = true;
        const status = document.createElement('div');
        status.className = 'event-autocomplete-status';
        status.id = 'event-autocomplete-status-' + (++nextId);
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        const list = document.createElement('div');
        list.id = 'event-autocomplete-list-' + nextId;
        list.className = 'event-autocomplete-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', translate('Existing events', 'Vorhandene Veranstaltungen'));
        panel.appendChild(status);
        panel.appendChild(list);
        input.parentNode.appendChild(panel);
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-haspopup', 'listbox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', list.id);
        input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), status.id].filter(Boolean).join(' '));
        input.setAttribute('autocomplete', 'off');
        const state = { input, panel, status, list, matches: [], active: -1, timer: null, request: null, sequence: 0, selected: null };
        instances.set(input, state);
        if (input.dataset.eventId) {
            // An activity may have retained an older display title after its event was renamed.
            state.selected = { id: input.dataset.eventId, titles: [input.value, input.dataset.eventTitle, input.dataset.eventTitleFull].filter(Boolean).map(normalize) };
        }
        input.addEventListener('input', function () {
            clearChangedSelection(state);
            queueSearch(state);
        });
        input.addEventListener('focus', () => queueSearch(state));
        input.addEventListener('blur', function () {
            invalidate(state);
            close(state);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (state.panel.hidden && !state.timer && !state.request) return;
                event.preventDefault();
                event.stopPropagation();
                invalidate(state);
                close(state);
                return;
            }
            if (state.panel.hidden || !state.matches.length) return;
            if (event.key === 'Enter' && state.active >= 0) {
                event.preventDefault();
                select(state, state.matches[state.active]);
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                event.stopPropagation();
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                state.active = state.active < 0 ? (direction === 1 ? 0 : state.matches.length - 1)
                    : (state.active + direction + state.matches.length) % state.matches.length;
                Array.from(state.list.children).forEach((option, index) => option.setAttribute('aria-selected', String(index === state.active)));
                const option = state.list.children[state.active];
                input.setAttribute('aria-activedescendant', option.id);
                option.scrollIntoView({ block: 'nearest' });
            }
        });
        if (input.id === 'event-select-search') {
            const dropdown = input.closest('#event-select-dropdown');
            const button = dropdown && dropdown.querySelector('#event-select-button');
            if (button) {
                // OSIRIS opens dropdowns on mousedown; native keyboard clicks need their own path.
                button.addEventListener('keydown', function (event) {
                    if (!['Enter', ' ', 'ArrowDown'].includes(event.key)) return;
                    event.preventDefault();
                    event.stopPropagation();
                    if (window.osirisJS) window.osirisJS.deactivateAllDropdownToggles();
                    dropdown.classList.add('show');
                    button.classList.add('active');
                    button.setAttribute('aria-expanded', 'true');
                    input.focus();
                });
                button.addEventListener('click', function () {
                    button.setAttribute('aria-expanded', String(dropdown.classList.contains('show')));
                    if (dropdown.classList.contains('show')) input.focus();
                });
                new MutationObserver(function () {
                    const open = dropdown.classList.contains('show');
                    button.setAttribute('aria-expanded', String(open));
                    if (!open) { invalidate(state); close(state); }
                }).observe(dropdown, { attributes: true, attributeFilter: ['class'] });
            }
        }
        return state;
    }

    function init(root) {
        root = root || document;
        if (root.matches && root.matches('[data-event-autocomplete]')) attach(root);
        if (root.querySelectorAll) root.querySelectorAll('[data-event-autocomplete]').forEach(attach);
    }

    window.EventAutocomplete = {
        init, normalize, setSelection,
        showMatches: function (input, matches) {
            if (!input) return;
            const state = attach(input);
            input.focus();
            invalidate(state);
            render(state, matches);
        }
    };
    function ready() {
        init(document);
        // Modules are replaced by AJAX when the activity type changes.
        new MutationObserver(function (records) {
            records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) init(node); }));
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})(window, document, window.jQuery);
