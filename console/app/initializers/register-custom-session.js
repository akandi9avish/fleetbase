/**
 * Application Initializer to Register Custom Session Service
 * ===========================================================
 */
import CustomSessionService from '../services/session';

// REEUP DEBUG: Log when this module is evaluated
console.log('[REEUP Initializer Module] ========================================');
console.log('[REEUP Initializer Module] register-custom-session.js loaded');
console.log('[REEUP Initializer Module] CustomSessionService:', CustomSessionService?.name || 'loaded');

export function initialize(application) {
    console.log('[REEUP Session Registration] ========================================');
    console.log('[REEUP Session Registration] Registering custom BFF session service');

    // Unregister any existing session service first
    if (application.hasRegistration('service:session')) {
        console.log('[REEUP Session Registration] Unregistering existing session service');
        application.unregister('service:session');
    }

    // Register our custom session service
    application.register('service:session', CustomSessionService);

    console.log('[REEUP Session Registration] ✓ Custom session service registered');
    console.log('[REEUP Session Registration] ========================================');
}

export default {
    name: 'register-custom-session',
    initialize,
};
