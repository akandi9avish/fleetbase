/**
 * Instance initializer to ensure our custom BFF-aware session service is used
 * ============================================================================
 *
 * This initializer explicitly registers and sets up the custom session service
 * to ensure Ember uses it instead of ember-simple-auth's default SessionService.
 *
 * Problem:
 * - Ember's service lookup can be ambiguous when extending addon services
 * - Our custom session service at app/services/session.js may not automatically
 *   override ember-simple-auth/services/session
 *
 * Solution:
 * - Explicitly look up and initialize the session service during app boot
 * - This ensures BFF authentication detection runs before any routes load
 *
 * @export
 * @param {ApplicationInstance} appInstance
 */
export function initialize(appInstance) {
    console.log('[REEUP Session Initializer] ========================================');
    console.log('[REEUP Session Initializer] Forcing custom session service to load');
    console.log('[REEUP Session Initializer] ========================================');

    try {
        // Explicitly look up the session service
        // This forces Ember to instantiate our custom service
        const sessionService = appInstance.lookup('service:session');

        console.log('[REEUP Session Initializer] Session service loaded:', sessionService.constructor.name);
        console.log('[REEUP Session Initializer] Has isBffMode property:', 'isBffMode' in sessionService);
        console.log('[REEUP Session Initializer] Has detectBffMode method:', typeof sessionService.detectBffMode === 'function');

        // Verify we got the custom service, not the default
        if (typeof sessionService.detectBffMode !== 'function') {
            console.error('[REEUP Session Initializer] ✗✗✗ CRITICAL: Custom session service NOT loaded!');
            console.error('[REEUP Session Initializer] Ember is using ember-simple-auth default instead');
            console.error('[REEUP Session Initializer] Service type:', sessionService.constructor.name);
        } else {
            console.log('[REEUP Session Initializer] ✓✓✓ Custom BFF session service loaded successfully');
        }
    } catch (error) {
        console.error('[REEUP Session Initializer] ✗✗✗ Failed to initialize session service:', error);
        console.error('[REEUP Session Initializer] Error stack:', error.stack);
    }

    console.log('[REEUP Session Initializer] ========================================');
}

export default {
    name: 'setup-session-service',
    // Run AFTER refresh-fetch-host but BEFORE application route loads
    after: 'refresh-fetch-host',
    initialize
};
