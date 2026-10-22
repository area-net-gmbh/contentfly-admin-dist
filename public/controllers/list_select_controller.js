/*
 * The list's multi-selection (002-004-0004).
 *
 * THE CHECKBOXES ARE THE STATE, this controller only reads them. It keeps no list of its own, so
 * a box the browser restored after a back navigation, or one the reader ticked before the module
 * finished loading, is counted like any other — the same stance `admin/search` takes towards its
 * hidden input.
 *
 * WITHOUT THE MODULE THE SELECTION STILL WORKS. The row boxes are plain form fields and the
 * browser submits what is ticked; what is missing is the select-all box, which has no `name` and
 * therefore does nothing on its own, and the live count, which then keeps its initial wording.
 * Unlovely and working, as elsewhere in this package.
 *
 * THE BAR IS HIDDEN WITH THE `hidden` ATTRIBUTE, not with a class of the stylesheet — the same
 * decision the form's inactive tab panel follows: the attribute is what assistive technology
 * reads, and a stylesheet that fails to load must not leave an empty bar claiming a selection.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['all', 'row', 'bar', 'count'];

    connect() {
        this.onRow = () => this.update();
        this.onAll = () => this.toggleAll();
        this.onSubmit = (event) => this.guardDelete(event);

        this.rowTargets.forEach((row) => row.addEventListener('change', this.onRow));
        this.element.addEventListener('submit', this.onSubmit);

        if (this.hasAllTarget) {
            this.allTarget.addEventListener('change', this.onAll);
        }

        this.update();
    }

    disconnect() {
        this.rowTargets.forEach((row) => row.removeEventListener('change', this.onRow));
        this.element.removeEventListener('submit', this.onSubmit);

        if (this.hasAllTarget) {
            this.allTarget.removeEventListener('change', this.onAll);
        }

        if (this.hasBarTarget) {
            this.barTarget.hidden = false;
        }
    }

    get checked() {
        return this.rowTargets.filter((row) => row.checked);
    }

    /*
     * A DELETE OVER A SELECTION IS ASKED BACK, and the browser's own dialog is what asks. There
     * is no undo behind it: the core deletes one record per call and nothing collects them again.
     * Without this module the dialog is missing and the delete goes through on the first press —
     * that is the honest consequence of the no-JavaScript fallback and not a hole to paper over
     * here, because a server-side confirmation step would be a second page in the way of every
     * single delete.
     *
     * `event.submitter` IS WHAT MAKES THIS PRECISE. The same form carries the change action, and
     * reading the pressed button is the only way to tell the two apart — a guard on the form as a
     * whole would ask the question before an ordinary field change as well.
     */
    guardDelete(event) {
        const pressed = event.submitter;

        if (pressed === null || pressed === undefined || pressed.value !== 'delete') {
            return;
        }

        const picked = this.checked.length;
        const what = picked === 1 ? 'Ein Datensatz wird' : `${picked} Datensätze werden`;

        if (!window.confirm(`${what} gelöscht. Das kann nicht zurückgenommen werden.`)) {
            event.preventDefault();
        }
    }

    toggleAll() {
        this.rowTargets.forEach((row) => {
            row.checked = this.allTarget.checked;
        });

        this.update();
    }

    update() {
        const picked = this.checked.length;

        if (this.hasAllTarget) {
            this.allTarget.checked = picked > 0 && picked === this.rowTargets.length;

            /*
             * THE MIXED STATE IS NOT A THIRD VALUE THE BOX CAN BE SET TO. `indeterminate` only
             * changes how the box is drawn; `checked` stays whatever it was, which is why it is
             * written above before this line and not instead of it.
             */
            this.allTarget.indeterminate = picked > 0 && picked < this.rowTargets.length;
        }

        if (this.hasBarTarget) {
            this.barTarget.hidden = picked === 0;
        }

        if (this.hasCountTarget) {
            this.countTarget.textContent =
                picked === 1 ? '1 Zeile markiert' : `${picked} Zeilen markiert`;
        }
    }
}
