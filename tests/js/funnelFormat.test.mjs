import assert from 'node:assert/strict';
import test from 'node:test';

import { buildFunnelOption } from '../../resources/js/lib/chartOption.js';
import {
    NO_RATE,
    barLabel,
    describeFunnel,
    describeSummary,
    failureHelp,
    formatRate,
    isEmptyFunnel,
    isFunnelResult,
    orderLabel,
    stepLabel,
    summaryItems,
} from '../../resources/js/lib/funnelFormat.js';

const RESULT = {
    steps: [
        { index: 1, event: 'signup', count: 5, dropped_off: 0, conversion_from_previous: null, conversion_from_first: 1 },
        { index: 2, event: 'activate', count: 3, dropped_off: 2, conversion_from_previous: 0.6, conversion_from_first: 0.6 },
        { index: 3, event: 'purchase', count: 1, dropped_off: 2, conversion_from_previous: 0.3333, conversion_from_first: 0.2 },
    ],
    entrants: 5,
    converted: 1,
    conversion_rate: 0.2,
    window_seconds: 1209600,
    order: 'ordered',
};

const theme = { fg: '#111', muted: '#555', line: '#ddd', lineStrong: '#888', surface: '#fff', fontFamily: 'sans-serif', series: ['#0072b2', '#e69f00'] };

const NOBODY = {
    steps: RESULT.steps.map((step) => ({ ...step, count: 0, dropped_off: 0, conversion_from_previous: step.index === 1 ? null : null, conversion_from_first: null })),
    entrants: 0, converted: 0, conversion_rate: null, window_seconds: 86400, order: 'strict_order',
};

test('writes a rate as a percentage with at most two decimals, and no rate as an em dash', () => {
    assert.equal(formatRate(1), '100%');
    assert.equal(formatRate(0), '0%');
    assert.equal(formatRate(0.5), '50%');
    assert.equal(formatRate(0.6667), '66.67%');
    assert.equal(formatRate(0.3333), '33.33%');
    assert.equal(formatRate(0.0001), '0.01%');
    assert.equal(formatRate(null), NO_RATE);
    assert.equal(NO_RATE, '—');
    assert.equal(formatRate(undefined), NO_RATE);
    assert.equal(formatRate(Number.NaN), NO_RATE);
});

test('accepts the shape of a result and refuses anything else', () => {
    assert.equal(isFunnelResult(RESULT), true);
    assert.equal(isFunnelResult(NOBODY), true, 'null rates are a valid answer');

    const broken = (patch) => isFunnelResult({ ...RESULT, ...patch });
    assert.equal(isFunnelResult(null), false);
    assert.equal(isFunnelResult('x'), false);
    assert.equal(broken({ steps: [] }), false);
    assert.equal(broken({ steps: 'x' }), false);
    assert.equal(broken({ entrants: -1 }), false);
    assert.equal(broken({ entrants: 1.5 }), false);
    assert.equal(broken({ conversion_rate: '0.2' }), false);
    assert.equal(broken({ window_seconds: 0 }), false);
    assert.equal(broken({ order: undefined }), false);
    assert.equal(broken({ steps: [{ ...RESULT.steps[0], index: 2 }, ...RESULT.steps.slice(1)] }), false, 'indexes run 1..n');
    assert.equal(broken({ steps: [{ ...RESULT.steps[0], count: '5' }, ...RESULT.steps.slice(1)] }), false);
    assert.equal(broken({ steps: [{ ...RESULT.steps[0], conversion_from_first: undefined }, ...RESULT.steps.slice(1)] }), false);
});

test('an empty funnel is one nobody started', () => {
    assert.equal(isEmptyFunnel(NOBODY), true);
    assert.equal(isEmptyFunnel(RESULT), false);
});

test('labels a step with its position and cuts a long name', () => {
    assert.equal(stepLabel(RESULT.steps[1]), '2. activate');
    assert.equal(stepLabel({ index: 3, event: 'x'.repeat(50) }, 10), `3. ${'x'.repeat(9)}…`);
    assert.equal(orderLabel('strict_order'), 'Strict order');
    assert.equal(orderLabel('ordered'), 'Ordered');
});

test('summarises entrants, converted, the overall rate and the window', () => {
    assert.deepEqual(summaryItems(RESULT), [
        { label: 'Entrants', value: '5' },
        { label: 'Converted', value: '1' },
        { label: 'Overall conversion', value: '20%' },
        { label: 'Window', value: '2 weeks' },
    ]);
    assert.equal(summaryItems(NOBODY)[2].value, '—');
    assert.equal(summaryItems({ ...RESULT, entrants: 12345 })[0].value, '12,345');
});

test('describes the result for the live region and for the chart, with singular and plural', () => {
    assert.equal(describeSummary(RESULT), 'Funnel updated: 5 people started, 1 person completed all 3 steps, overall conversion 20%.');
    assert.equal(describeSummary({ ...RESULT, entrants: 1 }), 'Funnel updated: 1 person started, 1 person completed all 3 steps, overall conversion 20%.');
    assert.equal(describeFunnel(RESULT), 'Horizontal bar chart of a funnel with 3 steps. 1. signup: 5; 2. activate: 3; 3. purchase: 1. Overall conversion 20% within 2 weeks.');
});

test('labels a bar with its count and its share of the entrants', () => {
    assert.equal(barLabel(RESULT.steps[0]), '5 · 100%');
    assert.equal(barLabel(RESULT.steps[2]), '1 · 20%');
    assert.equal(barLabel(NOBODY.steps[0]), '0 · —');
});

test('a compact bar label is the count alone, and a compact option has shorter labels and less room', () => {
    assert.equal(barLabel(RESULT.steps[1], true), '3');
    const wide = buildFunnelOption({ steps: RESULT.steps, theme: { ...theme }, reducedMotion: false, description: 'D' });
    const narrow = buildFunnelOption({ steps: RESULT.steps, theme: { ...theme }, reducedMotion: false, description: 'D', compact: true });

    assert.ok(narrow.grid.right < wide.grid.right);
    assert.ok(narrow.yAxis.axisLabel.width < wide.yAxis.axisLabel.width);
    assert.equal(narrow.series[0].label.formatter({ dataIndex: 2 }), '1');
});

test('gives help for a funnel that was too large or too slow, and nothing for other errors', () => {
    assert.match(failureHelp({ kind: 'memory_limit' }), /shorter date range or window/);
    assert.equal(failureHelp({ kind: 'too_many_rows' }), failureHelp({ kind: 'memory_limit' }));
    assert.match(failureHelp({ kind: 'timeout' }), /ordered funnel/);
    assert.equal(failureHelp({ kind: 'busy' }), null);
    assert.equal(failureHelp(null), null);
});


test('builds one horizontal bar series: step 1 on top, one colour, a label per bar, an accessible description', () => {
    const option = buildFunnelOption({ steps: RESULT.steps, theme, reducedMotion: false, description: 'D' });

    assert.equal(option.yAxis.type, 'category');
    assert.equal(option.yAxis.inverse, true);
    assert.deepEqual(option.yAxis.data, ['1. signup', '2. activate', '3. purchase']);
    assert.equal(option.xAxis.type, 'value');
    assert.equal(option.series.length, 1);
    assert.deepEqual(option.series[0].data, [5, 3, 1]);
    assert.equal(option.series[0].itemStyle.color, '#0072b2');
    assert.equal(option.series[0].label.formatter({ dataIndex: 1 }), '3 · 60%');
    assert.equal(option.aria.label.description, 'D');
    assert.equal(option.animation, true);
    assert.equal(buildFunnelOption({ steps: RESULT.steps, theme, reducedMotion: true, description: 'D' }).animation, false);
});

test('the tooltip has no HTML formatter: an event name is user data', () => {
    const option = buildFunnelOption({ steps: [{ ...RESULT.steps[0], event: '<img src=x onerror=alert(1)>' }, RESULT.steps[1]], theme, reducedMotion: false, description: 'D' });

    assert.equal(option.tooltip.formatter, undefined);
    assert.equal(typeof option.tooltip.valueFormatter, 'function');
});
