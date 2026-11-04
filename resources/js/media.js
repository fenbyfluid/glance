// #5 How to disable touch gestures/swiping
// When you want to make your content selectable or clickable, you have two options:
// * disable touch gestures completely by setting touch:false
// * add data-selectable="true" attribute to your html element

import '../css/media.css';

import $ from './jquery-global.js';
import '@fancyapps/fancybox';

function logFancyboxState(label, current) {
    console.log(label, (current !== undefined) ? JSON.parse(JSON.stringify(current, (k, v) => (v && k.startsWith('$')) ? v.prop('outerHTML') : v)) : current);
}

const HLS_MEDIA_MIME_TYPE = 'video/mp4; codecs="avc1.64001f, mp4a.40.2"';

function getHlsStreamUrl(src) {
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

// TODO: The `thumbs` button loads all of the thumbnails on the page, work out how to make it lazy.

$.fancybox.defaults = {
    ...$.fancybox.defaults,
    onInit: function(instance) {
        console.log('onInit');

        // Disable key handlers as they clash with system commands.
        $(document).off('afterKeydown.fb');

        // Avoid stripping out all but the first child of ajax-loaded HTML, add white-space CSS for plain text.
        const originalSetContent = instance.setContent;
        instance.setContent = function(slide, content) {
            if (typeof content !== 'string') {
                return originalSetContent.call(this, slide, content);
            }

            const contentKind = slide.opts.$orig?.attr('data-kind');

            if (contentKind === 'text') {
                slide.opts.touch = false;

                content = $('<div class="prose" style="white-space: pre-wrap;"></div>')
                    .text(content)
                    .wrap('<div></div>')
                    .parent();
            } else if (contentKind === 'html') {
                slide.opts.touch = false;

                // TODO: Like the Readme rendering, this requires trusted HTML.
                //       We'd ideally like to filter it to safe Markdown elements and no attributes.
                content = $('<div class="prose"></div>')
                    .html(content)
                    .wrap('<div></div>')
                    .parent();

                if (content.find('style').remove().length > 0) {
                    console.warn('HTML content contained style tags, they will be ignored.');
                }
            } else if (contentKind === 'audio') {
                slide.type = 'ajax';
                slide.contentType = 'html';
                content = $('<audio controls autoplay style="width: 100%;"></audio>')
                    .attr('src', slide.src)
                    .wrap('<div style="background: none; width: 60%"></div>')
                    .parent();
            }

            return originalSetContent.call(this, slide, content);
        };
    },
    beforeLoad: function(instance, current) {
        logFancyboxState('beforeLoad', current);

        if (current.type === 'video') {
            const srcType = current.opts.$orig?.attr('data-mime');

            current.opts.video.tpl = current.opts.video.tpl
                .replace('{{src_type}}', srcType ? `type="${srcType.replaceAll('"', '&quot;')}"` : '');
        }
    },
    afterLoad: function(instance, current) {
        logFancyboxState('afterLoad', current);

        // const pixelRatio = window.devicePixelRatio || 1;
        // if (pixelRatio > 1.5) {
        //     current.width  = current.width  / pixelRatio;
        //     current.height = current.height / pixelRatio;
        // }
    },
    afterShow: function(instance, current) {
        logFancyboxState('afterShow', current);

        if (current.type === 'video') {
            const element = current.$slide.find('video').get(0);

            let fallbackStarted = false;
            let slideReset = false;
            let hlsInstance = null;

            const startFallback = function() {
                if (fallbackStarted) {
                    return;
                }

                fallbackStarted = true;

                startHlsVideo(element, getHlsStreamUrl(current.src)).then(hls => {
                    if (!hls) {
                        return;
                    }

                    if (slideReset) {
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

            current.$slide.on('onReset', function () {
                console.log('onReset');
                slideReset = true;
                element.removeEventListener('error', onSourceError, true);
                hlsInstance?.destroy();
                hlsInstance = null;
            });

            if (element.networkState === HTMLMediaElement.NETWORK_NO_SOURCE) {
                startFallback();
            } else {
                element.addEventListener('error', onSourceError, true);
            }
        }
    },
    share: {
        ...$.fancybox.defaults.share,
        url: function(instance, item) {
             // console.log('share', item);

            // TODO: If we enable this button, we'll want to hook it into our own share function for permissions.
            return item.src;
        },
        tpl: `<div class="fancybox-share"><input class="fancybox-share__input" type="text" value="{{url_raw}}" onclick="select()" /></div>`,
    },
    caption: function(instance, item) {
        // This is pre-calculated for all items on init
        // logFancyboxState('caption', item);

        // TODO
    },
    buttons: ['download', 'share', 'thumbs', 'close'],
    smallBtn: false,
    wheel: false,
    defaultType: 'iframe',
    video: {
      tpl:
        '<video class="fancybox-video" controls autoplay poster="{{poster}}">' +
        '  <source src="{{src}}" {{src_type}} />' +
        '  <div style="height: 100%; color: white; display: flex; justify-content: center; align-items: center; text-align: center;"><span>Sorry, your browser doesn\'t support streaming this video, <br><a style="color: white; text-decoration: underline;" href="{{src}}" download>download</a> and watch with your favorite video player!</span></div>' +
        '</video>',
      format: '',
      autoStart: false,
    },
};

document.addEventListener('DOMContentLoaded', function() {
    /**
     * @param {HTMLImageElement} thumbnailImage
     */
    function initFilmstrip(thumbnailImage) {
        /** @type {HTMLImageElement|null} */
        let filmstripImage = null;

        const container = thumbnailImage.parentNode;

        // This needs to match GenerateMediaFileFilmstripJob
        const filmstripWidth = 10;
        const frameCount = filmstripWidth * 10;

        container.addEventListener('mouseenter', function() {
            filmstripImage = document.createElement('img');
            filmstripImage.src = thumbnailImage.getAttribute('data-filmstrip');
            filmstripImage.style.pointerEvents = 'none';
            filmstripImage.style.position = 'absolute';
            filmstripImage.style.top = '0';
            filmstripImage.style.left = '0';
            filmstripImage.style.width = `${thumbnailImage.offsetWidth * 10}px`;
            filmstripImage.style.height = `${thumbnailImage.offsetHeight * 10}px`;
            filmstripImage.style.maxWidth = 'none';

            let wrapper = document.createElement('div');
            wrapper.style.position = 'absolute';
            wrapper.style.overflow = 'hidden';
            wrapper.style.backgroundColor = 'black';
            wrapper.style.top = `${thumbnailImage.offsetTop}px`;
            wrapper.style.left = `${thumbnailImage.offsetLeft}px`;
            wrapper.style.width = `${thumbnailImage.offsetWidth}px`;
            wrapper.style.height = `${thumbnailImage.offsetHeight}px`;

            let loader = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            loader.classList = 'animate-spin';
            loader.setAttribute('viewBox', '0 0 24 24');
            loader.innerHTML = `
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            `;
            loader.style.color = 'white';
            loader.style.fill = 'none';
            loader.style.position = 'absolute';
            loader.style.top = 'calc(50% - 12px)';
            loader.style.left = 'calc(50% - 12px)';
            loader.style.width = '24px';
            loader.style.height = '24px';
            loader.style.pointerEvents = 'none';

            let thisFilmstripImage = filmstripImage;
            filmstripImage.addEventListener('load', function onFilmstripLoad() {
                thisFilmstripImage.removeEventListener('load', onFilmstripLoad);
                loader.remove();
            });

            wrapper.appendChild(loader);
            wrapper.appendChild(filmstripImage);
            container.appendChild(wrapper);
        });

        container.addEventListener('mouseleave', function() {
            filmstripImage?.parentElement?.remove();
            filmstripImage = null;
        });

        container.addEventListener('mousemove', function(e) {
            if (!filmstripImage) {
                return;
            }

            const percentage = e.offsetX / e.currentTarget.clientWidth;
            const frameIndex = Math.floor(frameCount * percentage);

            const frameX = frameIndex % filmstripWidth;
            const frameY = Math.floor(frameIndex / filmstripWidth);

            filmstripImage.style.top = `-${frameY * thumbnailImage.offsetHeight}px`;
            filmstripImage.style.left = `-${frameX * thumbnailImage.offsetWidth}px`;
        });
    }

    document.querySelectorAll('img[data-filmstrip]').forEach(function (thumbnailImage) {
        if (thumbnailImage.complete) {
            initFilmstrip(thumbnailImage);
        } else {
            thumbnailImage.addEventListener('load', function onLoad() {
                thumbnailImage.removeEventListener('load', onLoad);
                initFilmstrip(thumbnailImage);
            });
        }
    });
});
