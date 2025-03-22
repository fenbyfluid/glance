// #5 How to disable touch gestures/swiping
// When you want to make your content selectable or clickable, you have two options:
// * disable touch gestures completely by setting touch:false
// * add data-selectable="true" attribute to your html element

$.fancybox.defaults = {
    ...$.fancybox.defaults,
    buttons: ['download', 'share', 'thumbs', 'close'],
    afterLoad: function(instance, current) {
        console.log('afterLoad', current);

        // const pixelRatio = window.devicePixelRatio || 1;
        // if (pixelRatio > 1.5) {
        //     current.width  = current.width  / pixelRatio;
        //     current.height = current.height / pixelRatio;
        // }
    },
    share: {
        ...$.fancybox.defaults.share,
        url: function(instance, item) {
            console.log('share', item);

            if (item.type === 'inline' && item.contentType === 'video') {
                return item.$content.find('source:first').attr('src');
            }

            return item.src;
        },
        tpl: `<div class="fancybox-share"><input class="fancybox-share__input" type="text" value="{{url_raw}}" onclick="select()" /></div>`,
    },
    caption: function(instance, item) {
        // TODO
    },
};
