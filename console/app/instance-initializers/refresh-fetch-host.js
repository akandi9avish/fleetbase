import config from '@fleetbase/console/config/environment';

/**
 * Instance initializer that ensures the fetch service uses the correct API host
 * and namespace after runtime config has been loaded.
 *
 * This fixes a race condition where the fetch service's fallback host
 * (window.location.protocol + hostname) is set at module load time before
 * the runtime config (fleetbase.config.json) has been fetched and applied.
 *
 * REEUP BFF Integration:
 * - API_HOST should be http://localhost:3000 (REEUP frontend)
 * - API_NAMESPACE should be 'api/fleetbase-proxy' (BFF proxy route)
 * - All API calls then go through the BFF proxy which adds auth tokens
 *
 * @export
 * @param {ApplicationInstance} appInstance
 */
export function initialize(appInstance) {
    const fetchService = appInstance.lookup('service:fetch');

    console.log('[Fetch Service] ========================================');
    console.log('[Fetch Service] Refreshing fetch service configuration');
    console.log('[Fetch Service] Current host:', fetchService?.host);
    console.log('[Fetch Service] Current namespace:', fetchService?.namespace);
    console.log('[Fetch Service] Config API.host:', config.API?.host);
    console.log('[Fetch Service] Config API.namespace:', config.API?.namespace);

    // Update fetch service host to use the runtime config value
    // This ensures it uses the correct host from fleetbase.config.json
    // instead of the fallback value set at module load time
    if (fetchService && config.API) {
        if (config.API.host) {
            fetchService.host = config.API.host;
            console.log('[Fetch Service] ✓ Host refreshed to:', fetchService.host);
        }

        // CRITICAL: Also update namespace for BFF proxy routing
        // Without this, calls go to /int/v1/* instead of /api/fleetbase-proxy/v1/*
        if (config.API.namespace) {
            fetchService.namespace = config.API.namespace;
            console.log('[Fetch Service] ✓ Namespace refreshed to:', fetchService.namespace);
        }
    }

    console.log('[Fetch Service] Final host:', fetchService?.host);
    console.log('[Fetch Service] Final namespace:', fetchService?.namespace);
    console.log('[Fetch Service] ========================================');
}

export default {
    name: 'refresh-fetch-host',
    initialize,
};
