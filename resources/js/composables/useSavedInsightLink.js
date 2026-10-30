import { computed, onMounted, ref, shallowRef } from 'vue';

import { getSavedInsight, updateSavedInsight } from '@/lib/dashboardsApi';
import { buildUpdateBody } from '@/lib/saveInsight';
import { canonicalToQuery, savedIdOf, withSaved, withoutSaved } from '@/lib/savedInsightLinks';

/**
 * "This page edits a saved insight": the `saved=<uuid>` key of the address. The insight is loaded once the page is mounted; when
 * the address carried no definition of its own (`ownDefinition` false: the page was opened with `saved` alone) its stored
 * definition is turned into the page's own query string (lib/savedInsightLinks) and handed to `hydrate`, which reads it with
 * the page's decodeDefinition and fills the form. An insight that needs review is hydrated as far as it can be read.
 *
 * `status`: none (a new insight), loading, ready (editing `saved`), missing (no such insight), mismatch (it is of another
 * type), failed (could not be loaded). Only `ready` keeps the key in the address.
 *
 * @param {object} options
 * @param {string} options.type the insight type of the page
 * @param {string} options.base the collection URL of the saved insight API (server-built)
 * @param {boolean} options.ownDefinition whether the address already described a definition
 * @param {(query: string) => void} options.hydrate fills the form from a query string of the page's own keys
 */
export function useSavedInsightLink({ type, base, ownDefinition, hydrate }) {
    const initial = savedIdOf(window.location.search);
    const id = ref(initial);
    const saved = shallowRef(null);
    const status = ref(initial === null ? 'none' : 'loading');
    const message = ref('');

    onMounted(async () => {
        if (initial === null) {
            return;
        }

        const answer = await getSavedInsight(base, initial);
        if (!answer.ok) {
            status.value = answer.kind === 'not_found' ? 'missing' : 'failed';
            message.value = answer.kind === 'not_found' ? 'The saved insight in this link no longer exists. You can still save what you build as a new one.' : answer.message;
            id.value = answer.kind === 'not_found' ? null : id.value;

            return;
        }
        if (answer.data.type !== type) {
            status.value = 'mismatch';
            message.value = 'The saved insight in this link is of another type. You can save what you build here as a new one.';
            id.value = null;

            return;
        }

        saved.value = answer.data;
        status.value = 'ready';
        if (!ownDefinition) {
            const query = canonicalToQuery(type, answer.data);
            if (query !== null) {
                hydrate(query);
            }
        }
    });

    /** Keeps `saved` in a query string the page writes to its address while an insight is being edited. */
    const encode = (query) => withSaved(query, status.value === 'ready' || status.value === 'loading' ? id.value : null);

    /** Replaces the definition of the insight on the server (also how one that needs review is repaired). */
    async function update(query) {
        const answer = await updateSavedInsight(base, id.value, buildUpdateBody(query));
        if (answer.ok) {
            saved.value = answer.data;
        }

        return answer;
    }

    /** From now on the page edits this insight (just saved from here). */
    function adopt(detail) {
        id.value = detail.id;
        saved.value = detail;
        status.value = 'ready';
    }

    return { id, saved, status, message, editing: computed(() => status.value === 'ready' && saved.value !== null), encode, update, adopt };
}

export { withoutSaved };
