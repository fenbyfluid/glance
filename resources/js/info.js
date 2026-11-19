import { parseStartTime, playVideoWithFallback, renderTextContent } from './player.js';

document.addEventListener('DOMContentLoaded', function() {
    // Set by the lightbox's info button, to carry on from where the video had got to.
    const startTime = parseStartTime(window.location.hash);

    document.querySelectorAll('video[data-player-video]').forEach(function(element) {
        if (startTime !== null) {
            // The HLS fallback replaces the source, so wait for whichever one gets to play. It's left to the user to
            // press play, browsers block autoplay without a user gesture on the page.
            element.addEventListener('loadedmetadata', function() {
                element.currentTime = startTime;
            }, { once: true });
        }

        playVideoWithFallback(element, element.querySelector('source').src);
    });

    // The share dialog (share.js) gets this page's URL, with the playing video's position like the lightbox's.
    document.querySelectorAll('[data-share-path]').forEach(function(button) {
        button.addEventListener('click', function() {
            const url = new URL(window.location.href);
            const video = document.querySelector('video[data-player-video]');

            url.hash = (video && video.currentTime > 0) ? 't=' + Math.floor(video.currentTime) : '';

            window.dispatchEvent(new CustomEvent('open-share-dialog', {
                detail: { path: button.getAttribute('data-share-path'), internalUrl: url.href },
            }));
        });
    });

    document.querySelectorAll('[data-player-text]').forEach(function(container) {
        const kind = container.getAttribute('data-player-text');

        fetch(container.getAttribute('data-src'), { credentials: 'same-origin' }).then(response => {
            if (!response.ok) {
                throw new Error(`Loading failed: ${response.status}`);
            }

            return response.text();
        }).then(content => {
            container.replaceChildren(...renderTextContent(kind, content, 'dark:prose-invert mx-auto').get());
        }).catch(error => {
            console.error(error);

            container.textContent = 'Sorry, this file couldn\'t be loaded.';
        });
    });
});
