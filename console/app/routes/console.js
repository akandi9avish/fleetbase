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
            try {
                return await this.session.promiseCurrentUser(transition);
            } catch (error) {
                // Log the error but don't invalidate the session
                // This allows users to access the dashboard even if user data loading fails
                console.warn('[CONSOLE ROUTE] User loading failed, but keeping session active:', error);

                // Try to load user directly from currentUser service as fallback
                try {
                    await this.currentUser.promiseUser();
                } catch (fallbackError) {
                    console.warn('[CONSOLE ROUTE] Fallback user loading also failed:', fallbackError);
                }
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
