import assert from 'node:assert/strict';
import test from 'node:test';

import { nextUrl } from '../../resources/js/lib/urlState.js';

const at = (pathname, search = '', hash = '') => ({ pathname, search, hash });

test('writes the query after the path and keeps the fragment', () => {
    assert.equal(nextUrl(at('/projects/1/insights/funnel', '', '#results'), 's=a'), '/projects/1/insights/funnel?s=a#results');
});

test('removes the query when the definition is back to its defaults', () => {
    assert.equal(nextUrl(at('/p', '?s=a'), ''), '/p');
});

test('says nothing when the address bar already shows the query', () => {
    assert.equal(nextUrl(at('/p', '?s=a', '#x'), 's=a'), null);
    assert.equal(nextUrl(at('/p'), ''), null);
});
