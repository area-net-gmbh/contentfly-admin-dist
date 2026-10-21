/*
 * The form's field groups, switched without leaving the page (002-006-0003).
 *
 * WHY THIS IS JAVASCRIPT AT ALL. Everything else the admin does navigates: sorting and paging
 * in the list are plain links, because their state belongs in the URL and a round trip costs
 * nothing. A tab is the opposite case — the form around it holds input nobody has saved yet,
 * and a server round trip to change tabs would discard it.
 *
 * IT HIDES, IT DOES NOT REVEAL. Every panel is visible in the delivered HTML and this controller
 * hides the inactive ones when it connects. The other way round — panels hidden in CSS, revealed
 * by script — would turn a module that fails to load into a form showing one group of fields
 * and no sign that the rest exist.
 *
 * STORY 002-006-0005 WILL NEED ONE MORE THING: a validation error belonging to a field in a
 * hidden group has to bring that group forward, or the message is announced for something the
 * editor cannot see. `activate()` is the hook for it.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.activate(0);
    }

    select(event) {
        this.activate(this.tabTargets.indexOf(event.currentTarget));
    }

    /*
     * The arrow keys of the ARIA tabs pattern, and only those: Home and End earn their keep on a
     * bar with many tabs, and a form's field groups are counted on one hand.
     */
    key(event) {
        const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

        if (step === undefined) {
            return;
        }

        event.preventDefault();

        const count = this.tabTargets.length;
        const next = (this.tabTargets.indexOf(event.currentTarget) + step + count) % count;

        this.activate(next);
        this.tabTargets[next].focus();
    }

    /*
     * `tabindex` moves with the selection on purpose. A tab bar is one stop in the tab order,
     * not one per tab — Tab enters it and leaves it, the arrow keys move inside it.
     */
    activate(active) {
        this.tabTargets.forEach((tab, index) => {
            const on = index === active;

            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.setAttribute('tabindex', on ? '0' : '-1');
        });

        this.panelTargets.forEach((panel, index) => {
            panel.hidden = index !== active;
        });
    }
}
