import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import SessionService from '@fleetbase/ember-core/services/session';
import EphemeralStore from '../session-stores/ephemeral';

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
 * - Uses ephemeral (in-memory) session store to avoid cross-origin localStorage issues
 *
 * Cross-Origin iframe Storage Issue:
 * - Browsers block localStorage/sessionStorage access in cross-origin iframes
 * - ember-simple-auth default stores fail silently, then auto-invalidate session
 * - Ephemeral store keeps session in memory only, no storage access needed
 */
export default class CustomSessionService extends SessionService {
    @service fetch;
    @service currentUser;
    @tracked isBffMode = false;
    @tracked _ephemeralStore = null;

    /**
     * Override the store property to use ephemeral store in BFF mode
     * This prevents cross-origin localStorage issues in iframes
     */
    get store() {
        if (this.isBffMode) {
            if (!this._ephemeralStore) {
                console.log('[REEUP Session] Creating ephemeral session store for BFF mode');
                this._ephemeralStore = EphemeralStore.create();
            }
            return this._ephemeralStore;
        }
        // Use default store for standalone mode
        return super.store;
    }

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

        // Check if we're running in BFF mode FIRST
        // This must be set before any session operations because it affects the store
        this.isBffMode = await this.detectBffMode();

        console.log('[REEUP Session] BFF Mode Result:', this.isBffMode);
        console.log('[REEUP Session] Session store type:', this.store.constructor.name);

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
     * or when running in a REEUP iframe with cross-origin config
     */
    async detectBffMode() {
        try {
            console.log('[REEUP Session] Detecting BFF mode...');

            const apiHost = this.fetch.apiHost;
            const currentOrigin = window.location.origin;

            // Check config flags from runtime-config.js
            const isInIframe = config._isInIframe === true;
            const parentOrigin = config._parentOrigin;
            const isReeupEmbedded = config._isReeupEmbedded === true;
            const allowedOrigins = config.reeup?.allowedOrigins || [];

            console.log('[REEUP Session] API Host:', apiHost);
            console.log('[REEUP Session] Current Origin:', currentOrigin);
            console.log('[REEUP Session] Is in iframe:', isInIframe);
            console.log('[REEUP Session] Parent origin:', parentOrigin);
            console.log('[REEUP Session] Is REEUP embedded:', isReeupEmbedded);

            // Scenario 1: Same-origin (original check)
            if (apiHost && apiHost.startsWith(currentOrigin)) {
                console.log('[REEUP Session] BFF Mode: TRUE (same-origin)');
                return true;
            }

            // Scenario 2: Cross-origin REEUP iframe
            if (isInIframe && isReeupEmbedded && parentOrigin) {
                if (apiHost && apiHost.startsWith(parentOrigin)) {
                    console.log('[REEUP Session] BFF Mode: TRUE (cross-origin iframe)');
                    return true;
                }
            }

            // Scenario 3: API_HOST in allowedOrigins
            if (apiHost && allowedOrigins.some(origin => apiHost.startsWith(origin))) {
                console.log('[REEUP Session] BFF Mode: TRUE (allowedOrigins)');
                return true;
            }

            console.log('[REEUP Session] BFF Mode: FALSE');
            return false;
        } catch (error) {
            console.error('[REEUP Session] Error detecting BFF mode:', error);
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

    /**
     * Check for two-factor authentication
     * Explicitly forward to parent class to ensure method is available
     * (fixes potential inheritance issues with Ember service resolution)
     */
    checkForTwoFactor(identity) {
        return this.fetch.get('two-fa/check', { identity }).catch((error) => {
            throw new Error(error.message);
        });
    }

    /**
     * Load current user - forward to parent
     */
    async loadCurrentUser() {
        return super.loadCurrentUser();
    }

    /**
     * Promise current user - forward to parent
     */
    async promiseCurrentUser(transition = null) {
        return super.promiseCurrentUser(transition);
    }

    /**
     * Handle authentication - forward to parent
     */
    async handleAuthentication() {
        return super.handleAuthentication();
    }
}
