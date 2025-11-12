import Route from '@ember/routing/route';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import config from '../config/environment';
import isElectron from '@fleetbase/ember-core/utils/is-electron';
import pathToRoute from '@fleetbase/ember-core/utils/path-to-route';
import removeBootLoader from '../utils/remove-boot-loader';

export default class ApplicationRoute extends Route {
    @service session;
    @service theme;
    @service fetch;
    @service urlSearchParams;
    @service modalsManager;
    @service intl;
    @service currentUser;
    @service router;
    @service universe;
    @tracked defaultTheme;

    /**
     * Handle the transition into the application.
     *
     * @memberof ApplicationRoute
     */
    @action willTransition(transition) {
        this.universe.callHooks('application:will-transition', this.session, this.router, transition);
    }

    /**
     * On application route activation
     *
     * @memberof ApplicationRoute
     * @void
     */
    @action activate() {
        this.initializeTheme();
        this.initializeLocale();
    }

    /**
     * The application loading event.
     * Here will just run extension hooks.
     *
     * @memberof ApplicationRoute
     */
    @action loading(transition) {
        this.universe.callHooks('application:loading', this.session, this.router, transition);
    }

    /**
     * Check the installation status of Fleetbase and transition user accordingly.
     *
     * @return {void|Transition}
     * @memberof ApplicationRoute
     */
    // eslint-disable-next-line ember/classic-decorator-hooks
    async init() {
        super.init(...arguments);

        // REEUP Integration: Setup token-based authentication
        console.log('[REEUP] Initializing Fleetbase Console with REEUP integration');
        this.setupREEUPAuthListener();

        const { shouldInstall, shouldOnboard, defaultTheme } = await this.checkInstallationStatus();

        this.defaultTheme = defaultTheme;

        if (shouldInstall) {
            return this.router.transitionTo('install');
        }

        if (shouldOnboard) {
            return this.router.transitionTo('onboard');
        }
    }

    /**
     * Setup REEUP postMessage authentication listener for token-based auth.
     * Receives Sanctum token from parent REEUP window and directly authenticates.
     *
     * @memberof ApplicationRoute
     */
    setupREEUPAuthListener() {
        if (typeof window === 'undefined') return;

        console.log('[REEUP] Setting up token-based authentication listener');

        window.addEventListener('message', async (event) => {
            // Security: Only accept messages from configured REEUP origins
            const allowedOrigins = config.reeup?.allowedOrigins || ['http://localhost:3000'];

            if (!allowedOrigins.includes(event.origin)) {
                console.log(`[REEUP] Rejected message from unauthorized origin: ${event.origin}`);
                return;
            }

            // Handle token-based authentication (PREFERRED METHOD)
            if (event.data.type === 'REEUP_FLEETBASE_TOKEN') {
                const { token, email } = event.data;
                console.log('[REEUP] Received Sanctum token for:', email);

                try {
                    // Use session.manuallyAuthenticate to directly authenticate with token
                    await this.session.manuallyAuthenticate(token);
                    console.log('[REEUP] ✅ Successfully authenticated with token');

                    // Notify parent of success BEFORE reload
                    if (window.parent !== window) {
                        window.parent.postMessage({
                            type: 'REEUP_FLEETBASE_AUTH_SUCCESS',
                            email
                        }, event.origin);
                    }

                    // Reload the page to complete authentication (same as impersonation feature)
                    // This is required for Fleetbase Console to fully load the authenticated session
                    console.log('[REEUP] Reloading console to complete authentication...');
                    setTimeout(() => {
                        window.location.reload();
                    }, 300);
                } catch (error) {
                    console.error('[REEUP] ❌ Token authentication failed:', error);

                    // Notify parent of failure
                    if (window.parent !== window) {
                        window.parent.postMessage({
                            type: 'REEUP_FLEETBASE_AUTH_FAILURE',
                            error: error.message || 'Authentication failed'
                        }, event.origin);
                    }
                }
            }

            // Handle credential auto-fill (FALLBACK METHOD for backwards compatibility)
            if (event.data.type === 'REEUP_FLEETBASE_CREDENTIALS') {
                const { email, password } = event.data;
                console.log('[REEUP] Received credentials for auto-fill:', email);

                // Auto-fill login form with retry logic
                const fillLoginForm = () => {
                    const emailField = document.querySelector('input[name="email"], input[type="email"]');
                    const passwordField = document.querySelector('input[name="password"], input[type="password"]');

                    if (emailField && passwordField && email && password) {
                        console.log('[REEUP] Filling login form');

                        // Fill fields
                        emailField.value = email;
                        passwordField.value = password;

                        // Trigger Ember events
                        emailField.dispatchEvent(new Event('input', { bubbles: true }));
                        emailField.dispatchEvent(new Event('change', { bubbles: true }));
                        passwordField.dispatchEvent(new Event('input', { bubbles: true }));
                        passwordField.dispatchEvent(new Event('change', { bubbles: true }));

                        // Submit after brief delay
                        setTimeout(() => {
                            const form = emailField.closest('form');
                            if (form) {
                                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                                console.log('[REEUP] Form submitted');
                            }
                        }, 200);
                    } else {
                        // Retry if form not ready
                        setTimeout(fillLoginForm, 100);
                    }
                };

                fillLoginForm();
            }
        });

        // Signal ready to parent window
        if (window.parent !== window) {
            const allowedOrigins = config.reeup?.allowedOrigins || ['http://localhost:3000'];
            allowedOrigins.forEach(origin => {
                window.parent.postMessage({ type: 'REEUP_FLEETBASE_READY' }, origin);
            });
            console.log('[REEUP] Sent READY signal to parent');
        }
    }

    /**
     * Sets up session and handles redirects
     *
     * @param {Transition} transition
     * @return {Transition}
     * @memberof ApplicationRoute
     */
    async beforeModel(transition) {
        await this.session.setup();
        await this.universe.booting();

        this.universe.callHooks('application:before-model', this.session, this.router, transition);

        const shift = this.urlSearchParams.get('shift');
        if (this.session.isAuthenticated && shift) {
            return this.router.transitionTo(pathToRoute(shift));
        }
    }

    /**
     * Remove boot loader if not authenticated.
     *
     * @memberof ApplicationRoute
     */
    afterModel() {
        if (!this.session.isAuthenticated) {
            removeBootLoader();
        }
    }

    /**
     * Initializes the application's theme settings, applying necessary class names and default theme configurations.
     *
     * This method prepares the theme by setting up an array of class names that should be applied to the
     * application's body element. If the application is running inside an Electron environment, it adds the
     * `'is-electron'` class to the array. It then calls the `initialize` method of the `theme` service,
     * passing in the `bodyClassNames` array and the `defaultTheme` configuration.
     */
    initializeTheme() {
        const bodyClassNames = [];

        if (isElectron()) {
            bodyClassNames.pushObject(['is-electron']);
        }

        this.theme.initialize({ bodyClassNames, theme: this.defaultTheme });
    }

    /**
     * Initializes the application's locale settings based on the current user's preferences.
     *
     * This method retrieves the user's preferred locale using the `getOption` method from the `currentUser` service.
     * If no locale is set by the user, it defaults to `'en-us'`. It then sets the application's locale by calling
     * the `setLocale` method of the `intl` service with the retrieved locale.
     */
    initializeLocale() {
        const locale = this.currentUser.getOption('locale', 'en-us');
        this.intl.setLocale([locale]);
    }

    /**
     * Checks to determine if Fleetbase should be installed or user needs to onboard.
     *
     * @return {Promise}
     * @memberof ApplicationRoute
     */
    checkInstallationStatus() {
        return this.fetch.get('installer/initialize');
    }
}
