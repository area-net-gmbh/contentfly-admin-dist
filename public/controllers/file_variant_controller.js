/*
 * What one configured derivative actually turned out to be (003-003-0003).
 *
 * TWO QUESTIONS THE SERVER CANNOT ANSWER, AND BOTH ARE ANSWERED HERE.
 *
 * First, whether the alias exists at all. Which `PIM\ThumbnailSetting` rows an installation has
 * cannot be read from the package — the installer marks its defaults `isIntern` and
 * `Api::getList()` hard-codes `isIntern = false`, so the one read path this package uses never
 * sees them (Schema-Vertrag L25). An unknown alias is a `FileNotFoundException` and so a 500, and
 * `admin/file-preview` turns that into a silent swap for the original. On this page a silent swap
 * would be wrong: the list's whole job is to say which derivatives a project's configuration
 * really produces, so a failed entry is named instead of replaced.
 *
 * Second, the size. The nominal width and height live in the `ThumbnailSetting` row, behind the
 * same wall, and `doCut` plus the image's own proportions decide what a derivative ends up
 * being anyway. The browser has the finished image in hand, so it reads the size off that — a
 * measured value instead of an unreachable configured one.
 *
 * WITHOUT THIS MODULE THE PAGE STILL WORKS, the stance every controller here takes: the entries
 * are links, they lead where they lead, and a broken one shows the browser's own mark. The module
 * adds the measurement and the plain sentence; it is not what makes the list work.
 */

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['image', 'size', 'missing'];

    connect() {
        /*
         * A CACHED IMAGE IS ALREADY DECIDED BY NOW. Turbo swaps the body, so `connect()` can run
         * after the browser has finished with this image — neither `load` nor `error` would fire
         * again. `complete` is that finished state, and `naturalWidth === 0` distinguishes the
         * two outcomes within it.
         */
        if (this.imageTarget.complete) {
            this.settle();

            return;
        }

        this.onLoad = () => this.settle();
        this.onError = () => this.settle();

        this.imageTarget.addEventListener('load', this.onLoad);
        this.imageTarget.addEventListener('error', this.onError);
    }

    disconnect() {
        if (this.onLoad) {
            this.imageTarget.removeEventListener('load', this.onLoad);
            this.onLoad = null;
        }

        if (this.onError) {
            this.imageTarget.removeEventListener('error', this.onError);
            this.onError = null;
        }
    }

    settle() {
        this.disconnect();

        const width = this.imageTarget.naturalWidth;
        const height = this.imageTarget.naturalHeight;

        if (width === 0) {
            /*
             * THE IMAGE IS HIDDEN RATHER THAN LEFT AS A BROKEN MARK. The sentence next to it
             * already says what happened, and two signals for one fact read as two problems.
             */
            this.imageTarget.hidden = true;
            this.missingTarget.hidden = false;
            this.sizeTarget.hidden = true;

            return;
        }

        this.sizeTarget.textContent = `${width} × ${height} Pixel`;
        this.sizeTarget.hidden = false;
    }
}
