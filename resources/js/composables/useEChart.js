// The lifecycle every chart of the app shares, so a canvas component only says WHICH ECharts parts it needs and HOW its
// data becomes an option. Imported by the lazily loaded canvases only, so ECharts stays out of the entry bundle.
//
// SVG, not canvas: the plots are small (the API caps a result at 10 000 cells, a funnel has at most 8 bars), SVG stays crisp on
// every pixel density and zoom level, and its nodes can be inspected by tests and tools (the Playwright specs assert on
// them). The canvas renderer would only win on far larger plots.
//
// ECharts cannot read CSS variables, so the colours are resolved from the design tokens (readChartTheme) and resolved AGAIN
// whenever the colour scheme changes (prefers-color-scheme, or [data-theme] on <html>). Animation is off when the user
// prefers reduced motion (also followed live).
import * as echarts from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import { readChartTheme } from '@/lib/chartOption';

echarts.use([SVGRenderer]);

/**
 * @param {import('vue').Ref<HTMLElement|null>} el the element the chart is drawn into
 * @param {(context: {theme: ReturnType<typeof readChartTheme>, reducedMotion: boolean}) => object} build the ECharts option,
 *     read inside a computed: whatever reactive state it reads redraws the chart
 */
export function useEChart(el, build) {
    const theme = shallowRef(null);
    const reducedMotion = ref(false);
    let chart = null;
    let resizeObserver = null;
    let themeObserver = null;
    let frame = 0;
    const queries = [];

    const option = computed(() => (theme.value === null ? null : build({ theme: theme.value, reducedMotion: reducedMotion.value })));

    function resolveTheme() {
        // The new colour scheme is applied to the computed style a frame after the media query changes.
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => (theme.value = readChartTheme()));
    }

    const setReducedMotion = (event) => (reducedMotion.value = event.matches);

    onMounted(() => {
        theme.value = readChartTheme();

        const dark = window.matchMedia('(prefers-color-scheme: dark)');
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        reducedMotion.value = motion.matches;
        dark.addEventListener('change', resolveTheme);
        motion.addEventListener('change', setReducedMotion);
        queries.push([dark, resolveTheme], [motion, setReducedMotion]);

        themeObserver = new MutationObserver(resolveTheme);
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        chart = echarts.init(el.value, null, { renderer: 'svg' });
        resizeObserver = new ResizeObserver(() => chart?.resize());
        resizeObserver.observe(el.value);
    });

    watch(
        option,
        (next) => {
            // notMerge: a new definition replaces the old option instead of blending series into it.
            if (chart !== null && next !== null) {
                chart.setOption(next, { notMerge: true });
            }
        },
        { flush: 'post' },
    );

    onBeforeUnmount(() => {
        cancelAnimationFrame(frame);
        resizeObserver?.disconnect();
        themeObserver?.disconnect();
        for (const [query, listener] of queries) {
            query.removeEventListener('change', listener);
        }
        chart?.dispose();
        chart = null;
    });
}
