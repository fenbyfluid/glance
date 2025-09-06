import './bootstrap';

import '../css/app.css';

import Alpine from 'alpinejs';

import 'clockwork-browser/metrics';
import 'clockwork-browser/toolbar';

Alpine.store('searchModal', {
    open: false,

    toggle() {
        this.open = !this.open
    }
})

window.performSearch = function performSearch(event) {
    const formData = new FormData(event.target);

    const index = formData.get('index');
    formData.delete('index');

    const query = formData.get('query');
    formData.delete('query');

    const expectedEpoch = ++this.$data.epoch;

    if (query.length === 0) {
        this.$data.overheated = false;
        this.$data.results = [];

        return;
    }

    const searchParams = new URLSearchParams(formData.entries());

    fetch(`${this.$data[index]}/${encodeURIComponent(query)}?${searchParams}`, {
        method: 'GET',
    }).then(response => {
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
        this.$data.results = response.results;
    }).catch(error => {
        console.error(error);

        if (this.$data.epoch === expectedEpoch) {
            return;
        }

        this.$data.overheated = false;
        this.$data.results = [];
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
