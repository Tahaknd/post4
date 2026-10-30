// Client-side filter of the saved insight list (the whole list is loaded: a project has at most 200).

/** Case-insensitive match on name and description; `type` '' means every type. */
export function filterSavedInsights(insights, { type = '', search = '' } = {}) {
    const needle = search.trim().toLowerCase();

    return insights.filter((insight) => {
        if (type !== '' && insight.type !== type) {
            return false;
        }

        return needle === '' || `${insight.name} ${insight.description ?? ''}`.toLowerCase().includes(needle);
    });
}

/** The saved insights that are not on the dashboard yet, filtered by a search text. */
export function pickable(insights, usedIds, search = '') {
    const used = new Set(usedIds);

    return filterSavedInsights(insights.filter((insight) => !used.has(insight.id)), { search });
}
