// The ECharts options of the insight charts (trends: lines or bars over time; funnel: horizontal bars per step; retention: the average curve), built from plain data and a resolved colour theme. No ECharts import here: the
// chart component hands the object to `chart.setOption()`, and tests can inspect it without a DOM.

import { barLabel, formatRate, stepLabel } from './funnelFormat.js';
import { abbreviate, formatNumber, truncate } from './trendsFormat.js';

const CHART_COLORS = 8;

/**
 * Resolves the design tokens to concrete colours. ECharts cannot read CSS variables, so this reads the computed values
 * from `<html>` (call it again whenever the colour scheme changes).
 *
 * @param {Element} root
 */
export function readChartTheme(root = document.documentElement) {
    const style = getComputedStyle(root);
    const read = (name, fallback) => style.getPropertyValue(name).trim() || fallback;

    return {
        fg: read('--fg', '#17171a'),
        muted: read('--muted', '#52525a'),
        line: read('--line', '#dcdce0'),
        lineStrong: read('--line-strong', '#85858f'),
        surface: read('--surface', '#ffffff'),
        fontFamily: style.fontFamily || 'sans-serif',
        series: Array.from({ length: CHART_COLORS }, (_, index) => read(`--chart-${index + 1}`, '#0072b2')),
    };
}

/**
 * @param {object} input
 * @param {string[]} input.labels one per bucket, unique
 * @param {{name: string, data: number[]}[]} input.series the series that are drawn
 * @param {'line'|'bar'} input.chartType
 * @param {ReturnType<typeof readChartTheme>} input.theme
 * @param {boolean} input.reducedMotion
 * @param {string} input.description the accessible summary
 */
export function buildChartOption({ labels, series, chartType, theme, reducedMotion, description }) {
    const multiple = series.length > 1;

    return {
        animation: !reducedMotion,
        color: theme.series,
        textStyle: { color: theme.fg, fontFamily: theme.fontFamily },
        aria: { enabled: true, label: { enabled: true, description } },
        grid: { left: 8, right: 16, top: 16, bottom: multiple ? 40 : 8, containLabel: true },
        legend: {
            show: multiple,
            type: 'scroll',
            bottom: 0,
            data: series.map((entry) => entry.name),
            formatter: (name) => truncate(name, 28),
            textStyle: { color: theme.fg },
            pageTextStyle: { color: theme.muted },
            pageIconColor: theme.fg,
            pageIconInactiveColor: theme.line,
            tooltip: { show: true },
        },
        tooltip: {
            trigger: 'axis',
            confine: true,
            backgroundColor: theme.surface,
            borderColor: theme.lineStrong,
            textStyle: { color: theme.fg, fontFamily: theme.fontFamily },
            axisPointer: { type: chartType === 'bar' ? 'shadow' : 'line', lineStyle: { color: theme.lineStrong } },
            valueFormatter: (value) => formatNumber(value),
        },
        xAxis: {
            type: 'category',
            data: labels,
            boundaryGap: chartType === 'bar',
            axisLabel: { color: theme.muted, hideOverlap: true },
            axisLine: { lineStyle: { color: theme.lineStrong } },
            axisTick: { alignWithLabel: true, lineStyle: { color: theme.lineStrong } },
        },
        yAxis: {
            type: 'value',
            minInterval: 1,
            axisLabel: { color: theme.muted, formatter: (value) => abbreviate(value) },
            splitLine: { lineStyle: { color: theme.line } },
        },
        series: series.map((entry, index) => ({
            type: chartType,
            name: entry.name,
            data: entry.data,
            // Colours repeat after 8 series: the second round is drawn dashed (lines) so two series never look alike.
            itemStyle: { color: theme.series[index % CHART_COLORS] },
            lineStyle: { width: 2, type: index >= CHART_COLORS ? 'dashed' : 'solid', color: theme.series[index % CHART_COLORS] },
            showSymbol: labels.length <= 40,
            symbolSize: 6,
            emphasis: { focus: 'series' },
        })),
    };
}

/**
 * The option of a funnel: one horizontal bar per step, step 1 on top, each bar labelled with its count and its share of
 * everyone who started. One colour (the first chart colour): the bars measure the same thing, so length and the labels carry
 * the meaning and no colour has to be told apart. The tooltip is ECharts' own (escaped) one: an event name is user data and
 * never goes into an HTML formatter.
 *
 * @param {object} input
 * @param {object[]} input.steps the `steps` of a funnel result
 * @param {ReturnType<typeof readChartTheme>} input.theme
 * @param {boolean} input.reducedMotion
 * @param {string} input.description the accessible summary
 * @param {boolean} [input.compact] a narrow screen: shorter step labels and less room for the bar labels (the table has it all)
 */
export function buildFunnelOption({ steps, theme, reducedMotion, description, compact = false }) {
    return {
        animation: !reducedMotion,
        color: [theme.series[0]],
        textStyle: { color: theme.fg, fontFamily: theme.fontFamily },
        aria: { enabled: true, label: { enabled: true, description } },
        grid: { left: 8, right: compact ? 84 : 130, top: 8, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'axis',
            confine: true,
            backgroundColor: theme.surface,
            borderColor: theme.lineStrong,
            textStyle: { color: theme.fg, fontFamily: theme.fontFamily },
            axisPointer: { type: 'shadow' },
            valueFormatter: (value) => formatNumber(value),
        },
        xAxis: {
            type: 'value',
            minInterval: 1,
            axisLabel: { color: theme.muted, formatter: (value) => abbreviate(value) },
            splitLine: { lineStyle: { color: theme.line } },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: steps.map((step) => stepLabel(step, compact ? 14 : 32)),
            axisLabel: { color: theme.fg, width: compact ? 96 : 180, overflow: 'truncate' },
            axisLine: { lineStyle: { color: theme.lineStrong } },
            axisTick: { show: false },
        },
        series: [
            {
                type: 'bar',
                name: 'People',
                data: steps.map((step) => step.count),
                barMaxWidth: 44,
                itemStyle: { color: theme.series[0] },
                label: { show: true, position: 'right', color: theme.fg, formatter: (point) => barLabel(steps[point.dataIndex], compact) },
                emphasis: { focus: 'series' },
            },
        ],
    };
}

/**
 * The option of the average retention curve: one line over the periods 0..N, the share of a cohort that is active in that
 * period (0 to 100%, always the whole scale, so two tables can be compared by eye). A period without an average is a gap in the
 * line, never a drop to zero. One colour (the first chart colour); the numbers are in the table under the chart.
 *
 * @param {object} input
 * @param {string[]} input.labels one per period, unique ("Week 0")
 * @param {(number|null)[]} input.points the pooled share per period (0..1), null where there is none
 * @param {string} input.name the series name ("Average retention"), in the tooltip
 * @param {ReturnType<typeof readChartTheme>} input.theme
 * @param {boolean} input.reducedMotion
 * @param {string} input.description the accessible summary
 */
export function buildRetentionOption({ labels, points, name, theme, reducedMotion, description }) {
    return {
        animation: !reducedMotion,
        color: [theme.series[0]],
        textStyle: { color: theme.fg, fontFamily: theme.fontFamily },
        aria: { enabled: true, label: { enabled: true, description } },
        grid: { left: 8, right: 24, top: 16, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'axis',
            confine: true,
            backgroundColor: theme.surface,
            borderColor: theme.lineStrong,
            textStyle: { color: theme.fg, fontFamily: theme.fontFamily },
            axisPointer: { type: 'line', lineStyle: { color: theme.lineStrong } },
            valueFormatter: (value) => formatRate(value),
        },
        xAxis: {
            type: 'category',
            data: labels,
            boundaryGap: false,
            axisLabel: { color: theme.muted, hideOverlap: true },
            axisLine: { lineStyle: { color: theme.lineStrong } },
            axisTick: { alignWithLabel: true, lineStyle: { color: theme.lineStrong } },
        },
        yAxis: {
            type: 'value',
            min: 0,
            max: 1,
            interval: 0.25,
            axisLabel: { color: theme.muted, formatter: (value) => formatRate(value) },
            splitLine: { lineStyle: { color: theme.line } },
        },
        series: [
            {
                type: 'line',
                name,
                data: points,
                connectNulls: false,
                itemStyle: { color: theme.series[0] },
                lineStyle: { width: 2, color: theme.series[0] },
                showSymbol: labels.length <= 40,
                symbolSize: 6,
                emphasis: { focus: 'series' },
            },
        ],
    };
}
