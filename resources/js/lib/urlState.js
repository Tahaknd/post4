// The query string of an insight page follows its definition, so a link reproduces the chart.

/**
 * The URL (path, query, fragment) a page should show for `query` (a query string without `?`, '' for none), or null when
 * the address bar already shows it. The fragment is kept.
 *
 * @param {{pathname: string, search: string, hash: string}} location
 * @param {string} query
 */
export function nextUrl(location, query) {
    const next = location.pathname + (query === '' ? '' : `?${query}`) + location.hash;

    return next === location.pathname + location.search + location.hash ? null : next;
}
