import assert from 'node:assert/strict';
import test from 'node:test';

import { groupTimezones } from '../../resources/js/lib/timezoneGroups.js';

test('groups the zones by the part before the first slash, in order', () => {
    assert.deepEqual(groupTimezones(['Africa/Cairo', 'Europe/Berlin', 'Europe/Istanbul', 'America/Argentina/Buenos_Aires', 'Africa/Lagos']), [
        { label: 'Africa', options: ['Africa/Cairo', 'Africa/Lagos'] },
        { label: 'Europe', options: ['Europe/Berlin', 'Europe/Istanbul'] },
        { label: 'America', options: ['America/Argentina/Buenos_Aires'] },
    ]);
});

test('puts an identifier without a region in Other, and gives nothing for no zones', () => {
    assert.deepEqual(groupTimezones(['Europe/Berlin', 'UTC']), [{ label: 'Europe', options: ['Europe/Berlin'] }, { label: 'Other', options: ['UTC'] }]);
    assert.deepEqual(groupTimezones([]), []);
});
