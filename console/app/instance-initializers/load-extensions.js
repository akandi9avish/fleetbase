export function initialize(application) {
    const universe = application.lookup('service:universe');
    if (universe) {
        universe.createRegistries(['@fleetbase/console', 'auth:login']);
        try {
            universe.bootEngines(application);
        } catch (error) {
            console.warn('[LOAD EXTENSIONS] Failed to boot engines - this is OK if no extensions are installed:', error);
            // Continue loading - extensions are optional
        }
    }
}

export default {
    name: 'load-extensions',
    initialize,
};
