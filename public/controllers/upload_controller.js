/*
 * Several files at once, with a progress bar per file (003-002-0004).
 *
 * WHY THIS IS HAND-WRITTEN AT ALL. Symfony UX Live Components are not available without the
 * FrameworkBundle, and this package is a plain API consumer — the epic's risk table names the
 * progress display as one of the three items not to underestimate for exactly that reason.
 *
 * ONE FILE PER REQUEST, AND THEY GO ONE AFTER THE OTHER (ADR 0021). One request per file is what
 * makes a per-file bar possible at all: `upload.onprogress` only ever knows the total of the
 * request it belongs to. It is also what keeps a size problem from being reported as an expired
 * form — a batch POST over `post_max_size` arrives with an empty `$_POST`, token included.
 * Sequentially rather than in parallel because the core builds thumbnails *inside* the upload
 * request: n parallel uploads are n synchronous image conversions competing for the same server.
 *
 * THE REQUEST IS BUILT FROM THE FORM, not from field names repeated here. `new FormData(form)`
 * already carries the token, the action and the folder, so there is one place where those names
 * live and this module cannot drift from the template. Only `_format` is added, because that is
 * the one field that must *not* be in the markup: without it the route answers the 303 the
 * no-JavaScript path needs.
 *
 * A FAILURE STAYS WITH ITS FILE. The queue does not stop; the row says what happened and the next
 * file starts. The sentence comes from the server, which takes it from the same table the page
 * itself uses — so a refusal reads identically whether or not this module is loaded.
 *
 * WITHOUT THIS MODULE THE PAGE STILL WORKS, the stance every controller in this package takes.
 * The zone is an ordinary multipart form; this module upgrades it to many files with progress, it
 * is not what makes uploading work.
 */

import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

export default class extends Controller {
    static targets = ['input', 'button', 'queue', 'note'];

    static values = { formatField: String, format: String };

    connect() {
        this.queue = [];
        this.running = false;
        this.request = null;
        this.failed = 0;
        this.succeeded = 0;

        /*
         * THE BUTTON GOES AWAY WHILE THIS MODULE IS LOADED. Files start uploading the moment they
         * are dropped or picked, so a "upload" button would either do nothing or offer a second,
         * duplicate send. Without the module it is the only way to submit, which is why it lives
         * in the markup and is hidden here rather than the other way round.
         */
        if (this.hasButtonTarget) {
            this.buttonTarget.hidden = true;
        }

        this.onDragOver = (event) => {
            event.preventDefault();
            this.element.classList.add('admin-files__upload--over');
        };
        this.onDragLeave = (event) => {
            if (event.target === this.element || !this.element.contains(event.relatedTarget)) {
                this.element.classList.remove('admin-files__upload--over');
            }
        };
        this.onDrop = (event) => {
            event.preventDefault();
            this.element.classList.remove('admin-files__upload--over');
            this.add(event.dataTransfer ? event.dataTransfer.files : null);
        };
        this.onChange = () => {
            this.add(this.inputTarget.files);
            /*
             * THE FIELD IS EMPTIED AFTER READING IT, so the same selection cannot be sent twice —
             * once by this module and once by a later submit of the form.
             */
            this.inputTarget.value = '';
        };
        this.onSubmit = (event) => {
            event.preventDefault();

            if (this.hasInputTarget && this.inputTarget.files && this.inputTarget.files.length > 0) {
                this.add(this.inputTarget.files);
                this.inputTarget.value = '';
            }
        };

        this.element.addEventListener('dragover', this.onDragOver);
        this.element.addEventListener('dragleave', this.onDragLeave);
        this.element.addEventListener('drop', this.onDrop);
        this.element.addEventListener('submit', this.onSubmit);

        if (this.hasInputTarget) {
            this.inputTarget.addEventListener('change', this.onChange);
        }
    }

    disconnect() {
        this.element.removeEventListener('dragover', this.onDragOver);
        this.element.removeEventListener('dragleave', this.onDragLeave);
        this.element.removeEventListener('drop', this.onDrop);
        this.element.removeEventListener('submit', this.onSubmit);

        if (this.hasInputTarget) {
            this.inputTarget.removeEventListener('change', this.onChange);
        }

        /*
         * AN UPLOAD IN FLIGHT IS ABANDONED ON NAVIGATION. Turbo replaces the body, so the rows
         * this controller writes into are gone; letting the request finish would have it write
         * into detached nodes, and the file would land without anybody being told.
         */
        if (this.request) {
            this.request.abort();
            this.request = null;
        }

        this.queue = [];
        this.running = false;
    }

    /** Puts a `FileList` into the queue and makes sure it is being worked on. */
    add(files) {
        if (!files || files.length === 0) {
            return;
        }

        for (const file of Array.from(files)) {
            const row = this.row(file);

            if (!this.accepts(file)) {
                /*
                 * REFUSED WITHOUT A REQUEST — and this is comfort, never a guarantee (ADR 0021).
                 * What is really allowed lives in the core's configuration, which this package
                 * cannot read; `accept` is only what the project said it would accept.
                 */
                this.finish(row, false, 'Diese Datei hat einen Typ, der hier nicht vorgesehen ist — sie wurde nicht übertragen.');
                this.failed += 1;

                continue;
            }

            this.queue.push({ file, row });
        }

        this.next();
    }

    /** Starts the next file, unless one is already on its way. */
    next() {
        if (this.running) {
            return;
        }

        const entry = this.queue.shift();

        if (!entry) {
            this.done();

            return;
        }

        this.running = true;
        this.send(entry);
    }

    send(entry) {
        const data = new FormData(this.element);

        /*
         * THE FILE FIELD IS REPLACED, NOT ADDED TO. `FormData(form)` copies whatever the input
         * currently holds — which is nothing by the time this runs, but a stale entry here would
         * silently send the wrong file, so the field is cleared before the one file is set.
         */
        data.delete(this.inputTarget.name);
        data.set(this.inputTarget.name, entry.file, entry.file.name);
        data.set(this.formatFieldValue, this.formatValue);

        const request = new XMLHttpRequest();
        this.request = request;

        request.open('POST', this.endpoint(), true);

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                entry.row.bar.max = event.total;
                entry.row.bar.value = event.loaded;
            }
        });

        request.addEventListener('load', () => {
            this.request = null;
            this.running = false;

            let answer = null;

            try {
                answer = JSON.parse(request.responseText);
            } catch (error) {
                answer = null;
            }

            if (answer && answer.ok) {
                this.succeeded += 1;
                this.finish(entry.row, true, answer.text || 'Die Datei ist hochgeladen.');
            } else {
                this.failed += 1;
                /*
                 * THE SERVER'S SENTENCE WINS. It is produced by the same table the page uses for
                 * the redirect path, so the wording cannot drift; only when there is no readable
                 * answer at all does this module write one of its own.
                 */
                this.finish(entry.row, false, (answer && answer.text)
                    || 'Die Datei konnte nicht übertragen werden.');
            }

            this.next();
        });

        request.addEventListener('error', () => {
            this.request = null;
            this.running = false;
            this.failed += 1;
            this.finish(entry.row, false, 'Die Verbindung ist abgebrochen — die Datei wurde nicht übertragen.');
            this.next();
        });

        request.addEventListener('abort', () => {
            this.request = null;
            this.running = false;
        });

        request.send(data);
    }

    /** One row in the queue: the file's name, its bar and the sentence it ends with. */
    row(file) {
        const item = document.createElement('li');
        item.className = 'admin-files__upload-item';

        const name = document.createElement('span');
        name.className = 'admin-files__upload-name';
        name.textContent = file.name;

        const bar = document.createElement('progress');
        bar.className = 'admin-files__upload-bar';
        bar.max = 1;
        bar.value = 0;

        const text = document.createElement('span');
        text.className = 'admin-files__upload-state';
        text.textContent = 'wartet';

        item.append(name, bar, text);

        this.queueTarget.hidden = false;
        this.queueTarget.append(item);

        return { item, bar, text };
    }

    finish(row, ok, text) {
        row.bar.remove();
        row.text.textContent = text;
        row.item.classList.add(ok
            ? 'admin-files__upload-item--ok'
            : 'admin-files__upload-item--problem');
    }

    /**
     * After the last file.
     *
     * EVERYTHING WENT THROUGH → the page is reloaded with the same report the redirect path
     * produces, so the editor sees the new files and one familiar sentence.
     *
     * SOMETHING FAILED → nothing is navigated. The rows carry the only account of what went
     * wrong, and a reload would throw it away; instead the zone says plainly that the list below
     * is older than the upload and offers the way to refresh it.
     */
    done() {
        if (this.succeeded === 0 && this.failed === 0) {
            return;
        }

        if (this.failed === 0) {
            const target = new URL(this.endpoint(), window.location.href);
            const folder = new FormData(this.element).get('folder');

            if (folder) {
                target.searchParams.set('folder', folder);
            }

            target.searchParams.set('done', 'upload');

            this.succeeded = 0;
            Turbo.visit(target.toString(), { action: 'replace' });

            return;
        }

        if (this.hasNoteTarget) {
            this.noteTarget.hidden = false;
        }

        this.succeeded = 0;
        this.failed = 0;
    }

    /**
     * Where the upload goes.
     *
     * `this.element.action` IS NOT USABLE HERE, and the reason is a trap worth naming: the form
     * carries a field named `action` — the write action the route dispatches on — and a form's
     * named controls shadow the form's own properties. `form.action` therefore hands back that
     * input element rather than the URL, and stringified into a request it becomes
     * `[object HTMLInputElement]`. Measured in a browser: the upload then posts to
     * `/admin/files/[object%20HTMLInputElement]`, which no route answers. Reading the attribute
     * is not a style preference, it is the only correct access as long as the field is named
     * `action`.
     */
    endpoint() {
        return this.element.getAttribute('action') || window.location.href;
    }

    /**
     * Whether the file matches the `accept` the project configured.
     *
     * THE ATTRIBUTE IS THE SINGLE SOURCE. It is rendered from `uploadAccept` and is absent when
     * the option is empty — which is the default and means "do not pre-check at all", not
     * "accept nothing".
     */
    accepts(file) {
        const accept = this.hasInputTarget
            ? (this.inputTarget.getAttribute('accept') || '').trim()
            : '';

        if (accept === '') {
            return true;
        }

        const name = file.name.toLowerCase();
        const type = (file.type || '').toLowerCase();

        return accept
            .split(',')
            .map((rule) => rule.trim().toLowerCase())
            .filter((rule) => rule !== '')
            .some((rule) => {
                if (rule.startsWith('.')) {
                    return name.endsWith(rule);
                }

                if (rule.endsWith('/*')) {
                    return type.startsWith(rule.slice(0, -1));
                }

                return type === rule;
            });
    }
}
