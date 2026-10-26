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
 * unfold.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'childs'];

    static values = { entity: String };

    connect() {
        this.folded = this.read();
        this.childsTargets.forEach((childs) => {
            this.apply(childs, this.folded.includes(childs.dataset.adminTreeNode));
        });
    }

    disconnect() {
        this.childsTargets.forEach((childs) => this.apply(childs, false));
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
