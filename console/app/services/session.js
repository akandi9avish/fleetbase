import Service from '@ember/service';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import SessionService from 'ember-simple-auth/services/session';

/**
 * Custom Session Service for REEUP BFF Integration
 * ================================================
 *
 * This service extends ember-simple-auth to support transparent authentication
 * when running inside REEUP via the BFF (Backend-for-Frontend) proxy.
 *
 * BFF Architecture:
 * 1. Console runs inside REEUP iframe
 * 2. All API calls go through Next.js BFF proxy (/api/fleetbase-proxy/*)
 * 3. BFF proxy injects Fleetbase Sanctum token server-side
 * 4. Console never sees tokens directly (zero-trust security)
 *
 * Traditional Flow (standalone Fleetbase):
 * - User logs in → ember-simple-auth stores session → API calls use session token
 *
 * BFF Flow (inside REEUP):
 * - Console loads → This service detects BFF mode → Auto-authenticates
 * - All API calls automatically authenticated by BFF proxy
 * - No session tokens needed in browser (XSS-proof)
 */
export default class CustomSessionService extends SessionService {
    @service fetch;
    @service currentUser;
    @tracked isBffMode = false;

    /**
     * Setup session - called on app initialization
     * Detects BFF mode and auto-authenticates if needed
     */
    async setup() {
        console.log('[REEUP Session] ========================================');
        console.log('[REEUP Session] Initializing CUSTOM session service');
        console.log('[REEUP Session] Service class:', this.constructor.name);
        console.log('[REEUP Session] Window location:', window.location.href);
        console.log('[REEUP Session] ========================================');

        // Check if we're running in BFF mode
        this.isBffMode = await this.detectBffMode();

        console.log('[REEUP Session] BFF Mode Result:', this.isBffMode);

        if (this.isBffMode) {
            console.log('[REEUP Session] ✓ BFF mode CONFIRMED - attempting auto-authentication');

            // In BFF mode, try to authenticate automatically
            try {
                await this.authenticateViaBff();
                console.log('[REEUP Session] ✓✓✓ BFF auto-authentication SUCCESSFUL');
            } catch (error) {
                console.error('[REEUP Session] ✗✗✗ BFF auto-authentication FAILED:', error);
                console.error('[REEUP Session] Error stack:', error.stack);
                // Don't throw - let normal auth flow handle it
            }
        } else {
            console.log('[REEUP Session] Standalone mode - using standard authentication');
            // Call parent setup for normal authentication flow
            await super.setup();
        }

        console.log('[REEUP Session] Setup complete. isAuthenticated:', this.isAuthenticated);
    }

    /**
     * Detect if running in BFF proxy mode
     * BFF mode is when API_HOST is same-origin (proxied through Next.js)
     */
    async detectBffMode() {
        try {
            console.log('[REEUP Session] Detecting BFF mode...');
            console.log('[REEUP Session] this.fetch:', typeof this.fetch);

            // Check if API_HOST is same-origin
            const apiHost = this.fetch.apiHost;
            const currentOrigin = window.location.origin;

            console.log('[REEUP Session] API Host:', apiHost, '(type:', typeof apiHost, ')');
            console.log('[REEUP Session] Current Origin:', currentOrigin);

            // If API_HOST starts with current origin, we're in BFF mode
            const isBff = apiHost && apiHost.startsWith(currentOrigin);
            console.log('[REEUP Session] BFF Mode Check: apiHost.startsWith(currentOrigin) =', isBff);

            return isBff;
        } catch (error) {
            console.error('[REEUP Session] ✗ Error detecting BFF mode:', error);
            console.error('[REEUP Session] Error stack:', error.stack);
            return false;
        }
    }

    /**
     * Authenticate via BFF proxy
     * Makes a test API call to verify BFF authentication is working
     */
    async authenticateViaBff() {
        try {
            // Make a test API call to verify authentication
            // Use a lightweight endpoint that requires authentication
            const response = await this.fetch.get('auth/session', {}, { namespace: 'int/v1' });

            if (response && (response.id || response.user)) {
                const user = response.user || response;
                console.log('[REEUP Session] ✓ BFF authentication verified - User:', user.name || user.email || user.id);

                // Manually set authenticated state
                // In BFF mode, we don't need a token in the browser
                this.set('isAuthenticated', true);
                this.set('data', {
                    authenticated: {
                        user: user,
                        authenticatedVia: 'bff-proxy',
                        token: 'bff-managed' // Placeholder - actual token is in BFF server
                    }
                });

                // Try to load current user into service
                try {
                    if (this.currentUser && typeof this.currentUser.load === 'function') {
                        this.currentUser.load(user);
                    }
                } catch (userLoadError) {
                    console.warn('[REEUP Session] Could not load user into currentUser service:', userLoadError);
                    // Non-fatal - continue anyway
                }

                return true;
            } else {
                throw new Error('Invalid session response from BFF');
            }
        } catch (error) {
            console.error('[REEUP Session] BFF authentication test failed:', error);
            throw error;
        }
    }

    /**
     * Override isAuthenticated getter
     * In BFF mode, always return true if we've successfully authenticated via BFF
     */
    get isAuthenticated() {
        if (this.isBffMode && this.data?.authenticated?.authenticatedVia === 'bff-proxy') {
            return true;
        }
        return super.isAuthenticated;
    }

    /**
     * Override invalidate method
     * In BFF mode, we can't actually invalidate server-side session
     * Just clear local state
     */
    async invalidate() {
        if (this.isBffMode) {
            console.log('[REEUP Session] BFF mode - clearing local session state');
            this.set('isAuthenticated', false);
            this.set('data', {});
            // Notify parent REEUP app to handle logout
            if (window.parent !== window) {
                window.parent.postMessage({ type: 'fleetbase:logout' }, '*');
            }
            return;
        }

        // Normal mode - call parent invalidate
        return super.invalidate();
    }

    /**
     * Override requireAuthentication
     * In BFF mode, don't redirect to login - we're already authenticated via BFF
     */
    requireAuthentication(transition, routeOrCallback) {
        if (this.isBffMode && this.isAuthenticated) {
            // Already authenticated via BFF - no action needed
            return;
        }

        // Normal mode - call parent requireAuthentication
        return super.requireAuthentication(transition, routeOrCallback);
    }
}
