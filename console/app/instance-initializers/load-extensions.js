/**
 * Load extensions from the API using ExtensionManager
 * This must run before other initializers that depend on extensions
 */
export async function initialize(appInstance) {
    const application = appInstance.application;
    const extensionManager = appInstance.lookup('service:universe/extension-manager');

    try {
        await extensionManager.loadExtensions(application);
    } catch (error) {
        console.warn('[load-extensions] Failed to load extensions - this is OK if no extensions are installed:', error);
        // Continue loading - extensions are optional
    }
}

export default {
    name: 'load-extensions',
    initialize,
};
