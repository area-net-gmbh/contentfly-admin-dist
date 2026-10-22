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

        this.rowTargets.forEach((row) => row.addEventListener('change', this.onRow));

        if (this.hasAllTarget) {
            this.allTarget.addEventListener('change', this.onAll);
        }

        this.update();
    }

    disconnect() {
        this.rowTargets.forEach((row) => row.removeEventListener('change', this.onRow));

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
