import config from '@fleetbase/console/config/environment';

/**
 * Instance initializer that ensures the fetch service uses the correct API host
 * after runtime config has been loaded.
 *
 * This fixes a race condition where the fetch service's fallback host
 * (window.location.protocol + hostname) is set at module load time before
 * the runtime config (fleetbase.config.json) has been fetched and applied.
 *
 * @export
 * @param {ApplicationInstance} appInstance
 */
export function initialize(appInstance) {
    const fetchService = appInstance.lookup('service:fetch');

    // Update fetch service host to use the runtime config value
    // This ensures it uses the correct host from fleetbase.config.json
    // instead of the fallback value set at module load time
    if (fetchService && config.API && config.API.host) {
        fetchService.host = config.API.host;
        console.log('[Fetch Service] Host refreshed to:', fetchService.host);
    }
}

export default {
    name: 'refresh-fetch-host',
    after: 'ember-simple-auth',
    initialize
};
