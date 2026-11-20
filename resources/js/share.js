// The share dialog (shared-links/partials/dialog.blade.php): the link for people who already have access, and the
// file's or directory's public links. Opened by an `open-share-dialog` window event with `{ path, internalUrl, name? }`,
// from the lightbox (media.js), the info page (info.js) and a directory's navigation bar.

/**
 * The URL as reached from outside: on the local network we're redirected to a `-local` hostname, which won't resolve
 * for anyone else (see RedirectLocalAccess::getExternalHostIfLocal()). Public links get this done by the server.
 */
function toPublicUrl(href) {
    const url = new URL(href, window.location.href);
    url.hostname = url.hostname.replace(/^([^.]+)-local\./, '$1.');

    return url.href;
}

const relativeTimeUnits = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
];

/**
 * A read-only text box with a copy button inside it (components/copy-input.blade.php), used for links in the share
 * dialog and the shared link lists.
 */
export function copyInput() {
    return {
        copied: false,
        copiedTimer: null,

        async copy() {
            const text = this.$refs.input.value;

            try {
                await navigator.clipboard.writeText(text);
            } catch (error) {
                // The Clipboard API needs a secure context. The fallback's textarea goes inside this component, as
                // everything outside an open modal dialog is inert.
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                this.$root.append(textarea);
                textarea.select();

                const copied = document.execCommand('copy');
                textarea.remove();

                if (!copied) {
                    console.error(error);

                    return;
                }
            }

            this.copied = true;
            clearTimeout(this.copiedTimer);
            this.copiedTimer = setTimeout(() => this.copied = false, 2000);
        },
    };
}

export function shareDialog(linksUrl, defaultExpiresIn, canCreateLinks) {
    return {
        path: null,
        name: '',
        internalUrl: '',
        links: [],
        loading: false,
        error: null,
        note: '',
        expiresIn: defaultExpiresIn,
        creating: false,

        open({ path, internalUrl, name }) {
            this.path = path;
            this.name = name ?? path.split('/').pop();
            this.internalUrl = toPublicUrl(internalUrl);
            this.links = [];
            this.error = null;
            this.note = '';
            this.expiresIn = defaultExpiresIn;

            this.$root.showModal();

            // Without the permission to share, the dialog only has the link for people with access.
            if (canCreateLinks) {
                this.load();
            }
        },

        async request(url, options = {}) {
            const headers = {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            };
            if (options.body !== undefined) {
                headers['Content-Type'] = 'application/json';
            }

            const response = await fetch(url, { credentials: 'same-origin', ...options, headers });
            if (!response.ok) {
                throw new Error(`Request failed: ${response.status}`);
            }

            return (response.status === 204) ? null : response.json();
        },

        async load() {
            const path = this.path;
            this.loading = true;

            try {
                const data = await this.request(`${linksUrl}?${new URLSearchParams({ path })}`);

                // Skip if the dialog has been opened for another file in the meantime.
                if (this.path === path) {
                    this.links = data.links;
                }
            } catch (error) {
                console.error(error);

                if (this.path === path) {
                    this.error = 'The public links couldn\'t be loaded.';
                }
            } finally {
                if (this.path === path) {
                    this.loading = false;
                }
            }
        },

        async create() {
            this.creating = true;
            this.error = null;

            try {
                const data = await this.request(linksUrl, {
                    method: 'POST',
                    body: JSON.stringify({ path: this.path, note: this.note || null, expires_in: Number(this.expiresIn) }),
                });

                this.links.unshift(data.link);
                this.note = '';
            } catch (error) {
                console.error(error);

                this.error = 'The link couldn\'t be created.';
            } finally {
                this.creating = false;
            }
        },

        async saveNote(link) {
            this.error = null;

            try {
                const data = await this.request(link.apiUrl, {
                    method: 'PATCH',
                    body: JSON.stringify({ note: link.note || null }),
                });

                Object.assign(link, data.link);
            } catch (error) {
                console.error(error);

                this.error = 'The note couldn\'t be saved.';
            }
        },

        async revoke(link) {
            if (!window.confirm('Revoke this link? Anyone using it will lose access.')) {
                return;
            }

            this.error = null;

            try {
                await this.request(link.apiUrl, { method: 'DELETE' });

                this.links = this.links.filter(other => other.id !== link.id);
            } catch (error) {
                console.error(error);

                this.error = 'The link couldn\'t be revoked.';
            }
        },

        formatRelative(isoDate) {
            const seconds = (new Date(isoDate).getTime() - Date.now()) / 1000;
            const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

            for (const [unit, unitSeconds] of relativeTimeUnits) {
                if (Math.abs(seconds) >= unitSeconds) {
                    return format.format(Math.round(seconds / unitSeconds), unit);
                }
            }

            return format.format(Math.round(seconds / 60), 'minute');
        },
    };
}
