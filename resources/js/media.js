// #5 How to disable touch gestures/swiping
// When you want to make your content selectable or clickable, you have two options:
// * disable touch gestures completely by setting touch:false
// * add data-selectable="true" attribute to your html element

import '../css/media.css';

import $ from './jquery-global.js';
import '@fancyapps/fancybox';

function logFancyboxState(label, current) {
    console.log(label, (current !== undefined) ? JSON.parse(JSON.stringify(current, (k, v) => k.startsWith('$') ? v.prop('outerHTML') : v)) : current);
}

async function startHlsVideo(element) {
    const { Hls } = await import('hls.js/light');

    if (!Hls.isMSESupported()) {
        return;
    }

    const hlsMimeTypePattern = /^application\/vnd\.apple\.mpegurl(?=;|$)/;

    let hlsSource = null;
    const sources = element.getElementsByTagName('source');
    for (const source of sources) {
        if (!source.type.match(hlsMimeTypePattern)) {
            continue;
        }

        const mediaMimeType = source.type.replace(hlsMimeTypePattern, 'video/mp4');
        if (!Hls.getMediaSource().isTypeSupported(mediaMimeType)) {
            continue;
        }

        hlsSource = source;
        break;
    }

    if (!hlsSource) {
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

    hls.loadSource(hlsSource.src);
    hls.attachMedia(element);

    return hls;
}

$.fancybox.defaults = {
    ...$.fancybox.defaults,
    buttons: ['download', 'share', 'thumbs', 'close'],
    onInit: function(instance) {
        console.log('onInit');
    },
    beforeLoad: function(instance, current) {
        logFancyboxState('beforeLoad', current);

        if (current.type === 'video') {
            const src = new URL(current.src, window.location.href);

            // TODO: Use a named route somehow, account for the base path.
            src.pathname = '/_stream' + src.pathname + '.m3u8';

            const srcType = current.$thumb.attr('data-mime');

            current.opts.video.tpl = current.opts.video.tpl
                .replace('{{src_type}}', srcType ? `type="${srcType.replaceAll('"', '&quot;')}"` : '')
                .replace('{{src_stream}}', src.href);
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

            // TODO: See if we need an error event listener if we're not in this state already.
            if (element.networkState === 3) {
                startHlsVideo(element).then(hls => {
                    if (!hls) {
                        return;
                    }

                    current.$slide.on('onReset', function () {
                        console.log('onReset');
                        hls.destroy();
                    });
                });
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
    // TODO: This is copied from the old code and avoids everything being an image (actual images seemingly remain
    //       images), but we probably want to have the PHP side inform us of the actual type per-link. Even in that
    //       case, using 'video' here as the default might make sense to reduce HTML size (as apparently we'd only
    //       need to specify things that are neither images nor videos).
    // TODO: The automatic type discovery code is very crude.
    defaultType: 'video',
    video: {
      tpl:
        '<video class="fancybox-video" controls autoplay poster="{{poster}}">' +
        '  <source src="{{src}}" {{src_type}} />' +
        '  <source src="{{src_stream}}" type="application/vnd.apple.mpegurl; codecs=&quot;avc1.64001f, mp4a.40.2&quot;" />' +
        '  <div style="height: 100%; color: white; display: flex; justify-content: center; align-items: center; text-align: center;"><span>Sorry, your browser doesn\'t support streaming this video, <br><a style="color: white; text-decoration: underline;" href="{{src}}" download>download</a> and watch with your favorite video player!</span></div>' +
        '</video>',
      format: '',
      autoStart: false,
    },
};
