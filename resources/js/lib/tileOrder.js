// The order of the tiles of a dashboard, as plain operations on a list of ids. The server takes the WHOLE new order
// (PUT tiles/order, exactly the current set), so every move is "the list as it will be"; these functions build it.

/** Whether the tile can move `delta` places (-1 earlier, 1 later). */
export function canMove(ids, id, delta) {
    const index = ids.indexOf(id);

    return index !== -1 && index + delta >= 0 && index + delta < ids.length;
}

/** The ids with `id` moved `delta` places: a new list, or the same one when it cannot move. */
export function move(ids, id, delta) {
    if (!canMove(ids, id, delta)) {
        return ids;
    }

    const index = ids.indexOf(id);
    const next = [...ids];
    [next[index], next[index + delta]] = [next[index + delta], next[index]];

    return next;
}

/** The sentence a screen reader hears after a move: "Sessions moved earlier, now position 2 of 5". */
export function moveMessage(name, ids, id, delta) {
    return `${name} moved ${delta < 0 ? 'earlier' : 'later'}, now position ${ids.indexOf(id) + 1} of ${ids.length}.`;
}

/** The tiles in the order of `ids` (tiles of `ids` that are not in the list are dropped, tiles without an id go last). */
export function arrange(tiles, ids) {
    const byId = new Map(tiles.map((tile) => [tile.id, tile]));
    const ordered = ids.filter((id) => byId.has(id)).map((id) => byId.get(id));
    const rest = tiles.filter((tile) => !ids.includes(tile.id));

    return [...ordered, ...rest];
}

/** Whether the two lists hold the same ids in the same order. */
export function sameOrder(a, b) {
    return a.length === b.length && a.every((id, index) => id === b[index]);
}
