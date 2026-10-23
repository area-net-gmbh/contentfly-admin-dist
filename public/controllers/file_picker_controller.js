/*
 * Choosing an image for a form field, without leaving the form (003-004, ADR 0023).
 *
 * THE PLAIN TEXT FIELD STAYS THE FIELD. This controller hides it, puts a preview and two buttons
 * in front of it, and writes every choice back into it — so what the browser submits is always
 * that one input. Without the module the field shows the id and can still be edited by hand:
 * unlovely and working, the same stance `admin/search`, `admin/rte` and `admin/tabs` take.
 *
 * IT FETCHES AND NEVER NAVIGATES, which is the requirement the story is built around: an editor
 * who has to leave a half-filled form to pick an image loses everything else they typed. The
 * overlay asks `urlValue` for a page of files and renders them itself.
 *
 * A NATIVE `<dialog>` AND NOT HAND-BUILT MARKUP. It brings the focus trap, `Esc`, the inert
 * background and the top layer with it; rebuilding those without a bundler (ADR 0007) is exactly
 * where the bugs live that only a real browser shows.
 *
 * ONE ABORT CONTROLLER, NO TIMER. Opening a folder while the previous page is still in flight
 * aborts it, so a slow answer can never overwrite a newer one.
 *
 * A FAILED DERIVATIVE FALLS BACK TO THE ORIGINAL, because whether the configured size exists
 * cannot be found out on the server (Schema-Vertrag L25). Every entry carries both addresses and
 * the swap happens on the image's own `error` — the same answer `admin/file-preview` gives in the
 * file list.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'note'];

    static values = { url: String, field: String, alias: String };

    connect() {
        this.pending = null;

        this.preview = document.createElement('div');
        this.preview.className = 'admin-form__file-preview';

        this.choose = document.createElement('button');
        this.choose.type = 'button';
        this.choose.className = 'admin-form__file-button';
        this.choose.textContent = 'Bild wählen';
        this.choose.addEventListener('click', () => this.open());

        this.clear = document.createElement('button');
        this.clear.type = 'button';
        this.clear.className = 'admin-form__file-button';
        this.clear.textContent = 'Entfernen';
        this.clear.addEventListener('click', () => this.pick(''));

        const label = document.querySelector(`label[for="${this.fieldValue}"]`);
        const name = label === null ? '' : label.textContent.replace('*', '').trim();

        if (name !== '') {
            this.choose.setAttribute('aria-label', `${name} — Bild wählen`);
            this.clear.setAttribute('aria-label', `${name} — Bild entfernen`);
        }

        this.actions = document.createElement('div');
        this.actions.className = 'admin-form__file-actions';
        this.actions.append(this.choose, this.clear);

        this.element.insertBefore(this.preview, this.noteTarget);
        this.element.insertBefore(this.actions, this.noteTarget);

        this.sourceTarget.hidden = true;
        this.noteTarget.hidden = true;

        this.render();
    }

    disconnect() {
        this.abort();

        if (this.dialog !== undefined) {
            this.dialog.remove();
            this.dialog = undefined;
        }

        /*
         * THE FIELD IS HANDED BACK AS IT WAS FOUND. Turbo keeps the page in its cache, and a
         * controller that left the input hidden would leave a field nobody can edit behind.
         */
        this.sourceTarget.hidden = false;
        this.noteTarget.hidden = false;
        this.preview.remove();
        this.actions.remove();
    }

    abort() {
        if (this.pending !== null) {
            this.pending.abort();
            this.pending = null;
        }
    }

    get id() {
        return this.sourceTarget.value.trim();
    }

    /** Writes the choice into the field — the one place that does. */
    pick(id) {
        this.sourceTarget.value = id;
        this.sourceTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.render();
        this.close();
    }

    render() {
        this.preview.replaceChildren();
        this.clear.hidden = this.id === '';

        if (this.id === '') {
            const empty = document.createElement('p');
            empty.className = 'admin-form__file-empty';
            empty.textContent = 'Kein Bild gewählt';
            this.preview.append(empty);
            return;
        }

        /*
         * THE PREVIEW OF THE CHOSEN FILE IS BUILT FROM THE ID ALONE, because the field may hold
         * an id this module never saw — one typed in by hand, or one that came with the record.
         * The entry's own addresses are only known while the overlay is open, so the chosen
         * preview asks the same core route the listing asks, and the name segment is cosmetic
         * (Schema-Vertrag 8.1).
         *
         * THE SMALL VERSION FIRST, THE STORED FILE SECOND, THE BARE ID LAST. Whether the
         * configured size exists cannot be answered here or on the server (Schema-Vertrag L25),
         * and a file that is not an image has no small version at all — so each step is tried
         * and the next one answers its failure. Starting with the stored file instead would pull
         * a full-resolution photo into a form for a thumbnail-sized box.
         */
        const steps = [];

        if (this.aliasValue !== '') {
            steps.push(`/file/get/${encodeURIComponent(this.id)}/s-${encodeURIComponent(this.aliasValue)}/datei`);
        }

        steps.push(`/file/get/${encodeURIComponent(this.id)}/datei`);

        const img = document.createElement('img');
        img.className = 'admin-form__file-image';
        img.alt = '';
        img.loading = 'lazy';
        img.addEventListener('error', () => {
            const next = steps.shift();

            if (next !== undefined) {
                img.src = next;
                return;
            }

            img.remove();
            const fallback = document.createElement('p');
            fallback.className = 'admin-form__file-empty';
            fallback.textContent = this.id;
            this.preview.append(fallback);
        });

        img.src = steps.shift();

        this.preview.append(img);
    }

    open() {
        if (this.dialog === undefined) {
            this.build();
        }

        this.dialog.showModal();
        this.load(null);
    }

    close() {
        this.abort();

        if (this.dialog !== undefined && this.dialog.open) {
            this.dialog.close();
        }
    }

    build() {
        this.dialog = document.createElement('dialog');
        this.dialog.className = 'admin-picker';

        this.crumbs = document.createElement('nav');
        this.crumbs.className = 'admin-picker__crumbs';

        this.grid = document.createElement('ul');
        this.grid.className = 'admin-picker__grid';

        this.status = document.createElement('p');
        this.status.className = 'admin-picker__status';
        this.status.setAttribute('role', 'status');

        const done = document.createElement('button');
        done.type = 'button';
        done.className = 'admin-picker__close';
        done.textContent = 'Abbrechen';
        done.addEventListener('click', () => this.close());

        const head = document.createElement('div');
        head.className = 'admin-picker__head';
        const title = document.createElement('h2');
        title.className = 'admin-picker__title';
        title.textContent = 'Bild wählen';
        head.append(title, done);

        this.dialog.append(head, this.crumbs, this.status, this.grid);

        /* Closing with Esc or the backdrop must drop an in-flight request too. */
        this.dialog.addEventListener('close', () => this.abort());

        this.element.append(this.dialog);
    }

    async load(folder) {
        this.abort();
        this.pending = new AbortController();
        this.status.textContent = 'Wird geladen …';
        this.grid.replaceChildren();

        const url = folder === null || folder === ''
            ? this.urlValue
            : `${this.urlValue}?folder=${encodeURIComponent(folder)}`;

        try {
            const response = await fetch(url, {
                signal: this.pending.signal,
                headers: { Accept: 'application/json' },
            });

            /*
             * A NON-JSON ANSWER IS SAID OUT LOUD, NOT PARSED. An expired session answers this
             * route with a redirect to the login screen, so "not ok" and "not JSON" are both
             * ordinary here and neither may end as an unexplained empty overlay.
             *
             * THE RESPONSE IS NOT CALLED `answer`, because `show()` calls the parsed body that —
             * one word for the envelope and for its content is how a `.ok` check ends up on a
             * plain object that never had one.
             */
            if (!response.ok) {
                this.status.textContent = 'Die Dateien konnten nicht geladen werden.';
                return;
            }

            this.show(await response.json());
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.status.textContent = 'Die Dateien konnten nicht geladen werden.';
            }
        } finally {
            this.pending = null;
        }
    }

    show(answer) {
        this.crumbs.replaceChildren();
        this.grid.replaceChildren();

        const step = (id, text) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-picker__crumb';
            button.textContent = text;
            button.addEventListener('click', () => this.load(id));
            return button;
        };

        if (answer.mayReadFolders) {
            this.crumbs.append(step(null, 'Alle Dateien'));

            for (const node of answer.trail ?? []) {
                this.crumbs.append(step(node.id, node.name === '' ? '—' : node.name));
            }

            for (const node of answer.folders ?? []) {
                const item = document.createElement('li');
                item.className = 'admin-picker__item';
                const button = step(node.id, node.name === '' ? '—' : node.name);
                button.className = 'admin-picker__folder';
                item.append(button);
                this.grid.append(item);
            }
        }

        for (const file of answer.files ?? []) {
            this.grid.append(this.entry(file));
        }

        const count = (answer.files ?? []).length;

        if (count === 0) {
            this.status.textContent = 'In diesem Ordner liegt keine Datei.';
            return;
        }

        this.status.textContent = answer.pages > 1
            ? `Seite ${answer.page} von ${answer.pages} — ${answer.total} Dateien`
            : `${count} ${count === 1 ? 'Datei' : 'Dateien'}`;
    }

    entry(file) {
        const item = document.createElement('li');
        item.className = 'admin-picker__item';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-picker__file';
        button.addEventListener('click', () => this.pick(file.id));

        if (file.preview === null) {
            const badge = document.createElement('span');
            badge.className = 'admin-picker__badge';
            badge.textContent = file.badge;
            button.append(badge);
        } else {
            const img = document.createElement('img');
            img.className = 'admin-picker__image';
            img.alt = '';
            img.loading = 'lazy';
            img.src = file.preview;
            img.addEventListener('error', () => {
                if (file.original !== null && img.src !== file.original) {
                    img.src = file.original;
                }
            }, { once: true });
            button.append(img);
        }

        const name = document.createElement('span');
        name.className = 'admin-picker__name';
        name.textContent = file.name === '' ? file.id : file.name;
        button.append(name);

        item.append(button);

        return item;
    }
}
