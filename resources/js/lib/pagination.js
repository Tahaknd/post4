// Client-side paging of a list that is already loaded (a project has at most 200 saved insights and 50 dashboards, loaded in
// one or two API calls): which slice is on screen and what the pager says.

/** @template T @param {T[]} items @returns {{ items: T[], page: number, lastPage: number, total: number, from: number, to: number }} */
export function paginate(items, page, perPage) {
    const total = items.length;
    const size = Math.max(1, Math.floor(perPage));
    const lastPage = Math.max(1, Math.ceil(total / size));
    const current = Math.min(Math.max(1, Math.floor(Number(page)) || 1), lastPage);
    const start = (current - 1) * size;
    const slice = items.slice(start, start + size);

    return { items: slice, page: current, lastPage, total, from: total === 0 ? 0 : start + 1, to: start + slice.length };
}

/** "Showing 21 to 40 of 57" ("No results" for an empty list). */
export function rangeText({ from, to, total }, noun = 'results') {
    return total === 0 ? `No ${noun}` : `Showing ${from} to ${to} of ${total}`;
}
