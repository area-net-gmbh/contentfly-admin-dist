/*
 * A relation with too many target records to list, searched instead (002-007-0007).
 *
 * THE PLAIN TEXT FIELD STAYS THE FIELD. This controller hides it, puts a search box and a result
 * list in front of it, and writes every choice back into it — so what the browser submits is
 * always that one input. Without the module the field shows the ids and can still be edited by
 * hand: unlovely and working, which is the same stance `admin/rte` and `admin/tabs` take.
 *
 * THE SERVER DECIDES WHAT MAY BE SEARCHED. The endpoint is `<entity>/<property>`; the target
 * entity, the option group and the label property are all derived there from the schema. This
 * controller cannot widen any of them, and a chosen id is checked again on save, because the
 * form only writes properties it offers.
 *
 * NO DEBOUNCE TIMER, AN ABORT INSTEAD. A timer guesses how fast somebody types; aborting the
 * previous request when the next keystroke arrives is exact, cannot show the results of an
 * outdated term, and needs no number nobody can justify.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'note'];

    static values = { url: String, multiple: Boolean, field: String };

    connect() {
        this.pending = null;
        this.labels = new Map();

        this.box = document.createElement('input');
        this.box.type = 'search';
        this.box.className = 'admin-form__control';
        this.box.autocomplete = 'off';
        this.box.placeholder = 'Suchen …';

        const label = document.querySelector(`label[for="${this.fieldValue}"]`);

        if (label !== null) {
            this.box.setAttribute('aria-label', `${label.textContent.trim()} — suchen`);
        }

        this.results = document.createElement('ul');
        this.results.className = 'admin-form__search-results';

        this.chosen = document.createElement('ul');
        this.chosen.className = 'admin-form__search-chosen';

        this.element.insertBefore(this.chosen, this.noteTarget);
        this.element.insertBefore(this.box, this.noteTarget);
        this.element.insertBefore(this.results, this.noteTarget);

        this.sourceTarget.hidden = true;
        this.box.addEventListener('input', () => this.search());

        this.render();
    }

    disconnect() {
        if (this.pending !== null) {
            this.pending.abort();
        }

        this.sourceTarget.hidden = false;
    }

    get ids() {
        return this.sourceTarget.value
            .split(',')
            .map((id) => id.trim())
            .filter((id) => id !== '');
    }

    set ids(values) {
        this.sourceTarget.value = values.join(',');
        this.render();
    }

    async search() {
        const term = this.box.value.trim();

        if (this.pending !== null) {
            this.pending.abort();
            this.pending = null;
        }

        if (term === '') {
            this.results.replaceChildren();
            return;
        }

        this.pending = new AbortController();

        /*
         * THE TERM IS ADDED TO THE ADDRESS, NOT GLUED ONTO IT. This used to be
         * `${this.urlValue}?q=…`, which assumed the address carries no query of its own — and
         * story 004-001-0004 gave it one: the parent search of a tree node passes `self`, the
         * node whose own subtree must not be offered. With the literal `?` that became
         * `…?self=X?q=term`, where `q` is gone and `self` is nonsense. `URL` appends instead of
         * assuming, so any parameter the server put there survives.
         */
        const address = new URL(this.urlValue, document.baseURI);

        address.searchParams.set('q', term);

        try {
            const response = await fetch(address, {
                signal: this.pending.signal,
                headers: { Accept: 'application/json' },
            });

            /*
             * A REFUSAL IS NOT AN OUTAGE. The route answers 403 with an empty body when the
             * editor may not read the entity the relation points at (ADR 0019 §6). Reporting
             * that as "not reachable" would send someone looking for a broken server, and the
             * box would keep inviting a term that can never match anything. This is the one
             * status that means "there is nothing here you may choose".
             */
            if (response.status === 403) {
                this.results.replaceChildren(this.message('Dafür fehlt dir die Berechtigung.'));
                return;
            }

            if (!response.ok) {
                this.results.replaceChildren(this.message('Die Suche ist gerade nicht erreichbar.'));
                return;
            }

            this.offer(await response.json());
        } catch (error) {
            /*
             * AN ABORT IS NOT A FAILURE. It is this controller cancelling its own request one
             * keystroke later, and reporting it would put an error under a box that is working.
             */
            if (error.name !== 'AbortError') {
                this.results.replaceChildren(this.message('Die Suche ist gerade nicht erreichbar.'));
            }
        }
    }

    offer(options) {
        if (options.length === 0) {
            this.results.replaceChildren(this.message('Keine Treffer.'));
            return;
        }

        this.results.replaceChildren(
            ...options.map((option) => {
                this.labels.set(option.value, option.label);

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'admin-form__search-hit';
                button.textContent = option.label;
                button.addEventListener('click', () => this.choose(option.value));

                const item = document.createElement('li');
                item.append(button);

                return item;
            }),
        );
    }

    choose(id) {
        const current = this.ids;

        if (!this.multipleValue) {
            this.ids = [id];
        } else if (!current.includes(id)) {
            this.ids = [...current, id];
        }

        this.box.value = '';
        this.results.replaceChildren();
        this.box.focus();
    }

    drop(id) {
        this.ids = this.ids.filter((value) => value !== id);
    }

    /*
     * THE CHOICES ARE DRAWN FROM THE FIELD, never kept alongside it. The input is the single
     * source of truth, so a value written by anything else — the no-JavaScript fallback, a
     * rejected form coming back from the server — shows up here without a second code path.
     */
    render() {
        this.chosen.replaceChildren(
            ...this.ids.map((id) => {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'admin-form__search-drop';
                remove.textContent = '×';
                remove.setAttribute('aria-label', `${this.labels.get(id) ?? id} entfernen`);
                remove.addEventListener('click', () => this.drop(id));

                const item = document.createElement('li');
                item.className = 'admin-form__search-chip';
                item.append(document.createTextNode(this.labels.get(id) ?? id), remove);

                return item;
            }),
        );
    }

    message(text) {
        const item = document.createElement('li');
        item.className = 'admin-form__search-note';
        item.textContent = text;

        return item;
    }
}
