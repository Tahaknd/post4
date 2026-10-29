<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import CopyButton from '@/Components/CopyButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextField from '@/Components/TextField.vue';
import { useFormFocus } from '@/composables/useFormFocus';

// The one page that carries the project's API key (`api_key`): it is only ever sent to someone who may open this
// project, and it is not a shared prop, not flashed and not in any list. `urls` are built by the server.
const props = defineProps({
    project: { type: Object, required: true },
    api_key: { type: String, required: true },
    api_host: { type: String, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const MASK = 'phc_••••••••••••••••••••';

const revealed = ref(false);
const shownKey = computed(() => (revealed.value ? props.api_key : MASK));

// The snippets are shown masked until the key is revealed, but the copy buttons always copy the real thing.
const jsSnippet = (key) => `posthog.init('${key}', { api_host: '${props.api_host}' })`;
const curlSnippet = (key) =>
    `curl -X POST '${props.api_host}/capture' \\\n  -H 'Content-Type: application/json' \\\n  -d '{"api_key":"${key}","event":"test_event","distinct_id":"user-1"}'`;

const createdAt = computed(() =>
    props.project.created_at ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(props.project.created_at)) : '',
);

// Replace the key.
const rotateOpen = ref(false);
const rotating = ref(false);

function rotate() {
    if (rotating.value) {
        return;
    }

    rotating.value = true;
    router.put(
        props.urls.rotate_key,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                rotateOpen.value = false;
                revealed.value = false;
            },
            onFinish: () => (rotating.value = false),
        },
    );
}

// Delete the project.
const deleteOpen = ref(false);
const deleteForm = useForm({ confirm_name: '' });
const { formRef: deleteFormRef } = useFormFocus(deleteForm);

function openDelete() {
    deleteForm.reset();
    deleteForm.clearErrors();
    deleteOpen.value = true;
}

function destroy() {
    if (deleteForm.processing) {
        return;
    }

    deleteForm.delete(props.urls.destroy, { preserveScroll: true });
}
</script>

<template>
    <Head :title="project.name" />

    <div class="max-w-3xl space-y-10">
        <header class="space-y-1">
            <p class="text-sm"><Link :href="urls.index" class="text-accent hover:text-accent-hover">All projects</Link></p>
            <h1 class="text-2xl font-semibold tracking-tight">{{ project.name }}</h1>
            <p class="text-sm text-muted">Timezone {{ project.timezone }} · created {{ createdAt }}</p>
        </header>

        <section aria-labelledby="insights-heading" class="space-y-3">
            <h2 id="insights-heading" class="text-lg font-semibold tracking-tight">Insights</h2>
            <p class="text-sm text-muted">See how often your events happen over time, and where people drop off between steps.</p>
            <div class="flex flex-wrap gap-3">
                <Link
                    :href="urls.trends"
                    class="inline-flex items-center justify-center rounded-md bg-accent px-4 py-2 font-medium text-accent-fg hover:bg-accent-hover"
                >
                    Open Trends
                </Link>
                <Link
                    :href="urls.funnel"
                    class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-4 py-2 font-medium text-fg hover:bg-canvas"
                >
                    Open Funnel
                </Link>
            </div>
        </section>

        <section aria-labelledby="key-heading" class="space-y-3">
            <h2 id="key-heading" class="text-lg font-semibold tracking-tight">API key</h2>
            <p class="text-sm text-muted">Events must carry this key. Keep it out of public repositories.</p>
            <p class="break-all rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm" data-testid="api-key">{{ shownKey }}</p>
            <div class="flex flex-wrap items-center gap-3">
                <!-- A constant name with aria-pressed: the state is announced once ("Reveal key, toggle button, pressed"),
                     not twice by a label that flips as well. -->
                <SecondaryButton :aria-pressed="revealed ? 'true' : 'false'" @click="revealed = !revealed">Reveal key</SecondaryButton>
                <CopyButton :text="api_key" label="Copy key" />
            </div>
        </section>

        <section aria-labelledby="setup-heading" class="space-y-6">
            <h2 id="setup-heading" class="text-lg font-semibold tracking-tight">Send your first event</h2>

            <div class="space-y-2">
                <h3 class="font-medium">JavaScript (posthog-js)</h3>
                <pre
                    tabindex="0"
                    aria-label="posthog-js setup snippet"
                    class="overflow-x-auto rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm"
                ><code>{{ jsSnippet(shownKey) }}</code></pre>
                <CopyButton :text="jsSnippet(api_key)" label="Copy snippet" />
            </div>

            <div class="space-y-2">
                <h3 class="font-medium">Try it in this browser</h3>
                <p class="max-w-prose text-sm text-muted">Load the real posthog-js on a test page and send events with this project's key.</p>
                <Link
                    :href="urls.demo"
                    class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-4 py-2 font-medium text-fg hover:bg-canvas"
                >
                    Send test events
                </Link>
            </div>

            <div class="space-y-2">
                <h3 class="font-medium">Plain HTTP</h3>
                <pre
                    tabindex="0"
                    aria-label="curl example"
                    class="overflow-x-auto rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm"
                ><code>{{ curlSnippet(shownKey) }}</code></pre>
                <CopyButton :text="curlSnippet(api_key)" label="Copy command" />
            </div>
        </section>

        <section v-if="can.rotate_key" aria-labelledby="rotate-heading" class="space-y-3">
            <h2 id="rotate-heading" class="text-lg font-semibold tracking-tight">Replace the API key</h2>
            <p class="max-w-prose text-sm text-muted">
                Use this if the key leaked. The old key stops working at once, so every site or server that still uses it
                loses its events until you update it.
            </p>
            <SecondaryButton aria-haspopup="dialog" @click="rotateOpen = true">Replace API key…</SecondaryButton>
        </section>

        <section v-if="can.delete" aria-labelledby="delete-heading" class="space-y-3 rounded-md border border-danger px-4 py-4">
            <h2 id="delete-heading" class="text-lg font-semibold tracking-tight text-danger">Delete the project</h2>
            <p class="max-w-prose text-sm text-muted">
                This removes the project and, in the background, every event it collected. It cannot be undone.
            </p>
            <DangerButton type="button" aria-haspopup="dialog" @click="openDelete">Delete project…</DangerButton>
        </section>
    </div>

    <ConfirmDialog :open="rotateOpen" title="Replace the API key?" @close="rotateOpen = false">
        <template #description>
            The current key is rejected from now on. Update every place that sends events with the new key afterwards.
        </template>
        <div class="flex justify-end gap-3">
            <SecondaryButton autofocus @click="rotateOpen = false">Cancel</SecondaryButton>
            <DangerButton type="button" :disabled="rotating" @click="rotate">Replace key</DangerButton>
        </div>
    </ConfirmDialog>

    <ConfirmDialog :open="deleteOpen" title="Delete this project?" @close="deleteOpen = false">
        <template #description>
            The project and all of its events will be deleted. Events are removed in the background, which can take a
            few minutes. Type <strong class="text-fg">{{ project.name }}</strong> to confirm.
        </template>
        <form ref="deleteFormRef" class="space-y-4" novalidate @submit.prevent="destroy">
            <TextField
                v-model="deleteForm.confirm_name"
                label="Project name"
                name="confirm_name"
                autocomplete="off"
                :error="deleteForm.errors.confirm_name"
                required
                autofocus
            />
            <div class="flex justify-end gap-3">
                <SecondaryButton @click="deleteOpen = false">Cancel</SecondaryButton>
                <DangerButton :disabled="deleteForm.processing">Delete project</DangerButton>
            </div>
        </form>
    </ConfirmDialog>
</template>
