/*
 * Putting the entries of a list in an order by hand (003-004-0005).
 *
 * IT KNOWS NOTHING ABOUT IMAGES, and that is the point of the cut. It sees a list whose items
 * carry an id, and one field it writes the resulting order into — nothing else. The image field
 * puts it on the list its picker builds; the tree of epic 004 can put it on a server-rendered
 * list and read the same order out of the same field. A controller that knew about files would
 * have to be written twice.
 *
 * TWO WAYS TO MOVE AN ENTRY, BECAUSE DRAGGING IS NOT ONE. HTML5 drag and drop answers a mouse
 * and nothing else — no keyboard, no touch. The handle is therefore a real `<button>`: dragging
 * works where there is a pointer, and `ArrowUp`/`ArrowDown` move the entry everywhere. Shipping
 * only the drag would have made the order unreachable for anybody not using a mouse, which is
 * the kind of gap that does not show up in a screenshot.
 *
 * NO LIBRARY. There is no npm build (ADR 0007), so this is the browser's own drag events plus
 * two key handlers. That is also why there is no animation: a transition between two positions
 * is where a hand-written sorter starts fighting the browser's own drag image.
 *
 * THE FIELD IS THE TRUTH, THE DOM IS THE VIEW. Every move writes the whole order into the field
 * and announces it; whoever owns the list may then redraw it. This controller never decides what
 * an entry looks like.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { field: String };

    connect() {
        this.dragged = null;

        this.element.addEventListener('dragstart', this.onDragStart);
        this.element.addEventListener('dragover', this.onDragOver);
        this.element.addEventListener('dragend', this.onDragEnd);
        this.element.addEventListener('drop', this.onDrop);
        this.element.addEventListener('keydown', this.onKeyDown);

        this.prepare();
    }

    disconnect() {
        this.element.removeEventListener('dragstart', this.onDragStart);
        this.element.removeEventListener('dragover', this.onDragOver);
        this.element.removeEventListener('dragend', this.onDragEnd);
        this.element.removeEventListener('drop', this.onDrop);
        this.element.removeEventListener('keydown', this.onKeyDown);
    }

    /** The field this controller writes the order into, or `null` while the form has none. */
    get field() {
        return this.fieldValue === '' ? null : document.getElementById(this.fieldValue);
    }

    get items() {
        return [...this.element.querySelectorAll('[data-sortable-id]')];
    }

    /**
     * Makes every entry draggable and gives it a handle.
     *
     * DONE HERE AND NOT IN THE MARKUP, so a list that was rendered without JavaScript never
     * shows a handle that cannot do anything. The owner of the list only has to put
     * `data-sortable-id` on each entry.
     */
    prepare() {
        for (const item of this.items) {
            if (item.querySelector('[data-sortable-handle]') !== null) {
                continue;
            }

            item.draggable = true;

            const handle = document.createElement('button');
            handle.type = 'button';
            handle.className = 'admin-sortable__handle';
            handle.dataset.sortableHandle = '';
            handle.textContent = '⠿';
            handle.setAttribute(
                'aria-label',
                `${item.dataset.sortableLabel ?? 'Eintrag'} verschieben — mit den Pfeiltasten nach oben oder unten`
            );

            item.prepend(handle);
        }
    }

    onDragStart = (event) => {
        const item = event.target.closest('[data-sortable-id]');

        if (item === null) {
            return;
        }

        this.dragged = item;
        item.classList.add('admin-sortable__item--dragging');

        /* Firefox starts no drag at all without data on the transfer. */
        event.dataTransfer.setData('text/plain', item.dataset.sortableId);
        event.dataTransfer.effectAllowed = 'move';
    };

    onDragOver = (event) => {
        if (this.dragged === null) {
            return;
        }

        /* Without this the drop never fires — the default is "reject". */
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const over = event.target.closest('[data-sortable-id]');

        if (over === null || over === this.dragged) {
            return;
        }

        /*
         * THE MIDPOINT DECIDES, not the element. Inserting always before the hovered entry makes
         * the last position unreachable; comparing against its middle is what lets an entry be
         * dropped behind the last one.
         */
        const box = over.getBoundingClientRect();
        const after = (event.clientY - box.top) > (box.height / 2);

        over.parentNode.insertBefore(this.dragged, after ? over.nextSibling : over);
    };

    onDrop = (event) => {
        if (this.dragged === null) {
            return;
        }

        event.preventDefault();
        this.commit();
    };

    onDragEnd = () => {
        if (this.dragged === null) {
            return;
        }

        this.dragged.classList.remove('admin-sortable__item--dragging');
        this.dragged = null;
    };

    onKeyDown = (event) => {
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
            return;
        }

        const handle = event.target.closest('[data-sortable-handle]');

        if (handle === null) {
            return;
        }

        const item = handle.closest('[data-sortable-id]');

        if (item === null) {
            return;
        }

        /* The arrows would otherwise scroll the page under the moving entry. */
        event.preventDefault();

        const sibling = event.key === 'ArrowUp'
            ? item.previousElementSibling
            : item.nextElementSibling;

        if (sibling === null) {
            return;
        }

        if (event.key === 'ArrowUp') {
            item.parentNode.insertBefore(item, sibling);
        } else {
            item.parentNode.insertBefore(sibling, item);
        }

        this.commit();

        /*
         * THE FOCUS FOLLOWS THE ENTRY, not the position. Whoever owns the list may redraw it on
         * the change below, so the handle this event came from can be gone — it is looked up
         * again by id instead of being remembered.
         */
        const again = this.element.querySelector(
            `[data-sortable-id="${CSS.escape(item.dataset.sortableId)}"] [data-sortable-handle]`
        );

        if (again !== null) {
            again.focus();
        }
    };

    /** Writes the order the DOM now shows into the field. The one place that does. */
    commit() {
        const order = this.items.map((item) => item.dataset.sortableId);

        this.element.dispatchEvent(new CustomEvent('sortable:ordered', {
            bubbles: true,
            detail: { order },
        }));

        const field = this.field;

        if (field === null) {
            return;
        }

        field.value = order.join(',');
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
