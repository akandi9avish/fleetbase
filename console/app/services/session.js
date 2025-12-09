import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import SessionService from '@fleetbase/ember-core/services/session';
import EphemeralStore from '../session-stores/ephemeral';
import config from 'ember-get-config';

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
    @tracked _data = null;  // Stores BFF session data

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

            // Use this.fetch.host (NOT apiHost - that property doesn't exist)
            // Also check config directly in case fetch service hasn't been updated yet
            const apiHost = this.fetch.host || config?.API?.host;
            const currentOrigin = window.location.origin;

            // Check for reeupConfigUrl query param (most reliable indicator)
            // This is passed from REEUP parent via iframe src URL
            const urlParams = new URLSearchParams(window.location.search);
            const reeupConfigUrl = urlParams.get('reeupConfigUrl');

            // Check config flags from runtime-config.js (may include URL param data)
            const isInIframe = config?._isInIframe === true;
            const reeupConfigFromConfig = config?._reeupConfigUrl;
            const parentOrigin = config?._parentOrigin || reeupConfigUrl;
            const isReeupEmbedded = config?._isReeupEmbedded === true || !!reeupConfigUrl;
            const allowedOrigins = config?.reeup?.allowedOrigins || [];

            console.log('[REEUP Session] === BFF Detection Debug ===');
            console.log('[REEUP Session] API Host (fetch.host):', this.fetch.host);
            console.log('[REEUP Session] API Host (config.API.host):', config?.API?.host);
            console.log('[REEUP Session] Using API Host:', apiHost);
            console.log('[REEUP Session] Current Origin:', currentOrigin);
            console.log('[REEUP Session] reeupConfigUrl param:', reeupConfigUrl);
            console.log('[REEUP Session] Parent origin:', parentOrigin);
            console.log('[REEUP Session] Is REEUP embedded:', isReeupEmbedded);
            console.log('[REEUP Session] Is in iframe:', isInIframe);

            // Scenario 1: Same-origin (standalone reeup.co)
            if (apiHost && apiHost.startsWith(currentOrigin)) {
                console.log('[REEUP Session] BFF Mode: TRUE (same-origin)');
                return true;
            }

            // Scenario 2: Cross-origin REEUP iframe (via URL param or referrer)
            if (parentOrigin && (isInIframe || reeupConfigUrl || reeupConfigFromConfig)) {
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

            // Scenario 4: Same TLD (*.reeup.co) - Console on console.reeup.co, API on www.reeup.co
            const currentHostname = window.location.hostname;
            if (apiHost && currentHostname.endsWith('.reeup.co') && apiHost.includes('reeup.co')) {
                console.log('[REEUP Session] BFF Mode: TRUE (same TLD *.reeup.co)');
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
     * Uses ember-simple-auth's proper session persistence mechanism
     */
    async authenticateViaBff() {
        try {
            // Make a test API call to verify authentication
            // Use a lightweight endpoint that requires authentication
            console.log('[REEUP Session] Calling auth/session to verify BFF authentication...');
            const response = await this.fetch.get('auth/session', {}, { namespace: 'int/v1' });
            console.log('[REEUP Session] auth/session response:', JSON.stringify(response));

            if (response && response.token) {
                console.log('[REEUP Session] ✓ BFF authentication verified - Token received');

                // Build session data structure that ember-simple-auth expects
                const sessionData = {
                    authenticated: {
                        authenticator: 'authenticator:bff',
                        token: response.token,
                        user: response.user,
                        verified: response.verified,
                        type: response.type,
                        authenticatedVia: 'bff-proxy'
                    }
                };

                // Use the store's persist method to properly set session state
                // This triggers ember-simple-auth's internal session setup
                console.log('[REEUP Session] Persisting session data to store...');
                await this.store.persist(sessionData);

                // Update the internal data reference for our getter overrides
                this._data = sessionData;

                console.log('[REEUP Session] ✓ Session data persisted successfully');
                console.log('[REEUP Session] isAuthenticated check:', this.isAuthenticated);

                // Load the current user - needs to fetch full user details
                try {
                    console.log('[REEUP Session] Loading current user...');
                    await this.loadCurrentUser();
                    console.log('[REEUP Session] ✓ Current user loaded');
                } catch (userLoadError) {
                    console.warn('[REEUP Session] Could not load current user:', userLoadError);
                    // Non-fatal - the session is still valid
                }

                return true;
            } else {
                console.error('[REEUP Session] Invalid response - no token:', response);
                throw new Error('Invalid session response from BFF - no token received');
            }
        } catch (error) {
            console.error('[REEUP Session] BFF authentication failed:', error);
            throw error;
        }
    }

    /**
     * Override isAuthenticated getter
     * In BFF mode, check our _data for BFF authentication
     */
    get isAuthenticated() {
        // Check our local _data first (set during BFF auth)
        if (this.isBffMode && this._data?.authenticated?.authenticatedVia === 'bff-proxy') {
            return true;
        }
        return super.isAuthenticated;
    }

    /**
     * Override data getter to return our _data in BFF mode
     * This ensures other parts of the app can access session data
     */
    get data() {
        if (this.isBffMode && this._data && this._data.authenticated) {
            return this._data;
        }
        return super.data;
    }

    /**
     * Override invalidate method
     * In BFF mode, we can't actually invalidate server-side session
     * Just clear local state
     */
    async invalidate() {
        if (this.isBffMode) {
            console.log('[REEUP Session] BFF mode - clearing local session state');
            this._data = {};
            await this.store.clear();
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
