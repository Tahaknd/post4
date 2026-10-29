<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import CopyButton from '@/Components/CopyButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextField from '@/Components/TextField.vue';
import { capturedEntry, effectiveApiHost, isSdkRequest, maskKey, pushEntry, requestEntry } from '@/lib/demoLog';
import { createDemoSession } from '@/lib/demoSession';

// The posthog-js test bench. The project key is a prop here as on the project page (only a member of the project gets
// this page). posthog-js itself is loaded by lib/demoSdk.js in a chunk of its own, so visitors of other pages never
// download it; lib/demoSession.js owns its lifecycle (start, restart, stale loads, stop). Everything the SDK does shows
// up in the activity log: the events it is about to send (its own `eventCaptured` hook) and every request it makes to
// this server with the HTTP status (resource timing). Each SDK start leaves one dead instance and two window listeners
// behind until the tab is closed (posthog-js cannot remove them, see demoSession.js); they send nothing.
const props = defineProps({
    project: { type: Object, required: true },
    api_key: { type: String, required: true },
    api_host: { type: String, required: true },
    urls: { type: Object, required: true },
});

const apiHost = ref(props.api_host);
const masked = computed(() => maskKey(props.api_key));

// 'loading' | 'ready' | 'failed'. Every control is unavailable unless it is 'ready', also while the SDK restarts.
const status = ref('loading');
const autocapture = ref(true);
const remoteConfig = ref(true);
const compression = ref(true);
const optedOut = ref(false);
const distinctId = ref('');
const sdkVersion = ref('');

const log = shallowRef([]);
const logBox = ref(null);
// One short sentence for screen readers about what the USER just did (the log itself is not announced: it would read
// out every request, autocapture and config fetch).
const announcement = ref('');
let awaitingResult = null;

const eventName = ref('demo_event');
const propertyKey = ref('');
const propertyValue = ref('');
const identifyAs = ref('demo-user');
const formError = ref('');

const sdkOptions = () => ({
    token: props.api_key,
    apiHost: apiHost.value,
    autocapture: autocapture.value,
    compression: compression.value,
    remoteConfig: remoteConfig.value,
    onCaptured: (captured) => push(capturedEntry(captured, props.api_key, Date.now())),
});

const session = createDemoSession({
    load: (options) => import('@/lib/demoSdk').then((module) => module.startDemoSdk(options)),
    onStatus: (next) => (status.value = next),
    onReady: (sdk) => {
        sdkVersion.value = sdk.version;
        refreshState();
        note('info', `posthog-js ${sdk.version} started (autocapture ${autocapture.value ? 'on' : 'off'}, remote config ${remoteConfig.value ? 'on' : 'off'}, gzip ${compression.value ? 'on' : 'off'})`);
    },
    onFailure: (error) => {
        note('error', `posthog-js could not start: ${error?.message ?? error}`, '', 'error');
        announce('posthog-js could not start');
    },
    onStopFailure: (error) => note('error', `posthog-js did not stop cleanly: ${error?.message ?? error}`, '', 'error'),
});

let observer = null;

function push(entry) {
    const box = logBox.value;
    // Follow the newest line only while the reader is already at the bottom; never move the window.
    const following = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 8;

    log.value = pushEntry(log.value, entry);

    if (entry.kind === 'request' && awaitingResult !== null && /^\/e\//.test(entry.text)) {
        announce(`${awaitingResult} sent, ${entry.status === 0 ? 'status unknown' : `HTTP ${entry.status}`}`);
        awaitingResult = null;
    }

    if (following) {
        nextTick(() => {
            if (logBox.value) {
                logBox.value.scrollTop = logBox.value.scrollHeight;
            }
        });
    }
}

function note(kind, text, detail = '', tone = 'info') {
    push({ at: Date.now(), kind, text, detail, tone });
}

// Cleared first and set on a later tick: a live region announces a change, so the same sentence twice would stay silent.
function announce(text) {
    announcement.value = '';
    nextTick(() => (announcement.value = text));
}

function refreshState() {
    const sdk = session.current();

    if (sdk) {
        distinctId.value = sdk.distinctId();
        optedOut.value = sdk.hasOptedOut();
    }
}

function restart(reason) {
    note('info', reason);
    session.restart(sdkOptions).catch(() => {});
}

watch(autocapture, (on) => restart(`Autocapture ${on ? 'on' : 'off'}: restarting the SDK`));
watch(remoteConfig, (on) => restart(`Remote config ${on ? 'on' : 'off'}: restarting the SDK`));
watch(compression, (on) => restart(`Gzip ${on ? 'on' : 'off'}: restarting the SDK`));

onMounted(() => {
    // The page was served by the app that takes the events, so its own origin is the host the SDK should use.
    apiHost.value = effectiveApiHost(window.location.origin, props.api_host);

    if (typeof PerformanceObserver !== 'undefined') {
        try {
            observer = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (isSdkRequest(entry.name, apiHost.value, window.location.href)) {
                        push(requestEntry(entry, apiHost.value, Date.now(), props.api_key));
                    }
                }
            });
            observer.observe({ type: 'resource' });
        } catch {
            observer = null;
        }
    }

    session.start(sdkOptions);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
    session.dispose().catch(() => {});
});

const ready = computed(() => status.value === 'ready');

// posthog-js answers a capture it dropped with nothing: opted out, or a browser it takes for a bot (automation sets
// `navigator.webdriver`, headless browsers say so in the user agent). Say so instead of leaving the log silent.
function afterCapture(result, name) {
    if (result === undefined || result === null) {
        note('error', 'The SDK did not capture the event (opted out, or this browser looks like a bot to posthog-js)', '', 'error');
        announce(`${name} was not captured`);
    } else {
        awaitingResult = name;
    }

    refreshState();
}

function pageview() {
    afterCapture(session.current()?.capturePageview(), '$pageview');
}

function customEvent() {
    formError.value = '';
    const name = eventName.value.trim();

    if (name === '') {
        formError.value = 'Give the event a name.';

        return;
    }

    const key = propertyKey.value.trim();
    afterCapture(session.current()?.capture(name, key === '' ? undefined : { [key]: propertyValue.value }), name);
}

function identify() {
    const id = identifyAs.value.trim();

    if (id === '') {
        return;
    }

    session.current()?.identify(id);
    refreshState();
    awaitingResult = '$identify';
}

function reset() {
    session.current()?.reset();
    refreshState();
    note('info', 'Reset: a new anonymous distinct id was generated');
    announce('Reset, new anonymous id');
}

function toggleOptOut() {
    const sdk = session.current();

    if (!sdk) {
        return;
    }

    if (optedOut.value) {
        sdk.optIn();
    } else {
        sdk.optOut();
    }

    refreshState();
    note('info', optedOut.value ? 'Opted out: the SDK sends nothing until you opt in again' : 'Opted in again');
    announce(optedOut.value ? 'Opted out' : 'Opted in');
}

const timeFormat = new Intl.DateTimeFormat(undefined, { timeStyle: 'medium' });
const time = (at) => timeFormat.format(new Date(at));

const toneClass = { ok: 'text-success', error: 'text-danger', info: 'text-muted' };
const labels = { captured: 'EVENT', request: 'REQUEST', beacon: 'BEACON', info: 'INFO', error: 'ERROR' };

const snippet = computed(() => `posthog.init('${masked.value}', {\n    api_host: '${apiHost.value}',\n})`);
</script>

<template>
    <Head title="Send test events" />

    <div class="max-w-5xl space-y-8">
        <header class="space-y-1">
            <p class="text-sm"><Link :href="urls.show" class="text-accent hover:text-accent-hover">{{ project.name }}</Link></p>
            <h1 class="text-2xl font-semibold tracking-tight">Send test events</h1>
            <p class="max-w-prose text-sm text-muted">
                This page runs the real posthog-js in your browser, pointed at this server with the key of
                <strong class="text-fg">{{ project.name }}</strong>. Use it to see what an SDK sends and what the server answers.
            </p>
        </header>

        <section aria-labelledby="connection-heading" class="space-y-3 rounded-md border border-line bg-surface p-4">
            <h2 id="connection-heading" class="text-lg font-semibold tracking-tight">Connection</h2>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                <dt class="text-muted">api_host</dt>
                <dd class="break-all font-mono" data-testid="demo-host">{{ apiHost }}</dd>
                <dt class="text-muted">Project key</dt>
                <dd class="flex flex-wrap items-center gap-3">
                    <span class="break-all font-mono" data-testid="demo-key">{{ masked }}</span>
                    <CopyButton :text="api_key" label="Copy key" />
                </dd>
                <dt class="text-muted">SDK</dt>
                <dd>
                    <span data-testid="demo-status" role="status">
                        <span v-if="status === 'loading'">Loading posthog-js…</span>
                        <span v-else-if="status === 'ready'" class="text-success">Ready, posthog-js {{ sdkVersion }}</span>
                        <span v-else class="text-danger">Could not start, see the log</span>
                    </span>
                </dd>
                <dt class="text-muted">distinct_id</dt>
                <dd class="break-all font-mono" data-testid="demo-distinct-id">{{ distinctId || '—' }}</dd>
            </dl>
            <p class="max-w-prose text-sm text-muted">
                The page uses the address you opened it from as <code class="font-mono">api_host</code>, so it works on a LAN
                address too. On your own site, install the SDK and use the same two values:
            </p>
            <pre tabindex="0" aria-label="posthog-js setup" class="overflow-x-auto rounded-md border border-line bg-canvas px-3 py-2 font-mono text-sm"><code>{{ snippet }}</code></pre>
        </section>

        <div class="grid gap-8 lg:grid-cols-2">
            <div class="space-y-8">
                <section aria-labelledby="events-heading" class="space-y-4">
                    <h2 id="events-heading" class="text-lg font-semibold tracking-tight">Events</h2>

                    <div>
                        <PrimaryButton type="button" :disabled="!ready" @click="ready && pageview()">Capture $pageview</PrimaryButton>
                    </div>

                    <form class="space-y-3" novalidate @submit.prevent="ready && customEvent()">
                        <TextField v-model="eventName" label="Event name" name="event_name" autocomplete="off" :error="formError" required />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <TextField v-model="propertyKey" label="Property name (optional)" name="property_key" autocomplete="off" />
                            <TextField v-model="propertyValue" label="Property value" name="property_value" autocomplete="off" />
                        </div>
                        <PrimaryButton :disabled="!ready">Capture custom event</PrimaryButton>
                    </form>
                </section>

                <section aria-labelledby="identity-heading" class="space-y-4">
                    <h2 id="identity-heading" class="text-lg font-semibold tracking-tight">Identity</h2>
                    <form class="space-y-3" novalidate @submit.prevent="ready && identify()">
                        <TextField
                            v-model="identifyAs"
                            label="Identify as"
                            name="identify_as"
                            autocomplete="off"
                            hint="Sends $identify. Merging an anonymous visitor into this id arrives in a later milestone."
                        />
                        <SecondaryButton type="submit" :disabled="!ready">Identify</SecondaryButton>
                    </form>
                    <div class="flex flex-wrap gap-3">
                        <SecondaryButton :disabled="!ready" @click="ready && reset()">Reset</SecondaryButton>
                        <SecondaryButton :disabled="!ready" :aria-pressed="optedOut ? 'true' : 'false'" @click="ready && toggleOptOut()">Opt out</SecondaryButton>
                    </div>
                    <p class="max-w-prose text-sm text-muted">
                        Reset forgets the visitor and starts a new anonymous id. Opt out stops all sending and is remembered by this browser until you opt in again.
                    </p>
                </section>

                <section aria-labelledby="options-heading" class="space-y-3">
                    <h2 id="options-heading" class="text-lg font-semibold tracking-tight">SDK options</h2>
                    <p class="max-w-prose text-sm text-muted">Changing an option restarts the SDK.</p>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="autocapture" type="checkbox" class="mt-1" />
                        <span>Autocapture clicks and form changes</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="remoteConfig" type="checkbox" class="mt-1" />
                        <span>Fetch the remote config (<code class="font-mono">/array/&lt;key&gt;/config.js</code>)</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="compression" type="checkbox" class="mt-1" />
                        <span>Compress request bodies (gzip); off sends plain JSON, and a base64 form when the page is left</span>
                    </label>
                </section>
            </div>

            <div class="space-y-8">
                <section aria-labelledby="target-heading" class="space-y-3">
                    <h2 id="target-heading" class="text-lg font-semibold tracking-tight">Autocapture target</h2>
                    <p class="max-w-prose text-sm text-muted">With autocapture on, clicking this button sends a <code class="font-mono">$autocapture</code> event.</p>
                    <button
                        type="button"
                        data-attr="demo-autocapture-target"
                        class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-4 py-2 font-medium text-fg hover:bg-canvas"
                    >
                        Autocapture target
                    </button>
                </section>

                <section aria-labelledby="log-heading" class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="log-heading" class="text-lg font-semibold tracking-tight">Activity</h2>
                        <button type="button" aria-label="Clear activity log" class="text-sm text-accent hover:text-accent-hover" @click="log = []">Clear</button>
                    </div>
                    <!-- Not a live region (aria-live off): it would read out every request. The sentence below announces
                         the result of what the user did. The box stays focusable so it can be scrolled by keyboard. -->
                    <p class="sr-only" role="status" aria-live="polite" data-testid="demo-announce">{{ announcement }}</p>
                    <div
                        ref="logBox"
                        role="log"
                        aria-live="off"
                        aria-labelledby="log-heading"
                        tabindex="0"
                        data-testid="demo-log"
                        class="max-h-96 overflow-y-auto rounded-md border border-line bg-surface p-3 text-sm"
                    >
                        <p v-if="log.length === 0" class="text-muted">Nothing yet.</p>
                        <ol v-else class="space-y-2">
                            <li v-for="entry in log" :key="entry.id" data-testid="demo-log-entry">
                                <details>
                                    <summary class="cursor-pointer">
                                        <span class="text-muted">{{ time(entry.at) }}</span>
                                        <span class="ml-2 font-mono text-xs font-semibold" :class="toneClass[entry.tone ?? 'info']">{{ labels[entry.kind] ?? entry.kind }}</span>
                                        <span class="ml-2 break-all">{{ entry.text }}</span>
                                    </summary>
                                    <pre v-if="entry.detail" class="mt-1 overflow-x-auto rounded-md border border-line bg-canvas p-2 font-mono text-xs">{{ entry.detail }}</pre>
                                </details>
                            </li>
                        </ol>
                    </div>
                    <p class="text-sm text-muted">
                        See them arrive on the server in <Link :href="urls.trends" class="text-accent underline hover:text-accent-hover">Trends</Link>
                        (a few seconds after they are sent).
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
