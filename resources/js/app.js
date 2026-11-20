import './bootstrap';

import '../css/app.css';

import Alpine from 'alpinejs';
import { copyInput, shareDialog } from './share.js';

import 'clockwork-browser/metrics';
import 'clockwork-browser/toolbar';

Alpine.data('copyInput', copyInput);
Alpine.data('shareDialog', shareDialog);

Alpine.store('searchModal', {
    open: false,

    toggle() {
        this.open = !this.open
    }
})

// Slides the search box away while scrolling down through the results and brings it back when scrolling up, on
// compact screens, where the component's root is the scroller and the box is a sticky header inside it. Like Safari's
// bars, the box tracks the scroll distance 1:1 rather than animating on a threshold, and once scrolling stops (and the
// finger is lifted) a part-hidden box snaps the rest of the way in the direction it was last moving.
Alpine.data('collapsingSearchHeader', () => ({
    // How far the header is moved up. Never more than its height, nor than the scroll position, so near the top of
    // the results it simply sits in its place in the flow.
    offset: 0,
    lastScrollTop: 0,
    direction: 0,
    touching: false,
    snapTimer: null,

    // Ignores rubber-banding past either end, which reports scroll positions out of range on iOS, so bouncing at the
    // bottom doesn't count as scrolling up.
    scrollTop() {
        const scroller = this.$root;

        return Math.max(0, Math.min(scroller.scrollTop, scroller.scrollHeight - scroller.clientHeight));
    },

    maxOffset() {
        return Math.min(this.$refs.header.offsetHeight, this.scrollTop());
    },

    apply(animate = false) {
        const header = this.$refs.header;

        header.style.transition = animate ? 'transform 200ms ease-out' : '';
        header.style.transform = this.offset > 0 ? `translateY(${-this.offset}px)` : '';
    },

    // Picks up from wherever a snap animation has got to, so touching the results mid-snap doesn't make the box jump.
    stopAnimation() {
        const header = this.$refs.header;

        if (!header.style.transition) {
            return;
        }

        this.offset = -new DOMMatrixReadOnly(getComputedStyle(header).transform).m42;
        this.apply();
    },

    onScroll() {
        const scrollTop = this.scrollTop();
        const delta = scrollTop - this.lastScrollTop;
        this.lastScrollTop = scrollTop;

        if (delta === 0) {
            return;
        }

        this.stopAnimation();
        this.direction = Math.sign(delta);
        this.offset = Math.max(0, Math.min(this.offset + delta, this.maxOffset()));
        this.apply();
        this.scheduleSnap();
    },

    // There's no reliable scrollend in Safari, so this waits for the scroll events to stop. Momentum scrolling sends
    // one every frame, so it only fires once that has finished too.
    scheduleSnap() {
        clearTimeout(this.snapTimer);

        if (!this.touching) {
            this.snapTimer = setTimeout(() => this.snap(), 120);
        }
    },

    snap() {
        const maxOffset = this.maxOffset();

        if (this.offset > 0 && this.offset < maxOffset) {
            this.offset = this.direction > 0 ? maxOffset : 0;
            this.apply(true);
        }
    },

    show() {
        clearTimeout(this.snapTimer);

        if (this.offset > 0) {
            this.offset = 0;
            this.apply(true);
        }
    },

    onTouchStart() {
        this.touching = true;
        clearTimeout(this.snapTimer);
        this.stopAnimation();
    },

    onTouchEnd(event) {
        this.touching = event.touches.length > 0;
        this.scheduleSnap();
    },

    // Keeps the offset in range when the header changes height, and puts the header back when the screen becomes
    // spacious, where the root doesn't scroll.
    onResize() {
        this.offset = Math.min(this.offset, this.maxOffset());
        this.lastScrollTop = this.scrollTop();
        this.apply();
    },
}))

window.performSearch = function performSearch(event) {
    const formData = new FormData(event.target);

    const index = formData.get('index');
    formData.delete('index');

    const query = formData.get('query');
    formData.delete('query');

    const expectedEpoch = ++this.$data.epoch;

    if (query.length === 0) {
        this.$data.loading = false;
        this.$data.overheated = false;
        this.$data.error = null;
        this.$data.results = [];

        return;
    }

    this.$data.loading = true;

    const searchParams = new URLSearchParams(formData.entries());

    fetch(`${this.$data[index]}/${encodeURIComponent(query)}?${searchParams}`, {
        method: 'GET',
    }).then(response => {
        // Guests' searches are throttled (see AppServiceProvider), so say when it's worth trying again.
        if (response.status === 429) {
            const retryAfter = parseInt(response.headers.get('Retry-After') ?? '', 10);
            const error = new Error('Search throttled');
            error.userMessage = Number.isFinite(retryAfter) && retryAfter > 0
                ? `Too many searches, try again in ${retryAfter} second${retryAfter === 1 ? '' : 's'}.`
                : 'Too many searches, try again in a minute.';

            throw error;
        }

        if (!response.ok) {
            throw new Error(`Search failed: ${response.status}`);
        }

        return response.json();
    }).then(response => {
        // Skip setting the results if another request has happened in the meantime.
        if (this.$data.epoch !== expectedEpoch) {
            return;
        }

        this.$data.overheated = response.overheated;
        this.$data.error = null;
        this.$data.results = response.results;
    }).catch(error => {
        console.error(error);

        if (this.$data.epoch !== expectedEpoch) {
            return;
        }

        this.$data.overheated = false;
        this.$data.error = error.userMessage ?? 'Search failed, please try again.';
        this.$data.results = [];
    }).finally(() => {
        // Only the latest request owns the indicator, so an older one finishing late doesn't hide it.
        if (this.$data.epoch === expectedEpoch) {
            this.$data.loading = false;
        }
    });
}

window.onSearchIndexChange = function onSearchIndexChange(event) {
    let isContentSearch = event.target.value === 'content';

    let kindCheckboxes = event.target.form.elements.namedItem('kind[]');
    for (let checkbox of kindCheckboxes) {
        if (isContentSearch) {
            checkbox.disabled = checkbox.dataset.contentSearchable === '0';
        } else {
            checkbox.disabled = false;
        }
    }
}

window.Alpine = Alpine;

Alpine.start();
