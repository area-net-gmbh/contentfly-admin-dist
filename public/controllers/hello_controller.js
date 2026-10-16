/*
 * The first Stimulus controller of the admin — a placeholder with one job.
 *
 * It proves that an ES module reaches the browser from inside `vendor/` and is executed
 * there: the delivery sets `text/javascript`, and a browser refuses a module served under
 * any other type. That makes this file the smallest possible end-to-end check of the asset
 * route.
 *
 * THE BARE IMPORT BELOW RESOLVES ONLY ONCE THE IMPORTMAP EXISTS (task 001-007-0003). Until
 * then this file is delivered correctly and the import fails — that order is intended: this
 * task delivers bytes, the next one makes the module graph resolve.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.element.dataset.adminAssetsReady = 'true';
    }
}
