/**
 * Human-readable provider name for filter/select labels.
 * @param {string | null | undefined} provider
 * @returns {string}
 */
export function providerLabel(provider) {
    if (provider === 'mercadolibre') return 'Mercado Libre';
    return provider || '—';
}

/**
 * Compact label for connection filter options.
 * @param {{ provider?: string | null, display_name?: string | null, external_user_id?: string | null }} c
 * @returns {string}
 */
export function connectionFilterLabel(c) {
    const account = c?.display_name?.trim() || c?.external_user_id || '—';
    return `${providerLabel(c?.provider)} · ${account}`;
}
