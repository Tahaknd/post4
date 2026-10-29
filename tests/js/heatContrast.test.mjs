import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { HEAT_STEPS } from '../../resources/js/lib/retentionFormat.js';

// The heat map of the retention table (app.css: --heat-N tints, --heat-ink-N text, .heat-future) has to keep the text of
// every cell readable (WCAG 2.x AA, 4.5:1 for normal text) in both colour schemes. This reads the real stylesheet, resolves the
// tokens of each scheme and measures, so a changed tint or a changed palette cannot silently break it.
const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

function blockAfter(marker) {
    const start = css.indexOf(marker);
    assert.notEqual(start, -1, `${marker} is in app.css`);
    const open = css.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < css.length; i++) {
        depth += css[i] === '{' ? 1 : css[i] === '}' ? -1 : 0;
        if (depth === 0) {
            return css.slice(open + 1, i);
        }
    }
    throw new Error('unbalanced block');
}

const tokens = (text) => new Map([...text.matchAll(/(--[\w-]+):\s*([^;]+);/g)].map((match) => [match[1], match[2].trim()]));

const base = tokens(blockAfter(':root {\n    --heat-1'));
const themes = {
    light: new Map([...base, ...tokens(blockAfter(":root[data-theme='light'] {"))]),
    'dark (data-theme)': new Map([...base, ...tokens(blockAfter(":root[data-theme='dark'] {"))]),
    'dark (media query)': new Map([...base, ...tokens(blockAfter(":root:not([data-theme='light']) {"))]),
};

function value(theme, name) {
    const raw = theme.get(name);
    assert.ok(raw !== undefined, `${name} is defined`);
    const alias = /^var\((--[\w-]+)\)$/.exec(raw);

    return alias === null ? raw : value(theme, alias[1]);
}

const rgb = (hex) => {
    assert.match(hex, /^#[0-9a-f]{6}$/i, `${hex} is a hex colour`);

    return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
};
const channel = (c) => ((c /= 255) <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const luminance = ([r, g, b]) => 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
const contrast = (a, b) => {
    const [x, y] = [luminance(a), luminance(b)];

    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
};
// color-mix(in srgb, A p%, B): a straight blend of the sRGB channels
const mix = (a, b, percent) => a.map((v, i) => (v * percent) / 100 + (b[i] * (100 - percent)) / 100);

test('the stylesheet defines a tint and an ink for every heat step the code can produce, and nothing else', () => {
    for (const [name, theme] of Object.entries(themes)) {
        for (let step = 1; step <= HEAT_STEPS; step++) {
            assert.match(value(theme, `--heat-${step}`), /^\d+%$/, `${name}: tint ${step}`);
            assert.ok(theme.has(`--heat-ink-${step}`), `${name}: ink ${step}`);
            assert.match(css, new RegExp(`\\.heat-${step} \\{`), `class heat-${step}`);
        }
        assert.ok(!theme.has(`--heat-${HEAT_STEPS + 1}`), `${name}: no step beyond the last`);
    }
    assert.ok(!css.includes(`.heat-${HEAT_STEPS + 1} {`));
});

test('the tints get stronger with every step and the last one is the full colour', () => {
    const tints = Array.from({ length: HEAT_STEPS }, (_, i) => Number.parseInt(value(themes.light, `--heat-${i + 1}`), 10));

    assert.deepEqual([...tints].sort((a, b) => a - b), tints);
    assert.equal(new Set(tints).size, tints.length);
    assert.equal(tints[HEAT_STEPS - 1], 100);
});

for (const [name, theme] of Object.entries(themes)) {
    test(`${name}: the text of every heat step has at least 4.5:1 against its tint`, () => {
        const colour = rgb(value(theme, '--chart-1'));
        const surface = rgb(value(theme, '--surface'));

        for (let step = 1; step <= HEAT_STEPS; step++) {
            const background = mix(colour, surface, Number.parseInt(value(theme, `--heat-${step}`), 10));
            const ratio = contrast(rgb(value(theme, `--heat-ink-${step}`)), background);

            assert.ok(ratio >= 4.5, `step ${step} has ${ratio.toFixed(2)}:1`);
        }
    });

    test(`${name}: the text of a share of 0, of a period that has not started and of the table head stays readable`, () => {
        const ink = (token) => rgb(value(theme, token));

        assert.ok(contrast(ink('--fg'), ink('--surface')) >= 4.5, 'text on the surface (a share of 0, the sticky columns)');
        assert.ok(contrast(ink('--muted'), ink('--canvas')) >= 4.5, 'not yet: text on the canvas');
        assert.ok(contrast(ink('--muted'), ink('--line')) >= 4.5, 'not yet: text on a hatch stripe');
        assert.ok(contrast(ink('--line-strong'), ink('--surface')) >= 3, 'the border of a cell');
    });
}

test('both ways of selecting the dark scheme give the same heat map', () => {
    const [data, media] = [themes['dark (data-theme)'], themes['dark (media query)']];
    const heat = (theme) => [...theme].filter(([key]) => key.startsWith('--heat') || ['--chart-1', '--surface', '--fg', '--accent-fg', '--canvas', '--line', '--muted'].includes(key));

    assert.deepEqual(heat(media), heat(data));
});
