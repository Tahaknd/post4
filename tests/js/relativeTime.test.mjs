import assert from 'node:assert/strict';
import test from 'node:test';

import { formatAbsolute, formatRelative, isoOf, updatedText } from '../../resources/js/lib/relativeTime.js';

const NOW = Date.parse('2026-10-30T12:00:00Z');
const ago = (seconds) => NOW - seconds * 1000;

test('under a minute is just now, also for a time in the future', () => {
    assert.equal(formatRelative(ago(0), NOW), 'just now');
    assert.equal(formatRelative(ago(59), NOW), 'just now');
    assert.equal(formatRelative(ago(-300), NOW), 'just now');
    assert.equal(formatRelative('not a date', NOW), 'just now');
});

test('minutes, hours and days, with singular and plural', () => {
    assert.equal(formatRelative(ago(60), NOW), '1 minute ago');
    assert.equal(formatRelative(ago(5 * 60 + 30), NOW), '5 minutes ago');
    assert.equal(formatRelative(ago(3599), NOW), '59 minutes ago');
    assert.equal(formatRelative(ago(3600), NOW), '1 hour ago');
    assert.equal(formatRelative(ago(23 * 3600 + 3599), NOW), '23 hours ago');
    assert.equal(formatRelative(ago(86400), NOW), '1 day ago');
    assert.equal(formatRelative(ago(3 * 86400), NOW), '3 days ago');
});

test('accepts ISO strings and Dates, and builds the sentence', () => {
    assert.equal(updatedText('2026-10-30T11:55:00Z', new Date(NOW)), 'Updated 5 minutes ago');
    assert.equal(updatedText(new Date(NOW), NOW), 'Updated just now');
});

test('the absolute time and the datetime attribute', () => {
    assert.equal(isoOf('2026-10-30T11:55:00Z'), '2026-10-30T11:55:00.000Z');
    assert.equal(isoOf('nope'), '');
    assert.equal(formatAbsolute('2026-10-30T11:55:00Z', 'Europe/Istanbul'), 'Oct 30, 2026, 14:55');
    assert.equal(formatAbsolute('2026-10-30T11:55:00Z', 'Not/AZone'), 'Oct 30, 2026, 11:55');
    assert.equal(formatAbsolute('nope'), '');
});
