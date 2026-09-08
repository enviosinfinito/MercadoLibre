/**
 * Curated palette — keep in sync with App\Support\ConnectionColorPalette
 * @type {readonly string[]}
 */
export const CONNECTION_COLOR_PALETTE = Object.freeze([
    '#0f766e',
    '#0284c7',
    '#d97706',
    '#7c3aed',
    '#dc2626',
    '#059669',
    '#ea580c',
    '#4f46e5',
]);

const FALLBACK = CONNECTION_COLOR_PALETTE[0];

/**
 * @param {string | null | undefined} hex
 * @returns {string | null}
 */
export function normalizeConnectionColor(hex) {
    if (typeof hex !== 'string') return null;
    const trimmed = hex.trim();
    if (!/^#[0-9A-Fa-f]{6}$/.test(trimmed)) return null;
    return trimmed.toLowerCase();
}

/**
 * @param {string | null | undefined} hex
 * @param {number} [index=0]
 * @returns {string}
 */
export function resolveConnectionColor(hex, index = 0) {
    return (
        normalizeConnectionColor(hex) ??
        CONNECTION_COLOR_PALETTE[Math.abs(index) % CONNECTION_COLOR_PALETTE.length] ??
        FALLBACK
    );
}

/**
 * Darken a hex color for readable foreground text on tinted chips.
 * @param {string} hex
 * @param {number} [amount=0.35]
 * @returns {string}
 */
function darkenHex(hex, amount = 0.35) {
    const n = normalizeConnectionColor(hex) ?? FALLBACK;
    const r = parseInt(n.slice(1, 3), 16);
    const g = parseInt(n.slice(3, 5), 16);
    const b = parseInt(n.slice(5, 7), 16);
    const mix = (c) => Math.round(c * (1 - amount));
    const to = (c) => mix(c).toString(16).padStart(2, '0');
    return `#${to(r)}${to(g)}${to(b)}`;
}

/**
 * Inline style object with CSS custom properties for connection surfaces.
 * @param {string | null | undefined} hex
 * @param {number} [index=0]
 * @returns {Record<string, string>}
 */
export function connectionSurfaceStyle(hex, index = 0) {
    const color = resolveConnectionColor(hex, index);
    return {
        '--conn': color,
        '--conn-wash': `color-mix(in srgb, ${color} 8%, transparent)`,
        '--conn-chip': `color-mix(in srgb, ${color} 16%, transparent)`,
        '--conn-fg': darkenHex(color, 0.28),
        '--conn-border': `color-mix(in srgb, ${color} 32%, transparent)`,
        '--conn-hover': `color-mix(in srgb, ${color} 14%, white)`,
    };
}
