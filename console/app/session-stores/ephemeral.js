import BaseStore from 'ember-simple-auth/session-stores/base';
import { tracked } from '@glimmer/tracking';

/**
 * Ephemeral Session Store for BFF (Backend-for-Frontend) Mode
 * ============================================================
 *
 * This session store keeps session data in memory only, without persisting
 * to localStorage or sessionStorage. This is necessary for BFF mode because:
 *
 * 1. BFF authentication tokens are managed server-side, not in browser
 * 2. Cross-origin iframe localStorage access is blocked by browsers
 * 3. Session state doesn't need to survive page reloads in BFF mode
 *    (BFF proxy re-authenticates automatically)
 *
 * Why Default Stores Fail in iframe:
 * - localStorage: Blocked by browser Same-Origin Policy in cross-origin iframes
 * - sessionStorage: Also blocked in cross-origin iframes
 * - adaptive: Falls back to localStorage, same issue
 * - cookie: Works but unnecessary complexity for BFF mode
 *
 * This ephemeral store solves the immediate logout issue by:
 * - Not attempting any storage.setItem() calls (which fail silently)
 * - Not listening to storage events (which never fire in blocked contexts)
 * - Keeping session data in memory for the current page lifecycle only
 *
 * @extends BaseStore
 */
export default class EphemeralStore extends BaseStore {
    @tracked _data = {};

    /**
     * Persist session data (no-op in ephemeral mode, just store in memory)
     *
     * @param {Object} data - Session data to persist
     * @return {Promise<void>}
     */
    async persist(data) {
        console.log('[Ephemeral Store] persist() called with data:', data);
        this._data = data || {};
        console.log('[Ephemeral Store] Data stored in memory (no localStorage used)');
    }

    /**
     * Restore session data from memory
     *
     * @return {Promise<Object>}
     */
    async restore() {
        console.log('[Ephemeral Store] restore() called');
        console.log('[Ephemeral Store] Returning data from memory:', this._data);
        return this._data;
    }

    /**
     * Clear session data from memory
     *
     * @return {Promise<void>}
     */
    async clear() {
        console.log('[Ephemeral Store] clear() called');
        this._data = {};
        console.log('[Ephemeral Store] Memory cleared');
    }
}
