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

        if (init.signal?.aborted) {
            throw Object.assign(new Error('aborted'), { name: 'AbortError' });
        }

        const answer = await routes(call, calls);

        if (answer instanceof Error) {
            throw answer;
        }

        // an aborted request rejects like fetch does, even when the answer is already known (unless a test models the
        // race where the answer was already complete when the abort came)
        if (honourAbort && init.signal?.aborted) {
            throw Object.assign(new Error('aborted'), { name: 'AbortError' });
        }

        return {
            status: answer.status,
            headers: { get: (name) => answer.headers[name] ?? null },
            json: async () => {
                if (answer.body === undefined) {
                    throw new Error('no body');
                }

                return answer.body;
            },
        };
    }

    return { fetch, calls };
}
