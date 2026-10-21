/*
 * Formatted text in a form field (002-007-0005).
 *
 * THE TEXTAREA STAYS THE FIELD. This controller does not replace it, it puts an editable surface
 * in front of it and keeps the two in step; the textarea is what the browser submits. The same
 * stance as `admin/tabs`: a module that fails to load leaves a plain textarea holding HTML —
 * unlovely and completely usable — instead of a field that can no longer be edited at all.
 *
 * NOTHING HERE IS A SECURITY MEASURE. Whatever this produces is reduced on the server by
 * `View\RichText` to the elements the toolbar offers. That is not redundancy: a filter in the
 * browser is bypassed by posting the form by hand, which costs one line of `curl`. The toolbar
 * and the server's allowlist are meant to be read together, and the server one is the promise.
 *
 * `document.execCommand` IS DEPRECATED AND IS STILL THE RIGHT CHOICE. It is the only way to apply
 * formatting to a selection without a third-party library, and the package ships no npm build
 * (ADR 0007). Every current browser implements it; when one stops, this field degrades to its
 * textarea rather than breaking, which is the whole reason the textarea stays underneath.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'bar'];

    static values = { field: String };

    connect() {
        this.surface = document.createElement('div');
        this.surface.className = 'admin-form__rte-surface';
        this.surface.contentEditable = 'true';
        this.surface.innerHTML = this.sourceTarget.value;

        /*
         * THE LABEL KEEPS POINTING AT THE TEXTAREA, which is now hidden, so the surface carries
         * the accessible name itself. Without this the editable area is an unnamed text box to a
         * screen reader — the field's label would be announced for something invisible.
         */
        this.surface.setAttribute('role', 'textbox');
        this.surface.setAttribute('aria-multiline', 'true');

        const label = document.querySelector(`label[for="${this.fieldValue}"]`);

        if (label !== null) {
            this.surface.setAttribute('aria-label', label.textContent.trim());
        }

        if (this.sourceTarget.getAttribute('aria-describedby') !== null) {
            this.surface.setAttribute('aria-describedby', this.sourceTarget.getAttribute('aria-describedby'));
        }

        if (this.sourceTarget.getAttribute('aria-invalid') === 'true') {
            this.surface.setAttribute('aria-invalid', 'true');
        }

        this.sync = () => {
            this.sourceTarget.value = this.surface.innerHTML;
        };

        this.surface.addEventListener('input', this.sync);
        this.surface.addEventListener('blur', this.sync);

        this.element.insertBefore(this.surface, this.sourceTarget);
        this.sourceTarget.hidden = true;
        this.barTarget.hidden = false;
    }

    disconnect() {
        if (this.surface === undefined) {
            return;
        }

        /*
         * SYNC ONE LAST TIME. Turbo replaces the page body on navigation, and a disconnect that
         * dropped the surface without writing it back would lose whatever was typed since the
         * last input event.
         */
        this.sync();
        this.surface.removeEventListener('input', this.sync);
        this.surface.removeEventListener('blur', this.sync);
        this.surface.remove();
        this.sourceTarget.hidden = false;
    }

    bold() {
        this.command('bold');
    }

    italic() {
        this.command('italic');
    }

    bullets() {
        this.command('insertUnorderedList');
    }

    numbers() {
        this.command('insertOrderedList');
    }

    /*
     * `prompt` RATHER THAN A DIALOGUE OF OUR OWN. A link needs one string, and a built-in prompt
     * needs no markup, no focus trap and no escape handling — all three of which a hand-built
     * dialogue has to get right to be no worse than this.
     */
    link() {
        const href = window.prompt('Adresse des Links');

        if (href === null || href.trim() === '') {
            return;
        }

        this.command('createLink', href.trim());
    }

    unlink() {
        this.command('unlink');
    }

    /*
     * THE SURFACE IS FOCUSED FIRST. A toolbar button takes the focus on mousedown in some
     * browsers, and `execCommand` without a selection in the editable area does nothing at all —
     * the button would look broken rather than inert.
     */
    command(name, argument = undefined) {
        this.surface.focus();
        document.execCommand(name, false, argument);
        this.sync();
    }
}
