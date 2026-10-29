import { onBeforeUnmount, watch } from 'vue';

import { nextUrl } from '@/lib/urlState';

const DEBOUNCE_MS = 300;

/**
 * Mirrors a definition into the query string of the page. `encode` returns the query string (without `?`) and is
 * watched, so every change of what it reads is written, debounced. Harmless when the browser refuses it, keeps the
 * fragment, and never fires after the page is left (a late replaceState would rewrite the URL of the NEXT page).
 * Inertia keeps its page object in history.state: it is passed through untouched, only the URL changes.
 *
 * @param {() => string} encode
 */
export function useUrlState(encode) {
    let timer = null;

    watch(encode, (query) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            try {
                const next = nextUrl(window.location, query);

                if (next !== null) {
                    window.history.replaceState(window.history.state, '', next);
                }
            } catch {
                // a sandbox or a browser limit: the link just does not follow the form
            }
        }, DEBOUNCE_MS);
    });

    onBeforeUnmount(() => clearTimeout(timer));
}
