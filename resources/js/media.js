// #5 How to disable touch gestures/swiping
// When you want to make your content selectable or clickable, you have two options:
// * disable touch gestures completely by setting touch:false
// * add data-selectable="true" attribute to your html element

import '../css/media.css';

import $ from './jquery-global.js';
import '@fancyapps/fancybox';
import { playVideoWithFallback, renderTextContent } from './player.js';

/**
 * The info page for a slide's file, with the video's current position when it's playing (so it carries on there).
 */
function getInfoUrl(slide, withCurrentTime = false) {
    const infoUrl = slide.opts.$orig?.attr('data-info-url');
    if (!infoUrl) {
        return null;
    }

    const url = new URL(infoUrl, window.location.href);

    const video = (withCurrentTime && slide.type === 'video') ? slide.$slide?.find('video').get(0) : null;
    if (video && video.currentTime > 0) {
        url.hash = 't=' + Math.floor(video.currentTime);
    }

    return url.href;
}

/**
 * The URL as reached from outside: on the local network we're redirected to a `-local` hostname, which won't resolve
 * for anyone else (see RedirectLocalAccess::getExternalHostIfLocal()).
 */
function toPublicUrl(href) {
    const url = new URL(href);
    url.hostname = url.hostname.replace(/^([^.]+)-local\./, '$1.');

    return url.href;
}

function logFancyboxState(label, current) {
    console.log(label, (current !== undefined) ? JSON.parse(JSON.stringify(current, (k, v) => (v && k.startsWith('$')) ? v.prop('outerHTML') : v)) : current);
}

// TODO: The `thumbs` button loads all of the thumbnails on the page, work out how to make it lazy.

$.fancybox.defaults = {
    ...$.fancybox.defaults,
    onInit: function(instance) {
        console.log('onInit');

        // Disable key handlers as they clash with system commands.
        $(document).off('afterKeydown.fb');

        // The container isn't fixed so Safari keeps its toolbar see-through (see media.css), so place it over the
        // viewport. The page doesn't scroll while it's open. Its height: 100% would be the initial viewport, which
        // excludes Safari's bars even once they've been hidden by scrolling, so size it to what's visible instead.
        const fitToViewport = function() {
            instance.$refs.container.css({ top: window.scrollY + 'px', height: window.innerHeight + 'px' });
        };

        fitToViewport();
        $(window).on('resize.glanceFit', fitToViewport);

        // Avoid stripping out all but the first child of ajax-loaded HTML, add white-space CSS for plain text.
        const originalSetContent = instance.setContent;
        instance.setContent = function(slide, content) {
            if (typeof content !== 'string') {
                return originalSetContent.call(this, slide, content);
            }

            const contentKind = slide.opts.$orig?.attr('data-kind');

            if (contentKind === 'text' || contentKind === 'html') {
                slide.opts.touch = false;

                content = renderTextContent(contentKind, content);
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
    afterClose: function() {
        $(window).off('resize.glanceFit');
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
            const destroy = playVideoWithFallback(current.$slide.find('video').get(0), current.src);

            current.$slide.on('onReset', function () {
                console.log('onReset');
                destroy();
            });
        }
    },
    beforeShow: function(instance, current) {
        // Like upstream's download button, the info button is a link to the current slide's file, so it can be opened
        // in a new tab. Its position in a playing video is added when it's clicked.
        const infoUrl = getInfoUrl(current);

        instance.$refs.container.find('[data-fancybox-info]')
            .attr('href', infoUrl ?? 'javascript:;')
            .toggle(infoUrl !== null);
    },
    btnTpl: {
        ...$.fancybox.defaults.btnTpl,
        info: '<a data-fancybox-info class="fancybox-button fancybox-button--info" title="Info" href="javascript:;">' +
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 1.5a7.5 7.5 0 1 1 0 15 7.5 7.5 0 0 1 0-15ZM11.1 10.5h1.8v6h-1.8ZM12 6.9a1.1 1.1 0 1 1 0 2.2 1.1 1.1 0 0 1 0-2.2Z"/></svg>' +
            '</a>',
    },
    share: {
        ...$.fancybox.defaults.share,
        url: function(instance, item) {
            // TODO: This is only a link for users who already have access, we'll want our own share function for
            //       public links (see the project plan).
            return toPublicUrl(getInfoUrl(item, true) ?? new URL(item.src, window.location.href).href);
        },
        tpl: `<div class="fancybox-share"><input class="fancybox-share__input" type="text" value="{{url_raw}}" onclick="select()" /></div>`,
    },
    caption: function(instance, item) {
        // This is pre-calculated for all items on init
        // logFancyboxState('caption', item);

        // TODO
    },
    buttons: ['info', 'download', 'share', 'thumbs', 'close'],
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

// Adds the playing video's position to the info link as it's followed. On mousedown too, which comes before the
// browser reads the link for a middle-click or context menu.
$(document).on('mousedown click', '[data-fancybox-info]', function() {
    const instance = $.fancybox.getInstance();
    const infoUrl = instance?.current ? getInfoUrl(instance.current, true) : null;

    if (infoUrl) {
        this.href = infoUrl;
    }
});

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
