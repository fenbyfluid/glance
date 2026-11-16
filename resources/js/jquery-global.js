import jQuery from 'jquery';

// Fancybox requires a window-global jQuery reference.
window.jQuery = jQuery;

// Fancybox 3 (unmaintained) still calls utilities removed in jQuery 4. These match jQuery 3's behaviour.
// TODO: Drop these if we ever replace Fancybox 3; nothing else here uses them.
const typeTags = ['boolean', 'number', 'string', 'function', 'array', 'date', 'regexp', 'object', 'error', 'symbol'];

jQuery.type ??= function(obj) {
    if (obj == null) {
        return String(obj);
    }

    if (typeof obj !== 'object' && typeof obj !== 'function') {
        return typeof obj;
    }

    const tag = Object.prototype.toString.call(obj).slice(8, -1).toLowerCase();

    return typeTags.includes(tag) ? tag : 'object';
};

jQuery.isArray ??= Array.isArray;

jQuery.isFunction ??= function(obj) {
    return typeof obj === 'function' && typeof obj.nodeType !== 'number' && typeof obj.item !== 'function';
};

jQuery.isNumeric ??= function(obj) {
    const type = jQuery.type(obj);

    return (type === 'number' || type === 'string') && !isNaN(obj - parseFloat(obj));
};

jQuery.trim ??= function(text) {
    return text == null ? '' : String(text).trim();
};

export default jQuery;
