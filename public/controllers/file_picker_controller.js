/*
 * Choosing images for a form field, without leaving the form (003-004, ADR 0023).
 *
 * ONE CONTROLLER FOR BOTH IMAGE FIELDS. `multipleValue` decides whether the field holds one id
 * or a comma-separated list; everything else — the overlay, the folder walk, the preview chain —
 * is the same act. Two modules would be two places to keep in step, and the difference is three
 * branches.
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

    static values = { url: String, field: String, alias: String, multiple: Boolean, sortable: Boolean };

    connect() {
        this.pending = null;
        this.rendered = [];

        this.preview = document.createElement('div');
        this.preview.className = 'admin-form__file-preview';

        this.choose = document.createElement('button');
        this.choose.type = 'button';
        this.choose.className = 'admin-form__file-button';
        this.choose.textContent = this.multipleValue ? 'Bilder wählen' : 'Bild wählen';
        this.choose.addEventListener('click', () => this.open());

        this.clear = document.createElement('button');
        this.clear.type = 'button';
        this.clear.className = 'admin-form__file-button';
        this.clear.textContent = this.multipleValue ? 'Alle entfernen' : 'Entfernen';
        this.clear.addEventListener('click', () => {
            this.ids = [];
        });

        const label = document.querySelector(`label[for="${this.fieldValue}"]`);
        this.name = label === null ? '' : label.textContent.replace('*', '').trim();

        if (this.name !== '') {
            this.choose.setAttribute('aria-label', `${this.name} — ${this.choose.textContent}`);
            this.clear.setAttribute('aria-label', `${this.name} — ${this.clear.textContent}`);
        }

        this.actions = document.createElement('div');
        this.actions.className = 'admin-form__file-actions';
        this.actions.append(this.choose, this.clear);

        this.element.insertBefore(this.preview, this.noteTarget);
        this.element.insertBefore(this.actions, this.noteTarget);

        this.sourceTarget.hidden = true;
        this.noteTarget.hidden = true;

        /*
         * THE FIELD IS THE ONE TRUTH, SO THE LIST FOLLOWS IT — including when somebody else
         * writes it. `admin/sortable` does exactly that after a move.
         *
         * A PURE REORDER IS NOT REDRAWN, and that is not an optimisation. The sorter has already
         * moved the entry in the DOM, so a rebuild would only replace the element the keyboard
         * focus is sitting on and drop the focus with it — the next arrow key would then do
         * nothing. Only a changed *set* of ids needs a new list.
         */
        this.onSourceChange = () => {
            const now = this.ids;
            const same = now.length === this.rendered.length
                && now.every((id) => this.rendered.includes(id));

            if (!same) {
                this.render();
            }
        };

        this.sourceTarget.addEventListener('change', this.onSourceChange);

        this.render();
    }

    disconnect() {
        this.abort();
        this.sourceTarget.removeEventListener('change', this.onSourceChange);

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

    get ids() {
        return this.sourceTarget.value
            .split(',')
            .map((id) => id.trim())
            .filter((id) => id !== '');
    }

    /** Writes the choice into the field — the one place that does. */
    set ids(values) {
        /* A repeated id would be one entry the core keeps and one the editor cannot remove. */
        this.sourceTarget.value = [...new Set(values)].join(',');

        /* The redraw happens in the `change` handler, so there is one path and not two. */
        this.sourceTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * One entry was chosen in the overlay.
     *
     * A SINGLE FIELD CLOSES, A LIST STAYS OPEN. Choosing is finished for the one-image field the
     * moment it happened; for a list the overlay is the place the editor assembles several, so
     * closing after the first would make every further image another round trip. Already chosen
     * entries toggle off, which is the only way to correct a misclick without leaving.
     */
    pick(id) {
        if (!this.multipleValue) {
            this.ids = [id];
            this.close();
            return;
        }

        this.ids = this.ids.includes(id)
            ? this.ids.filter((chosen) => chosen !== id)
            : [...this.ids, id];

        this.mark();
    }

    render() {
        const chosen = this.ids;

        this.rendered = chosen;
        this.preview.replaceChildren();
        this.clear.hidden = chosen.length === 0;

        if (chosen.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'admin-form__file-empty';
            empty.textContent = this.multipleValue ? 'Keine Bilder gewählt' : 'Kein Bild gewählt';
            this.preview.append(empty);
            this.mark();
            return;
        }

        if (!this.multipleValue) {
            this.preview.append(this.thumb(chosen[0]));
            this.mark();
            return;
        }

        /*
         * THE CHOSEN LIST IS A LIST, NOT A ROW OF IMAGES. Each entry needs its own remove
         * button, and the order shown is the order the field holds — which is the order the core
         * will store for a sortable field (`MultifileType::toDatabase()` counts `sorting` up as
         * it walks the posted ids).
         */
        const list = document.createElement('ul');
        list.className = 'admin-form__file-list';

        /*
         * THE SORTER IS A CONTROLLER OF ITS OWN, PUT ON THIS LIST — not code in here. It writes
         * the order into the same field this controller owns, and it knows nothing about images,
         * so the tree of epic 004 can use it on a list of its own (ADR 0023). Stimulus connects
         * it although this element was built just now.
         *
         * ONLY WHERE AN ORDER CAN BE STORED. `sortableValue` comes from the field's schema
         * (gap L12): in the ordinary `ManyToMany` form there is no column for a sequence, and a
         * handle would promise one that is gone after the save.
         */
        if (this.sortableValue) {
            list.dataset.controller = 'admin-sortable';
            list.dataset.adminSortableFieldValue = this.fieldValue;
        }

        for (const id of chosen) {
            const item = document.createElement('li');
            item.className = 'admin-form__file-item';

            if (this.sortableValue) {
                item.dataset.sortableId = id;
                item.dataset.sortableLabel = this.name === '' ? 'Bild' : `Bild ${id}`;
            }

            item.append(this.thumb(id));

            const drop = document.createElement('button');
            drop.type = 'button';
            drop.className = 'admin-form__file-drop';
            drop.textContent = '×';
            drop.setAttribute('aria-label', `${this.name === '' ? 'Bild' : this.name} — ${id} entfernen`);
            drop.addEventListener('click', () => {
                this.ids = this.ids.filter((kept) => kept !== id);
            });

            item.append(drop);
            list.append(item);
        }

        this.preview.append(list);

        /*
         * THE LIMIT IS SAID, NOT LEFT TO BE GUESSED (ADR 0023). Where no order can be stored
         * there is no handle — and a list of images without a handle looks exactly like one
         * whose handle failed to appear. So the field says why.
         *
         * FROM TWO ENTRIES ON, because that is when a sequence begins to mean anything; telling
         * somebody that one image cannot be reordered would be noise.
         */
        if (!this.sortableValue && chosen.length > 1) {
            const limit = document.createElement('p');
            limit.className = 'admin-form__hint';
            limit.textContent = 'Die Reihenfolge dieser Bilder lässt sich nicht festlegen — '
                + 'für diese Art der Verknüpfung speichert das Grundsystem keine.';
            this.preview.append(limit);
        }

        this.mark();
    }

    /**
     * The thumbnail of one chosen id.
     *
     * IT IS BUILT FROM THE ID ALONE, because the field may hold an id this module never saw —
     * one typed in by hand, or one that came with the record. The entry's own addresses are only
     * known while the overlay is open, so this asks the same core route the listing asks, and
     * the name segment is cosmetic (Schema-Vertrag 8.1).
     *
     * THE SMALL VERSION FIRST, THE STORED FILE SECOND, THE BARE ID LAST. Whether the configured
     * size exists cannot be answered here or on the server (Schema-Vertrag L25), and a file that
     * is not an image has no small version at all — so each step is tried and the next one
     * answers its failure. Starting with the stored file instead would pull a full-resolution
     * photo into a thumbnail-sized box.
     */
    thumb(id) {
        const steps = [];

        if (this.aliasValue !== '') {
            steps.push(`/file/get/${encodeURIComponent(id)}/s-${encodeURIComponent(this.aliasValue)}/datei`);
        }

        steps.push(`/file/get/${encodeURIComponent(id)}/datei`);

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

            const fallback = document.createElement('span');
            fallback.className = 'admin-form__file-empty';
            fallback.textContent = id;
            img.replaceWith(fallback);
        });

        img.src = steps.shift();

        return img;
    }

    /** Marks the entries of an open overlay that are already chosen. */
    mark() {
        if (this.grid === undefined) {
            return;
        }

        const chosen = this.ids;

        for (const button of this.grid.querySelectorAll('.admin-picker__file')) {
            const picked = chosen.includes(button.dataset.id);
            button.classList.toggle('admin-picker__file--chosen', picked);
            button.setAttribute('aria-pressed', picked ? 'true' : 'false');
        }
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
        done.textContent = this.multipleValue ? 'Fertig' : 'Abbrechen';
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
            this.mark();
            return;
        }

        this.status.textContent = answer.pages > 1
            ? `Seite ${answer.page} von ${answer.pages} — ${answer.total} Dateien`
            : `${count} ${count === 1 ? 'Datei' : 'Dateien'}`;

        this.mark();
    }

    entry(file) {
        const item = document.createElement('li');
        item.className = 'admin-picker__item';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-picker__file';
        /* The id lives on the element so `mark()` can find it again without a second list. */
        button.dataset.id = file.id;
        button.addEventListener('click', () => this.pick(file.id));

        if (this.multipleValue) {
            /* A list toggles, so the entry is a switch and says so. */
            button.setAttribute('aria-pressed', 'false');
        }

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
