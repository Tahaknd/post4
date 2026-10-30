/**
 * Groups IANA time zone identifiers by region for a native <select> with <optgroup>s (keyboard type-ahead works, and it needs
 * no script to be accessible): "Europe/Istanbul" goes to "Europe", an identifier without a slash ("UTC") to "Other". Regions
 * come in the order of their first zone and the zones keep the order of the list.
 *
 * @param {string[]} zones
 * @returns {{label: string, options: string[]}[]}
 */
export function groupTimezones(zones) {
    const byRegion = new Map();

    for (const zone of zones) {
        const region = zone.includes('/') ? zone.split('/')[0] : 'Other';

        if (!byRegion.has(region)) {
            byRegion.set(region, []);
        }

        byRegion.get(region).push(zone);
    }

    return [...byRegion].map(([label, options]) => ({ label, options }));
}
