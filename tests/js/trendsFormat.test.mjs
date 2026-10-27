import assert from 'node:assert/strict';
import test from 'node:test';

import { buildChartOption } from '../../resources/js/lib/chartOption.js';
import {
    DEFAULT_LOCALE,
    MAX_CHART_SERIES,
    abbreviate,
    bucketLabels,
    codePoints,
    formatNumber,
    describeResult,
    isEmptyResult,
    selectChartSeries,
    seriesNames,
    total,
    truncate,
} from '../../resources/js/lib/trendsFormat.js';

const utc = (iso) => Date.parse(iso) / 1000;
const serie = (event, breakdown, data, flags = {}) => ({ event, breakdown, is_other: false, is_none: false, data, ...flags });

test('day labels are read in the result zone, not the browser zone', () => {
    // 2026-03-14 22:00 UTC is already 03-15 in Istanbul (UTC+3) and still 03-14 in New York
    const buckets = [utc('2026-03-14T22:00:00Z')];

    assert.match(bucketLabels(buckets, 'day', 'Europe/Istanbul')[0], /15/);
    assert.match(bucketLabels(buckets, 'day', 'America/New_York')[0], /14/);
    assert.match(bucketLabels(buckets, 'day', 'UTC')[0], /14/);
});

test('hour labels follow the zone clock across a DST change and stay unique', () => {
    // Europe/Istanbul has no DST; use America/New_York: 2026-11-01 01:00 happens twice (EDT then EST)
    const buckets = [utc('2026-11-01T04:00:00Z'), utc('2026-11-01T05:00:00Z'), utc('2026-11-01T06:00:00Z'), utc('2026-11-01T07:00:00Z')];
    const labels = bucketLabels(buckets, 'hour', 'America/New_York');

    assert.equal(new Set(labels).size, 4, 'no two buckets share a label');
    assert.match(labels[1], /1:00|01:00/);
    assert.match(labels[2], /1:00|01:00/);
    assert.notEqual(labels[1], labels[2]);
    assert.match(labels[1], /GMT-4/);
    assert.match(labels[2], /GMT-5/);

    // spring forward: 02:00 does not exist on 2026-03-08 in New York
    const spring = [utc('2026-03-08T06:00:00Z'), utc('2026-03-08T07:00:00Z'), utc('2026-03-08T08:00:00Z')];
    const labelsSpring = bucketLabels(spring, 'hour', 'America/New_York');
    assert.equal(new Set(labelsSpring).size, 3);
    assert.ok(!labelsSpring.some((label) => /02:00|2:00/.test(label)), labelsSpring.join('|'));
});

test('month and week labels, years shown only when the axis spans years', () => {
    assert.match(bucketLabels([utc('2026-03-01T00:00:00Z')], 'month', 'UTC')[0], /2026/);
    assert.doesNotMatch(bucketLabels([utc('2026-03-01T00:00:00Z'), utc('2026-03-02T00:00:00Z')], 'day', 'UTC').join(' '), /2026/);
    assert.match(bucketLabels([utc('2025-12-31T00:00:00Z'), utc('2026-01-01T00:00:00Z')], 'day', 'UTC').join(' '), /2025.*2026|2026.*2025/);
    assert.match(bucketLabels([utc('2026-03-02T00:00:00Z')], 'week', 'UTC', 'long')[0], /Mon/);
});

test('sparse buckets keep one label each, aligned by index', () => {
    const buckets = [utc('2026-03-01T00:00:00Z'), utc('2026-03-05T00:00:00Z'), utc('2026-03-20T00:00:00Z')];

    assert.equal(bucketLabels(buckets, 'day', 'UTC').length, 3);
    assert.equal(bucketLabels([], 'day', 'UTC').length, 0);
});

test('an unknown zone falls back to UTC instead of throwing', () => {
    assert.equal(bucketLabels([utc('2026-03-01T00:00:00Z')], 'day', 'Not/AZone').length, 1);
});

test('series names: sentinels become words, the event joins the name only when there are several events', () => {
    const one = [serie('signup', null, [1]), serie('signup', 'Chrome', [1]), serie('signup', '$$_post4_breakdown_other_$$', [1], { is_other: true }), serie('signup', '$$_post4_breakdown_none_$$', [1], { is_none: true })];
    assert.deepEqual(seriesNames([one[0]]), ['signup']);
    assert.deepEqual(seriesNames(one.slice(1)), ['Chrome', 'Other (rest)', 'No value']);
    assert.ok(!seriesNames(one.slice(1)).join().includes('post4'));

    const two = [serie('signup', 'Chrome', [1]), serie('purchase', null, [1]), serie('purchase', '$$_post4_breakdown_none_$$', [1], { is_none: true })];
    assert.deepEqual(seriesNames(two), ['signup · Chrome', 'purchase', 'purchase · No value']);
});

test('a REAL value called Other or None is shown as written and never collides with the sentinel names', () => {
    assert.deepEqual(seriesNames([serie('e', 'Other', [1]), serie('e', 'None', [1]), serie('e', '$$_x', [1], { is_other: true }), serie('e', '$$_y', [1], { is_none: true })]), ['Other', 'None', 'Other (rest)', 'No value']);
});

test('the chart draws at most 10 series, the biggest ones in their original order, and counts what is hidden', () => {
    const series = Array.from({ length: 260 }, (_, i) => serie('e', `v${i}`, [i % 7, 0, 1]));
    const { shown, hidden } = selectChartSeries(series);

    assert.equal(shown.length, MAX_CHART_SERIES);
    assert.equal(hidden, 250);
    assert.deepEqual([...shown].sort((a, b) => a - b), shown, 'original order');
    const smallestShown = Math.min(...shown.map((i) => total(series[i].data)));
    const biggestHidden = Math.max(...series.filter((_, i) => !shown.includes(i)).map((s) => total(s.data)));
    assert.ok(smallestShown >= biggestHidden);

    assert.deepEqual(selectChartSeries(series.slice(0, 3)), { shown: [0, 1, 2], hidden: 0 });
});

test('detects an all-zero result', () => {
    assert.equal(isEmptyResult({ series: [serie('a', null, [0, 0]), serie('b', null, [0])] }), true);
    assert.equal(isEmptyResult({ series: [serie('a', null, [0, 1])] }), false);
    assert.equal(isEmptyResult({ series: [] }), true);
});

test('truncates by characters, not bytes, and abbreviates big numbers', () => {
    assert.equal(truncate('short'), 'short');
    assert.equal(truncate('a'.repeat(50), 10), 'aaaaaaaaa…');
    assert.equal([...truncate('😀'.repeat(50), 10)].length, 10);
    assert.equal(abbreviate(1200), '1.2K');
    assert.equal(abbreviate(5), '5');
});

test('describes the result for the accessible name', () => {
    const result = { timezone: 'UTC', buckets: [utc('2026-03-01T00:00:00Z'), utc('2026-03-03T00:00:00Z')], series: [serie('a', null, [3, 4]), serie('b', null, [1, 0])] };
    const text = describeResult(result, { chartType: 'line', metric: 'total_events', interval: 'day' });

    assert.match(text, /^Line chart of events per day, 2 series from /);
    assert.match(text, /Sum of all values: 8\./);
    assert.match(describeResult(result, { chartType: 'bar', metric: 'unique_users', interval: 'week' }), /^Bar chart of unique users per week/);
    assert.match(describeResult({ ...result, buckets: [result.buckets[0]] }, { chartType: 'line', metric: 'total_events', interval: 'day' }), / on /);
});

const theme = { fg: '#111', muted: '#222', line: '#333', lineStrong: '#444', surface: '#555', fontFamily: 'sans-serif', series: ['#1', '#2', '#3', '#4', '#5', '#6', '#7', '#8'] };

test('builds an option with a category axis from the labels, aligned data, theme colours and animation off for reduced motion', () => {
    const option = buildChartOption({
        labels: ['a', 'b', 'c'],
        series: Array.from({ length: 10 }, (_, i) => ({ name: `s${i}`, data: [i, i, i] })),
        chartType: 'line', theme, reducedMotion: true, description: 'Chart text',
    });

    assert.equal(option.animation, false);
    assert.equal(option.xAxis.type, 'category');
    assert.deepEqual(option.xAxis.data, ['a', 'b', 'c']);
    assert.equal(option.series.length, 10);
    assert.equal(option.series[0].lineStyle.type, 'solid');
    assert.equal(option.series[8].lineStyle.type, 'dashed');
    assert.equal(option.series[8].itemStyle.color, '#1');
    assert.equal(option.aria.enabled, true);
    assert.equal(option.aria.label.description, 'Chart text');
    assert.equal(option.legend.show, true);
    assert.equal(option.textStyle.color, '#111');

    const bar = buildChartOption({ labels: ['a'], series: [{ name: 's', data: [1] }], chartType: 'bar', theme, reducedMotion: false, description: '' });
    assert.equal(bar.animation, true);
    assert.equal(bar.series[0].type, 'bar');
    assert.equal(bar.xAxis.boundaryGap, true);
    assert.equal(bar.legend.show, false);
});

test('formats with an explicit locale, whatever the process language is, and reuses the formatters', () => {
    assert.equal(DEFAULT_LOCALE, 'en');
    assert.equal(formatNumber(1234567), '1,234,567');
    assert.equal(formatNumber(1234567, 'de'), '1.234.567');
    assert.equal(abbreviate(1200), '1.2K');
    assert.match(bucketLabels([utc('2026-03-14T22:00:00Z')], 'day', 'UTC')[0], /Mar/);
    assert.match(bucketLabels([utc('2026-03-14T22:00:00Z')], 'day', 'UTC', 'short', 'de')[0], /M\u00e4r/);

    // one instance per locale and options
    const first = Intl.DateTimeFormat;
    let built = 0;
    Intl.DateTimeFormat = new Proxy(first, { construct(target, args) { built++; return new target(...args); } });
    try {
        bucketLabels([utc('2026-03-14T22:00:00Z')], 'day', 'Asia/Tokyo');
        const afterFirst = built;
        bucketLabels([utc('2026-03-15T22:00:00Z')], 'day', 'Asia/Tokyo');
        assert.equal(built, afterFirst, 'the second call built no new formatter');
    } finally {
        Intl.DateTimeFormat = first;
    }
});

test('counts code points like mb_strlen', () => {
    assert.equal(codePoints('abc'), 3);
    assert.equal(codePoints('😀'), 1);
    assert.equal(codePoints('é'), 1);
    assert.equal('😀'.length, 2);
});
