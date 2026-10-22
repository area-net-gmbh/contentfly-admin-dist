/*
 * Searching the list while typing, instead of pressing return (002-004-0003).
 *
 * THE FORM STAYS THE FORM. This controller adds one behaviour — submit on input — and changes
 * nothing else: no second request path, no rendering of its own, no state beside the URL. Without
 * the module the box is a plain text field in a plain GET form and one press of the return key
 * does the same thing, which is the stance `admin/rte` and `admin/search` take as well.
 *
 * NO DEBOUNCE TIMER, THE VISIT IS THE ABORT. `admin/search` cancels its own fetch when the next
 * keystroke arrives, because a timer only guesses how fast somebody types. The same property
 * comes for free here: Turbo abandons an in-flight visit when the next one starts, so the results
 * of an outdated term can never arrive late. `data-turbo-action="replace"` on the form is what
 * keeps those visits out of the browser history — without it, every keystroke would be a back
 * step.
 *
 * THE BOX SURVIVES THE VISIT BECAUSE IT IS `data-turbo-permanent`. Turbo replaces the body, which
 * would otherwise take the field the reader is typing in — and with it the focus and the caret —
 * away mid-word. A permanent element is carried over instead.
 *
 * AND THAT IS WHY `connect()` CORRECTS ITS VALUE. Being permanent, the box also survives a visit
 * to a *different* list, where the server did not fill it and the term does not apply; it would
 * then show a search that is not in effect. The URL is the truth, so the box is set from it — but
 * never while the reader has the field, because then the keystroke that has not reached the URL
 * yet is the newer fact.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['box'];

    connect() {
        this.submit = () => this.element.requestSubmit();
        this.boxTarget.addEventListener('input', this.submit);

        if (document.activeElement !== this.boxTarget) {
            this.boxTarget.value = new URL(window.location.href).searchParams.get('q') ?? '';
        }
    }

    disconnect() {
        this.boxTarget.removeEventListener('input', this.submit);
    }
}
