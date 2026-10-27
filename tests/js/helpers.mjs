// A fake clock and a scripted fetch for the framework-free client tests (node:test, no dependencies).

export class FakeClock {
    time = 0;
    #timers = [];
    #nextId = 1;

    now = () => this.time;

    setTimer = (fn, ms) => {
        const id = this.#nextId++;
        this.#timers.push({ id, at: this.time + ms, fn });
        this.#timers.sort((a, b) => a.at - b.at || a.id - b.id);

        return id;
    };

    clearTimer = (id) => {
        this.#timers = this.#timers.filter((timer) => timer.id !== id);
    };

    /** Runs every timer due within `ms`, in order, letting promises settle after each one. */
    async advance(ms) {
        const end = this.time + ms;
        await flush();

        for (;;) {
            const next = this.#timers[0];
            if (next === undefined || next.at > end) {
                break;
            }
            this.#timers.shift();
            this.time = Math.max(this.time, next.at);
            next.fn();
            await flush();
        }

        this.time = end;
        await flush();
    }

    get pending() {
        return this.#timers.length;
    }
}

export async function flush() {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

export function json(status, body, headers = {}) {
    return { status, body, headers };
}

/** `routes`: function(call) returning json(...) | Error | a promise of either. Every call is recorded. */
export function fakeFetch(routes, { honourAbort = true } = {}) {
    const calls = [];

    async function fetch(url, init = {}) {
        const call = { url, method: init.method ?? 'GET', headers: init.headers ?? {}, body: init.body === undefined ? undefined : JSON.parse(init.body), credentials: init.credentials, signal: init.signal, at: null };
        calls.push(call);
        const abortError = () => Object.assign(new Error('aborted'), { name: 'AbortError' });

        if (init.signal?.aborted) {
            throw abortError();
        }

        // Like fetch, a request whose signal aborts rejects at once, however long the answer would take (a request that
        // never answers ends here).
        const aborted = new Promise((_, reject) => init.signal?.addEventListener('abort', () => honourAbort && reject(abortError()), { once: true }));
        const answer = await Promise.race([Promise.resolve(routes(call, calls)), aborted]);

        if (answer instanceof Error) {
            throw answer;
        }

        return {
            status: answer.status,
            headers: { get: (name) => answer.headers[name] ?? null },
            // reading the body is aborted by the signal too, like the real thing
            json: async () => {
                await Promise.resolve();
                if (init.signal?.aborted) {
                    throw abortError();
                }
                if (answer.body === undefined) {
                    throw new Error('no body');
                }

                return answer.body;
            },
        };
    }

    return { fetch, calls };
}
