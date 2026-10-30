// A queue that lets at most `limit` jobs run at once and starts the rest in the order they were asked for. A dashboard page
// has up to 30 tiles; every tile runs its insight through the insight API, and this is what keeps that from being 30 queries
// at once (the server's own caps would answer 429, and a page must not be the thing that provokes them).
//
// A job is `start()`: it begins the work and returns nothing; whoever owns the job calls `release(key)` when the work is
// over (done, failed, cancelled, unmounted). Framework free, so the rules are tested without a DOM (tests/js/runQueue.test.mjs).

export class RunQueue {
    #limit;
    #active = new Set();
    #waiting = [];
    #listeners = new Set();

    /** @param {number} limit how many jobs may run at the same time (at least 1) */
    constructor(limit = 3) {
        this.#limit = Math.max(1, Math.floor(limit));
    }

    get limit() {
        return this.#limit;
    }

    get activeCount() {
        return this.#active.size;
    }

    get waitingCount() {
        return this.#waiting.length;
    }

    isActive(key) {
        return this.#active.has(key);
    }

    isWaiting(key) {
        return this.#waiting.some((job) => job.key === key);
    }

    /** Called with no arguments whenever a job started, was released or left the queue. Returns the unsubscribe function. */
    subscribe(listener) {
        this.#listeners.add(listener);

        return () => this.#listeners.delete(listener);
    }

    /**
     * Asks for a slot. A key that already runs or already waits is left alone (a second request for the same tile never
     * doubles it) and `false` is returned; otherwise the job is queued (and started at once when a slot is free).
     *
     * @param {string} key
     * @param {() => void} start
     * @returns {boolean} whether the job was accepted
     */
    enqueue(key, start) {
        if (this.#active.has(key) || this.isWaiting(key)) {
            return false;
        }

        this.#waiting.push({ key, start });
        this.#drain();
        this.#notify();

        return true;
    }

    /** The work of `key` is over: its slot goes to the next waiting job. Harmless for a key that holds none. */
    release(key) {
        if (this.#active.delete(key)) {
            this.#drain();
            this.#notify();
        }
    }

    /** Takes `key` out of the queue if it still waits, and frees its slot if it runs. */
    cancel(key) {
        const before = this.#waiting.length;
        this.#waiting = this.#waiting.filter((job) => job.key !== key);
        const removed = this.#waiting.length !== before;

        if (this.#active.delete(key)) {
            this.#drain();
            this.#notify();
        } else if (removed) {
            this.#notify();
        }
    }

    /** Forgets every waiting job and every running one (the page is going away). */
    clear() {
        this.#waiting = [];
        this.#active.clear();
        this.#notify();
    }

    #drain() {
        while (this.#active.size < this.#limit && this.#waiting.length > 0) {
            const job = this.#waiting.shift();
            this.#active.add(job.key);
            try {
                job.start();
            } catch {
                // a job that cannot even start holds no slot
                this.#active.delete(job.key);
            }
        }
    }

    #notify() {
        for (const listener of [...this.#listeners]) {
            listener();
        }
    }
}
