/*
 * The tree's branches, folded up and down without asking the server (004-001-0003).
 *
 * WHY THIS IS JAVASCRIPT AT ALL. Everything else that is navigation in this admin is a link —
 * sorting, paging, opening a record — because that state belongs in the URL and a round trip
 * costs nothing. Folding a branch is the opposite case: the whole tree arrives in one query
 * (`Api::getTree2()`), so every node the editor could want is already on the page, and a round
 * trip to hide one would be a request that fetches nothing new.
 *
 * IT HIDES, IT DOES NOT REVEAL — the rule `tabs_controller.js` states and the reason it matters
 * more here. Every branch is expanded in the delivered HTML. A tree whose branches were hidden
 * in CSS and opened by script would, if this module failed to load, show its roots and no sign
 * that anything hangs below them: the editor would conclude the records are gone. This way a
 * failure costs the folding and nothing else.
 *
 * THE FOLD SURVIVES A RELOAD, AND THAT IS WHY THIS FILE TOUCHES `sessionStorage` — the first
 * place in the package that touches browser storage at all. The reason is story 004-001-0005:
 * deleting a node re-renders this page, and in a tree of any size an editor who loses every fold
 * loses their place. What is stored is one list of node ids per entity, per tab — no record
 * data, nothing another viewer or the server ever reads. It is a convenience, so every access is
 * wrapped: a private window, blocked site data or a quota error must cost the memory and never
 * the page.
 *
 * `disconnect()` PUTS THE TREE BACK the way it was delivered. Turbo caches a page before it
 * navigates away and may show that copy again; a cached snapshot with branches hidden by this
 * controller, restored while the module is not connected, would be a tree the user cannot
 * unfold. Since 004-002-0004 it also takes the drag handles out again, for the same reason one
 * step further: a handle in a cached snapshot, restored without the module, is a grab affordance
 * that does nothing.
 *
 * DRAGGING (004-002-0004) IS MOUSE-ONLY, AND THAT IS A DECISION, NOT AN OMISSION (ADR 0026 §6).
 * The handle is therefore `aria-hidden` and not focusable: a focusable control that answers no
 * key would announce an operation it does not have — the same mistake 004-001-0003 avoided by
 * refusing `role="tree"`. The path without a mouse is the `treeParent` field in the form
 * (ADR 0025 §3), which covers reparenting; reordering inside one level has no keyboard path and
 * the ADR says so out loud.
 *
 * THE HANDLE IS INJECTED HERE, NOT RENDERED BY THE SERVER, exactly as `sortable_controller.js`
 * does it — a handle in the delivered markup would be visible and dead without this module.
 *
 * THE SERVER'S ORDER WINS AFTER A WRITE. This module does not keep the arrangement it dropped:
 * the answer carries the level's order as the server re-read it, and that is what gets applied
 * (ADR 0026 §4). On a refusal the tree is put back the way it looked before the drag, because a
 * display that shows what was *meant* rather than what is *stored* is the one thing this story
 * set out to avoid. The snapshot is taken on `dragstart`, so a refusal costs nothing.
 *
 * `sortable_controller.js` IS NOT REUSED, and ADR 0026 §1 holds the measurement: its item
 * selector reaches every descendant, so one instance would flatten a nested tree into a single
 * order, and its persistence is a hidden field riding along on a form POST — the tree has no
 * form. Neither is configurable away.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'childs'];

    static values = { entity: String, sortable: Boolean, token: String, tokenField: String };

    connect() {
        this.folded = this.read();
        this.childsTargets.forEach((childs) => {
            this.apply(childs, this.folded.includes(childs.dataset.adminTreeNode));
        });

        this.dragging = null;
        this.snapshot = null;

        if (this.sortableValue) {
            this.addHandles();
        }
    }

    disconnect() {
        this.childsTargets.forEach((childs) => this.apply(childs, false));
        this.clearDropHints();
        this.element
            .querySelectorAll('.admin-tree__handle')
            .forEach((handle) => handle.remove());
    }

    toggle(event) {
        const button = event.currentTarget;
        const childs = this.childsFor(button);

        if (childs === null) {
            return;
        }

        const fold = !childs.hidden;

        this.apply(childs, fold);

        const id = childs.dataset.adminTreeNode;
        this.folded = this.folded.filter((node) => node !== id);

        if (fold) {
            this.folded.push(id);
        }

        this.write();
    }

    /*
     * The branch a button controls is named by `aria-controls` and found by that id — not by
     * walking the DOM from the button. The markup is nested, so "the next sibling" and "the
     * parent's last child" are both true often enough to look correct and wrong on the node
     * where it matters.
     */
    childsFor(button) {
        const id = button.getAttribute('aria-controls');

        return id === null ? null : this.element.querySelector(`#${CSS.escape(id)}`);
    }

    /*
     * One place sets all three things that must agree: what is visible, what the button says
     * about it, and what the button's label offers to do next. Two of them in two places is how
     * a collapsed branch ends up announced as expanded.
     */
    apply(childs, fold) {
        childs.hidden = fold;

        const button = this.element.querySelector(
            `[aria-controls="${CSS.escape(childs.id)}"]`,
        );

        if (button === null) {
            return;
        }

        button.setAttribute('aria-expanded', fold ? 'false' : 'true');
        button.classList.toggle('admin-tree__toggle--folded', fold);

        const text = button.querySelector('.admin-tree__toggle-text');

        if (text !== null) {
            const label = text.textContent.replace(/ (auf|zu)klappen$/, '');

            text.textContent = `${label} ${fold ? 'aufklappen' : 'zuklappen'}`;
        }
    }

    /*
     * ONE HANDLE PER MOVABLE NODE. The nodes that may move are the ones the server marked with
     * `data-admin-tree-id`; it only marks them when the schema says the entity carries an order
     * and the editor may write (ADR 0026 §5), so this loop needs no permission logic of its own.
     */
    addHandles() {
        this.element.querySelectorAll('[data-admin-tree-id]').forEach((node) => {
            const row = node.querySelector('.admin-tree__row');

            if (row === null || row.querySelector('.admin-tree__handle') !== null) {
                return;
            }

            const handle = document.createElement('span');

            handle.className = 'admin-tree__handle';
            handle.draggable = true;
            handle.setAttribute('aria-hidden', 'true');
            handle.title = 'Ziehen, um zu verschieben';
            handle.textContent = '⠿';

            handle.addEventListener('dragstart', (event) => this.start(event, node));
            handle.addEventListener('dragend', () => this.finish());

            row.prepend(handle);
        });
    }

    start(event, node) {
        this.dragging = node;
        this.snapshot = this.element.innerHTML;

        if (event.dataTransfer !== null) {
            event.dataTransfer.effectAllowed = 'move';
            /* Firefox starts no drag at all without payload; the id is the cheapest honest one. */
            event.dataTransfer.setData('text/plain', node.dataset.adminTreeId);
        }

        node.classList.add('admin-tree__node--dragging');

        this.over = (moved) => this.hover(moved);
        this.dropped = (moved) => this.release(moved);

        this.element.addEventListener('dragover', this.over);
        this.element.addEventListener('drop', this.dropped);
    }

    finish() {
        this.element.removeEventListener('dragover', this.over);
        this.element.removeEventListener('drop', this.dropped);
        this.clearDropHints();

        if (this.dragging !== null) {
            this.dragging.classList.remove('admin-tree__node--dragging');
        }

        this.dragging = null;
    }

    /*
     * WHAT A DROP WOULD MEAN, decided by where in the row the pointer is. The middle band
     * reparents, the outer quarters insert before or after — two intents, and the editor has to
     * be able to tell which one is armed, which is why they get two different shapes
     * (`admin-tree__marker` is a line, `admin-tree__row--drop` a frame).
     */
    intent(event) {
        if (this.dragging === null) {
            return null;
        }

        const row = event.target.closest === undefined
            ? null
            : event.target.closest('.admin-tree__row');

        if (row === null) {
            return null;
        }

        const node = row.closest('[data-admin-tree-id]');

        /* An unmarked node cannot be a target: the server would refuse the write anyway. */
        if (node === null) {
            return null;
        }

        /*
         * THE SUBTREE IS REFUSED HERE TOO, not only on the server. The server's refusal is the
         * binding one (gap L31 is measured and lethal to a branch), but letting the pointer offer
         * a drop that is certain to be rejected would teach the editor to ignore the refusal.
         */
        if (this.dragging.contains(node)) {
            return null;
        }

        const box = row.getBoundingClientRect();
        const edge = box.height / 4;

        if (event.clientY < box.top + edge) {
            return { kind: 'before', node };
        }

        if (event.clientY > box.bottom - edge) {
            return { kind: 'after', node };
        }

        return { kind: 'into', node };
    }

    hover(event) {
        const aim = this.intent(event);

        this.clearDropHints();

        if (aim === null) {
            return;
        }

        event.preventDefault();

        if (event.dataTransfer !== null) {
            event.dataTransfer.dropEffect = 'move';
        }

        if (aim.kind === 'into') {
            const row = aim.node.querySelector('.admin-tree__row');

            if (row !== null) {
                row.classList.add('admin-tree__row--drop');
            }

            return;
        }

        const marker = document.createElement('hr');

        marker.className = 'admin-tree__marker';

        if (aim.kind === 'before') {
            aim.node.before(marker);
        } else {
            aim.node.after(marker);
        }
    }

    clearDropHints() {
        this.element
            .querySelectorAll('.admin-tree__marker')
            .forEach((marker) => marker.remove());
        this.element
            .querySelectorAll('.admin-tree__row--drop')
            .forEach((row) => row.classList.remove('admin-tree__row--drop'));
    }

    release(event) {
        const aim = this.intent(event);

        if (aim === null) {
            return;
        }

        event.preventDefault();

        const moved = this.dragging;

        /*
         * THE POSITION IS COUNTED IN THE TARGET LEVEL WITHOUT THE MOVED NODE, which is the same
         * list the server builds before it inserts (ADR 0026 §3). Counting it with the node still
         * in place is off by one for every move downwards inside one level.
         */
        const parent = aim.kind === 'into'
            ? aim.node.dataset.adminTreeId
            : (aim.node.parentElement.closest('[data-admin-tree-id]')?.dataset.adminTreeId ?? '');

        let position = 0;

        if (aim.kind === 'into') {
            position = 0;
        } else {
            const level = Array.from(aim.node.parentElement.children)
                .filter((item) => item.dataset.adminTreeId !== undefined && item !== moved);

            position = level.indexOf(aim.node) + (aim.kind === 'after' ? 1 : 0);
        }

        this.clearDropHints();
        this.send(moved.dataset.adminTreeId, parent, position);
    }

    /*
     * ONE DRAG, ONE POST. The token travels in the body because the tree has no form to carry it
     * and its cookie is `HttpOnly` on purpose (004-002-0003); `_format=json` asks for the
     * machine-readable half of ADR 0021's two-sided answer. The address is the path without its
     * query: a stale `?moved=…` from the last report must not ride along — the same trap
     * `search_controller.js` fell into in 004-001-0004.
     */
    send(id, parent, position) {
        const body = new FormData();

        body.set(this.tokenFieldValue, this.tokenValue);
        body.set('_format', 'json');
        body.set('action', 'move');
        body.set('id', id);
        body.set('parent', parent);
        body.set('position', String(position));

        fetch(window.location.pathname, { method: 'POST', body })
            .then((response) => response.json())
            .then((answer) => this.settle(answer))
            .catch(() => this.settle(null));
    }

    /*
     * THE ANSWER DECIDES, NOT THE DROP. On success the level is rearranged into the order the
     * server re-read; on anything else the tree goes back to the snapshot taken on `dragstart`.
     * A null answer — network gone, JSON unreadable — is treated as a refusal, because the one
     * thing that must not happen is a display that claims a move the database never got.
     */
    settle(answer) {
        if (answer === null || answer.ok !== true) {
            this.revert();
            this.say(answer === null
                ? 'Das Verschieben konnte nicht gesendet werden. Der Baum zeigt den gespeicherten Stand.'
                : answer.text, false);

            return;
        }

        /*
         * A RELOAD IS THE HONEST FALLBACK, not a failure. The target level may not exist in this
         * DOM at all: the template renders a node's child container only when it already has
         * children (`{% if node.childs is not empty %}`), so a drop onto a leaf has no list to
         * append into — and the toggle button, its `aria-controls` and the container id would all
         * have to be invented here. Letting the server render the new shape is both shorter and
         * the same rule as everywhere else in this story: the stored state is what gets shown.
         * The fold state survives it, because it lives in `sessionStorage`.
         */
        if (!this.arrange(answer.parent, Array.isArray(answer.order) ? answer.order : [])) {
            window.location.reload();

            return;
        }

        this.snapshot = null;
        this.say(answer.text, true);
        this.addHandles();
    }

    revert() {
        if (this.snapshot === null) {
            return;
        }

        this.element.innerHTML = this.snapshot;
        this.snapshot = null;
        this.addHandles();
        this.childsTargets.forEach((childs) => {
            this.apply(childs, this.folded.includes(childs.dataset.adminTreeNode));
        });
    }

    /*
     * The level is put into the server's order by appending in that order — a list of nodes
     * appended one by one ends up in exactly the sequence they were appended in, and moving a
     * node that is already in the right place is a no-op the browser handles.
     */
    arrange(parent, order) {
        if (order.length === 0) {
            return false;
        }

        const nodes = new Map();

        order.forEach((id) => {
            const node = this.element.querySelector(
                `[data-admin-tree-id="${CSS.escape(id)}"]`,
            );

            if (node !== null) {
                nodes.set(id, node);
            }
        });

        if (nodes.size !== order.length) {
            return false;
        }

        let list = nodes.get(order[0]).parentElement;

        if (parent !== '') {
            const target = this.element.querySelector(
                `[data-admin-tree-id="${CSS.escape(parent)}"]`,
            );
            const inside = target === null
                ? null
                : target.querySelector('.admin-tree__childs > .admin-tree__list');

            if (inside === null) {
                return false;
            }

            list = inside;
        }

        order.forEach((id) => list.append(nodes.get(id)));

        return true;
    }

    /* The report line the page already owns, reused so a drag and a reload say the same thing. */
    say(text, ok) {
        if (text === undefined || text === '') {
            return;
        }

        let line = this.element.parentElement.querySelector('.admin-tree__report');

        if (line === null) {
            line = document.createElement('p');
            line.className = 'admin-tree__report';
            line.setAttribute('role', 'status');
            this.element.before(line);
        }

        line.classList.toggle('admin-tree__report--problem', !ok);
        line.textContent = text;
    }

    get storageKey() {
        return `contentfly-admin.tree.folded.${this.entityValue}`;
    }

    read() {
        try {
            const stored = window.sessionStorage.getItem(this.storageKey);
            const parsed = stored === null ? [] : JSON.parse(stored);

            return Array.isArray(parsed) ? parsed.filter((id) => typeof id === 'string') : [];
        } catch (error) {
            return [];
        }
    }

    write() {
        try {
            window.sessionStorage.setItem(this.storageKey, JSON.stringify(this.folded));
        } catch (error) {
            /* A fold nobody can remember is still a fold that works. */
        }
    }
}
