/*
 * A preview that cannot be derived shows the original instead (003-001-0004).
 *
 * WHY THIS IS NOT A SERVER-SIDE CHECK. Whether the configured thumbnail alias names an existing
 * `PIM\ThumbnailSetting` row cannot be found out from the package: the installer marks its two
 * default rows `isIntern`, and `Api::getList()` hard-codes `isIntern = false`, so the one read
 * path this package uses never sees them (Schema-Vertrag L25). Reaching past `Api` into the
 * entity manager would answer it and would also make the admin something other than the pure API
 * consumer it is. So the question stays where it can be answered: the derivative either arrives
 * or it does not.
 *
 * AND THAT CATCHES MORE THAN A MISSING ROW — a row that exists but cannot be applied to this one
 * file, a derivative deleted from disk, a size whose processor refuses. A lookup would have said
 * "the alias is fine" in every one of those.
 *
 * ONE ATTEMPT, NEVER TWO. The handler removes itself before it swaps the source, so a broken
 * original cannot start the same swap again — which, with the two addresses differing only in one
 * segment, would be a loop that keeps a request in flight forever. After the fallback the box
 * keeps its grey background and the row keeps its height, so a file that cannot be shown at all
 * looks like what it is rather than like a layout fault.
 *
 * WITHOUT THIS MODULE THE PAGE STILL WORKS, the stance every controller in this package takes:
 * the derivative is requested, and if it fails the browser shows its own broken-image mark. The
 * module upgrades that to the original; it is not what makes previews work.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { fallback: String };

    connect() {
        /*
         * A CACHED IMAGE IS ALREADY DECIDED BY NOW. Turbo swaps the body, so `connect()` can run
         * after the browser has finished with this element — `error` would then never fire again.
         * `complete && naturalWidth === 0` is that failed-and-finished state.
         */
        if (this.element.complete) {
            if (this.element.naturalWidth === 0) {
                this.swap();
            }

            return;
        }

        this.onError = () => this.swap();
        this.element.addEventListener('error', this.onError);
    }

    disconnect() {
        if (this.onError) {
            this.element.removeEventListener('error', this.onError);
            this.onError = null;
        }
    }

    swap() {
        this.disconnect();

        const fallback = this.fallbackValue;

        if (!fallback || this.element.getAttribute('src') === fallback) {
            return;
        }

        this.element.src = fallback;
    }
}
