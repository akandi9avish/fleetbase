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
    @service router;
    @tracked isBffMode = false;
    @tracked _ephemeralStore = null;
    @tracked _data = null; // Stores BFF session data

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

        // CRITICAL: Check for URL token BEFORE super.setup() to prevent redirect race condition
        // ember-simple-auth's route guards run during/after super.setup(), so we need
        // to be "authenticated" before then to prevent redirect to /auth
        const urlToken = this.getUrlToken();
        if (urlToken) {
            console.log('[REEUP Session] ✓ Found reeupToken in URL - PRE-AUTHENTICATING before super.setup()');

            // Pre-set BFF mode and session data BEFORE super.setup()
            this.isBffMode = true;

            // Initialize ephemeral store early
            if (!this._ephemeralStore) {
                console.log('[REEUP Session] Creating ephemeral session store (pre-auth)');
                this._ephemeralStore = EphemeralStore.create();
            }

            // Pre-populate session data so isAuthenticated returns true
            this._data = {
                authenticated: {
                    authenticator: 'authenticator:bff',
                    token: urlToken,
                    verified: true,
                    type: 'url-token-auth',
                    authenticatedVia: 'bff-proxy',
                },
            };

            // Persist to ephemeral store
            await this._ephemeralStore.persist(this._data);
            console.log('[REEUP Session] ✓ Pre-auth complete, isAuthenticated should be true');
        }

        // Now call parent setup - route guards should see us as authenticated
        console.log('[REEUP Session] Calling super.setup() to initialize ember-simple-auth...');
        await super.setup();
        console.log('[REEUP Session] ✓ ember-simple-auth initialized');

        // If we pre-authenticated with URL token, load user now
        if (urlToken) {
            console.log('[REEUP Session] Post-setup: loading current user...');
            try {
                await this.loadCurrentUser();
                console.log('[REEUP Session] ✓✓✓ URL token authentication COMPLETE');
            } catch (error) {
                console.warn('[REEUP Session] Could not load user after URL token auth:', error);
            }
            return; // Skip other auth methods
        }

        // Check if we're running in BFF mode (only if not already set by URL token)
        if (!this.isBffMode) {
            this.isBffMode = await this.detectBffMode();
        }

        console.log('[REEUP Session] BFF Mode Result:', this.isBffMode);
        console.log('[REEUP Session] Session store type:', this.store.constructor.name);

        // Set up postMessage listener for receiving auth token from parent REEUP app
        // This is essential for iframe authentication when cookies don't work
        this.setupPostMessageListener();

        // Try BFF cookie-based authentication
        if (this.isBffMode) {
            console.log('[REEUP Session] ✓ BFF mode CONFIRMED - attempting auto-authentication');

            // In BFF mode, try to authenticate automatically
            try {
                await this.authenticateViaBff();
                console.log('[REEUP Session] ✓✓✓ BFF auto-authentication SUCCESSFUL');
            } catch (error) {
                console.error('[REEUP Session] ✗✗✗ BFF auto-authentication FAILED:', error);
                console.error('[REEUP Session] Error stack:', error.stack);
                // Don't throw - postMessage auth may still work
                console.log('[REEUP Session] Waiting for postMessage auth from parent...');
            }
        } else {
            console.log('[REEUP Session] Standalone mode - standard authentication flow');
        }

        console.log('[REEUP Session] Setup complete. isAuthenticated:', this.isAuthenticated);
    }

    /**
     * Set up postMessage listener to receive auth token from parent REEUP app
     * This enables iframe authentication when cross-origin cookies don't work
     */
    setupPostMessageListener() {
        // Only set up listener if we're in an iframe
        if (window.parent === window) {
            console.log('[REEUP Session] Not in iframe - skipping postMessage listener');
            return;
        }

        console.log('[REEUP Session] Setting up postMessage listener for parent auth...');

        window.addEventListener('message', async (event) => {
            // Validate message origin (allow reeup.co and local.reeup.co domains)
            const origin = event.origin;
            const isValidOrigin = origin.endsWith('.reeup.co') || origin.includes('localhost') || origin.includes('127.0.0.1');

            if (!isValidOrigin) {
                console.log('[REEUP Session] Ignoring postMessage from untrusted origin:', origin);
                return;
            }

            // Check for REEUP auth token message
            if (event.data?.type === 'reeup:auth-token' && event.data?.token) {
                console.log('[REEUP Session] ✓ Received auth token via postMessage from:', origin);
                try {
                    await this.authenticateWithToken(event.data.token);
                    console.log('[REEUP Session] ✓✓✓ PostMessage authentication SUCCESSFUL');
                } catch (error) {
                    console.error('[REEUP Session] ✗ PostMessage authentication failed:', error);
                }
            }
        });

        console.log('[REEUP Session] ✓ PostMessage listener ready');
    }

    /**
     * Get REEUP token from URL query parameter
     * This is used when the parent app passes the token via URL for iframe auth
     * (workaround for cross-origin cookie partitioning)
     */
    getUrlToken() {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const token = urlParams.get('reeupToken');
            if (token) {
                console.log('[REEUP Session] Found reeupToken in URL params');
                // Security: Remove token from URL to prevent it appearing in logs/history
                // Use replaceState to clean up the URL without reloading
                const url = new URL(window.location.href);
                url.searchParams.delete('reeupToken');
                window.history.replaceState({}, '', url.toString());
                return token;
            }
            return null;
        } catch (error) {
            console.error('[REEUP Session] Error reading URL token:', error);
            return null;
        }
    }

    /**
     * Authenticate using a token received via postMessage
     * @param {string} token - Fleetbase Sanctum token
     */
    async authenticateWithToken(token) {
        if (!token) {
            throw new Error('No token provided');
        }

        console.log('[REEUP Session] Authenticating with provided token...');

        // Build session data structure that ember-simple-auth expects
        const sessionData = {
            authenticated: {
                authenticator: 'authenticator:bff',
                token: token,
                verified: true,
                type: 'postmessage-auth',
                authenticatedVia: 'bff-proxy',
            },
        };

        // Use the store's persist method to properly set session state
        console.log('[REEUP Session] Persisting postMessage session data to store...');
        await this.store.persist(sessionData);

        // Update the internal data reference for our getter overrides
        this._data = sessionData;

        // Enable BFF mode since we're being authenticated from parent
        this.isBffMode = true;

        console.log('[REEUP Session] ✓ PostMessage session data persisted');
        console.log('[REEUP Session] isAuthenticated check:', this.isAuthenticated);

        // Load the current user
        try {
            console.log('[REEUP Session] Loading current user after postMessage auth...');
            await this.loadCurrentUser();
            console.log('[REEUP Session] ✓ Current user loaded');
        } catch (userLoadError) {
            console.warn('[REEUP Session] Could not load current user:', userLoadError);
            // Non-fatal - the session is still valid
        }

        // Redirect to console dashboard after successful postMessage authentication
        // This is needed because the app is likely showing the login page
        try {
            console.log('[REEUP Session] Redirecting to console dashboard...');
            await this.router.transitionTo('console');
            console.log('[REEUP Session] ✓ Redirected to console dashboard');
        } catch (transitionError) {
            console.warn('[REEUP Session] Could not redirect to console:', transitionError);
        }
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
            if (apiHost && allowedOrigins.some((origin) => apiHost.startsWith(origin))) {
                console.log('[REEUP Session] BFF Mode: TRUE (allowedOrigins)');
                return true;
            }

            // Scenario 4: Same TLD (*.reeup.co or *.local.reeup.co) - Console on console.reeup.co, API on www.reeup.co
            const currentHostname = window.location.hostname;
            const isReeupTLD = currentHostname.endsWith('.reeup.co') || currentHostname.endsWith('.local.reeup.co');
            if (apiHost && isReeupTLD && apiHost.includes('reeup.co')) {
                console.log('[REEUP Session] BFF Mode: TRUE (same TLD *.reeup.co or *.local.reeup.co)');
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
            // IMPORTANT: Use native fetch with credentials: 'include' to send cookies
            // The Ember fetch service doesn't send credentials for cross-origin requests
            console.log('[REEUP Session] Calling auth/session to verify BFF authentication...');

            const apiHost = this.fetch.host || config?.API?.host;
            const authUrl = `${apiHost}/int/v1/auth/session`;
            console.log('[REEUP Session] Fetching from:', authUrl);

            const fetchResponse = await fetch(authUrl, {
                method: 'GET',
                credentials: 'include', // CRITICAL: Send cookies for cross-origin BFF auth
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
            });

            if (!fetchResponse.ok) {
                throw new Error(`HTTP ${fetchResponse.status}: ${fetchResponse.statusText}`);
            }

            const response = await fetchResponse.json();
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
                        authenticatedVia: 'bff-proxy',
                    },
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
