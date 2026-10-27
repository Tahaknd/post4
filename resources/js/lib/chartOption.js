// The ECharts option of a trends chart, built from plain data and a resolved colour theme. No ECharts import here: the
// chart component hands the object to `chart.setOption()`, and tests can inspect it without a DOM.

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
