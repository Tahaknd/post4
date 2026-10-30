import { onBeforeUnmount, onMounted, ref } from 'vue';

/** A clock ref that moves every `intervalMs` (default half a minute), for "5 minutes ago" labels. One per page, passed down. */
export function useNow(intervalMs = 30000) {
    const now = ref(Date.now());
    let timer = null;

    onMounted(() => {
        now.value = Date.now();
        timer = setInterval(() => (now.value = Date.now()), intervalMs);
    });
    onBeforeUnmount(() => clearInterval(timer));

    return now;
}
