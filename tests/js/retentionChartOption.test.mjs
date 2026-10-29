import assert from 'node:assert/strict';
import test from 'node:test';

import { buildRetentionOption } from '../../resources/js/lib/chartOption.js';

const theme = { fg: '#111', muted: '#555', line: '#ddd', lineStrong: '#888', surface: '#fff', fontFamily: 'sans-serif', series: ['#0072b2', '#df600c'] };
const input = { labels: ['Week 0', 'Week 1', 'Week 2'], points: [1, 0.4, null], name: 'Average retention', theme, reducedMotion: false, description: 'The curve.' };

test('draws one line in the first chart colour over the periods, with the whole 0 to 100% scale', () => {
    const option = buildRetentionOption(input);

    assert.deepEqual(option.xAxis.data, input.labels);
    assert.equal(option.series.length, 1);
    assert.deepEqual([option.series[0].type, option.series[0].name, option.series[0].data], ['line', 'Average retention', [1, 0.4, null]]);
    assert.deepEqual([option.series[0].itemStyle.color, option.series[0].lineStyle.color, option.color], ['#0072b2', '#0072b2', ['#0072b2']]);
    assert.deepEqual([option.yAxis.min, option.yAxis.max], [0, 1]);
});

test('a period without an average is a gap in the line, not a fall to zero', () => {
    const option = buildRetentionOption(input);

    assert.equal(option.series[0].connectNulls, false);
    assert.equal(option.series[0].data[2], null);
});

test('formats the axis and the tooltip as shares and carries the description for assistive technology', () => {
    const option = buildRetentionOption(input);

    assert.equal(option.yAxis.axisLabel.formatter(0.25), '25%');
    assert.equal(option.tooltip.valueFormatter(0.3333), '33.33%');
    assert.equal(option.aria.label.description, 'The curve.');
    assert.equal(option.aria.enabled, true);
});

test('follows reduced motion and the colours of the theme', () => {
    assert.equal(buildRetentionOption(input).animation, true);
    assert.equal(buildRetentionOption({ ...input, reducedMotion: true }).animation, false);
    assert.equal(buildRetentionOption(input).tooltip.backgroundColor, '#fff');
    assert.equal(buildRetentionOption(input).yAxis.splitLine.lineStyle.color, '#ddd');
});
