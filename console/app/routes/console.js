import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import removeBootLoader from '../utils/remove-boot-loader';
import '@fleetbase/leaflet-routing-machine';

export default class ConsoleRoute extends Route {
    @service store;
    @service session;
    @service universe;
    @service router;
    @service currentUser;
    @service intl;

    /**
     * Require authentication to access all `console` routes.
     *
     * @param {Transition} transition
     * @return {Promise}
     * @memberof ConsoleRoute
     */
    async beforeModel(transition) {
        await this.session.requireAuthentication(transition, 'auth.login');

        this.universe.callHooks('console:before-model', this.session, this.router, transition);

        if (this.session.isAuthenticated) {
            // Temporarily commented out to debug auth issue
            // The promiseCurrentUser() call is invalidating the session if user loading fails
            // return this.session.promiseCurrentUser(transition);

            // Instead, try to load user without invalidating session on failure
            try {
                await this.currentUser.promiseUser();
            } catch (error) {
                console.error('[CONSOLE ROUTE] Failed to load user:', error);
                // Don't invalidate session - let the user see the dashboard even if user loading fails
            }
        }
    }

    /**
     * Register after model hook.
     *
     * @param {DS.Model} model
     * @param {Transition} transition
     * @memberof ConsoleRoute
     */
    async afterModel(model, transition) {
        this.universe.callHooks('console:after-model', this.session, this.router, model, transition);
        removeBootLoader();
    }

    /**
     * Route did complete transition.
     *
     * @memberof ConsoleRoute
     */
    @action didTransition() {
        this.universe.callHooks('console:did-transition', this.session, this.router);
    }

    /**
     * Get the branding settings.
     *
     * @return {BrandModel}
     * @memberof ConsoleRoute
     */
    model() {
        return this.store.findRecord('brand', 1);
    }
}
