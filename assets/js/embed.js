/**
 * The embeddable widget's only script: tell the parent page how tall we are.
 *
 * A review wall is as tall as the reviews in it, and a fixed-height iframe
 * either clips the last one or leaves a band of empty space under it. So this
 * posts the document height to whoever framed us, and the snippet the
 * Reputation settings page hands out listens for it.
 *
 * Deliberately small and deliberately not inline: the Content-Security-Policy
 * forbids inline script, and an embeddable page is the last place to carve out
 * an exception.
 *
 * It sends a height and nothing else. It never reads from the parent, so a
 * hostile host page has nothing to answer — which is why there is no
 * `message` listener here, and why the target origin is `*`: the only thing
 * crossing the boundary is a number describing our own layout.
 */
(function () {
    'use strict';

    if (window.parent === window) {
        return; // Not framed. Nothing to tell anybody.
    }

    var last = 0;

    /*
     * WHY THE HEIGHT IS SENT SEVERAL TIMES over the first couple of seconds,
     * and not only when it changes.
     *
     * The iframe loads asynchronously, so a host page that installs its
     * listener late — in a deferred bundle, a tag manager, anything that is
     * not the inline snippet we hand out — is not listening yet when the
     * first message goes. And once the height has settled, a
     * change-triggered post never fires again, so that host would wait for
     * ever on a message that already happened and keep whatever height it
     * guessed.
     *
     * MEASURED in a real browser: with only the change check below, a
     * listener added after load heard nothing at all. So the first few posts
     * are forced, and after that only real changes are sent.
     */
    function height() {
        var body = document.body;
        var html = document.documentElement;

        return Math.max(
            body.scrollHeight,
            body.offsetHeight,
            html.clientHeight,
            html.scrollHeight,
            html.offsetHeight
        );
    }

    function post(force) {
        var now = height();

        // Only on a real change, and only a meaningful one: a sub-pixel
        // wobble as fonts settle would otherwise post on every frame. A
        // forced post ignores that — see the comment above.
        if (!force && Math.abs(now - last) < 2) {
            return;
        }

        last = now;

        try {
            window.parent.postMessage({ castleCrmEmbedHeight: now }, '*');
        } catch (e) {
            // A browser that refuses the post leaves the iframe at whatever
            // height the host page set, which is a worse page, not a broken
            // one.
        }
    }

    post(true);

    window.addEventListener('load', function () { post(true); });
    window.addEventListener('resize', function () { post(false); });

    // The forced repeats, for a host that starts listening late.
    [250, 750, 2000].forEach(function (delay) {
        window.setTimeout(function () { post(true); }, delay);
    });

    // Images and web fonts land after `load` on a slow connection, and each
    // one changes the height, so keep watching after the repeats stop.
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(function () { post(false); }).observe(document.body);
    }
})();
