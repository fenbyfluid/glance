// Playback and rendering shared by the lightbox (media.js) and the info page (info.js).

import $ from './jquery-global.js';

const HLS_MEDIA_MIME_TYPE = 'video/mp4; codecs="avc1.64001f, mp4a.40.2"';

export function getHlsStreamUrl(src) {
    const url = new URL(src, window.location.href);

    // TODO: Use a named route somehow, account for the base path.
    url.pathname = '/_stream' + url.pathname + '.m3u8';

    return url.href;
}

async function startHlsVideo(element, hlsUrl) {
    const { Hls } = await import('hls.js/light');

    if (!Hls.isMSESupported() || !Hls.getMediaSource().isTypeSupported(HLS_MEDIA_MIME_TYPE)) {
        return;
    }

    const hls = new Hls({
        // debug: true,
        backBufferLength: 90,
    });

    hls.on(Hls.Events.ERROR, function(event, data) {
        console.log('hls.js error', data);

        if (!data.fatal) {
            return;
        }

        switch (data.type) {
            case Hls.ErrorTypes.MEDIA_ERROR:
                console.log('fatal media error encountered, try to recover');
                hls.recoverMediaError();
                break;
            case Hls.ErrorTypes.NETWORK_ERROR:
                console.error('fatal network error encountered', data);
                // All retries and media options have been exhausted.
                // Immediately trying to restart loading could cause loop loading.
                // Consider modifying loading policies to best fit your asset and network
                // conditions (manifestLoadPolicy, playlistLoadPolicy, fragLoadPolicy).
                break;
            default:
                // cannot recover
                hls.destroy();
                break;
        }
    });

    hls.loadSource(hlsUrl);
    hls.attachMedia(element);

    return hls;
}

/**
 * Plays the video's own <source>s, and once the browser has given up on all of them, a live HLS transcode of `src`.
 *
 * @param {HTMLVideoElement} element
 * @param {string} src
 * @returns {function(): void} Stops the fallback, call it when the element is being thrown away.
 */
export function playVideoWithFallback(element, src) {
    let fallbackStarted = false;
    let destroyed = false;
    let hlsInstance = null;

    const startFallback = function() {
        if (fallbackStarted) {
            return;
        }

        fallbackStarted = true;

        startHlsVideo(element, getHlsStreamUrl(src)).then(hls => {
            if (!hls) {
                return;
            }

            if (destroyed) {
                hls.destroy();
                return;
            }

            hlsInstance = hls;
        });
    };

    // Source load failures fire `error` on the <source> element, which doesn't bubble, so capture it on the video.
    // Once the last source has failed, networkState is NETWORK_NO_SOURCE.
    const onSourceError = function() {
        if (element.networkState === HTMLMediaElement.NETWORK_NO_SOURCE) {
            element.removeEventListener('error', onSourceError, true);
            startFallback();
        }
    };

    if (element.networkState === HTMLMediaElement.NETWORK_NO_SOURCE) {
        startFallback();
    } else {
        element.addEventListener('error', onSourceError, true);
    }

    return function destroy() {
        destroyed = true;
        element.removeEventListener('error', onSourceError, true);
        hlsInstance?.destroy();
        hlsInstance = null;
    };
}

/**
 * Wraps a text or HTML file's contents for display. Returns null for any other kind of content.
 *
 * @param {string} kind The file's MediaContentKind.
 * @param {string} content
 * @param {string} proseClasses Extra classes for the prose element, e.g. for dark mode (the lightbox is always light).
 * @returns {jQuery|null}
 */
export function renderTextContent(kind, content, proseClasses = '') {
    if (kind === 'text') {
        return $('<div class="prose" style="white-space: pre-wrap;"></div>')
            .addClass(proseClasses)
            .text(content)
            .wrap('<div></div>')
            .parent();
    }

    if (kind === 'html') {
        // TODO: Like the Readme rendering, this requires trusted HTML.
        //       We'd ideally like to filter it to safe Markdown elements and no attributes.
        const wrapped = $('<div class="prose"></div>')
            .addClass(proseClasses)
            .html(content)
            .wrap('<div></div>')
            .parent();

        if (wrapped.find('style').remove().length > 0) {
            console.warn('HTML content contained style tags, they will be ignored.');
        }

        return wrapped;
    }

    return null;
}

/**
 * Parses a start time from a media fragment-style `#t=<seconds>` hash.
 *
 * @param {string} hash
 * @returns {number|null}
 */
export function parseStartTime(hash) {
    const match = /(?:^#|&)t=(\d+(?:\.\d+)?)(?:&|$)/.exec(hash);

    return match ? parseFloat(match[1]) : null;
}
