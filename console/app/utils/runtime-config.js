import config from '@fleetbase/console/config/environment';
import toBoolean from '@fleetbase/ember-core/utils/to-boolean';
import { set } from '@ember/object';
import { debug } from '@ember/debug';

/**
 * REEUP BFF Integration - Iframe Detection
 * =========================================
 * When console runs in REEUP iframe, config must be fetched from parent origin
 * to get the correct API_HOST pointing to BFF proxy.
 */
const REEUP_PARENT_ORIGINS = [
    'https://www.reeup.co',
    'https://reeup.co',
    'https://console.reeup.co',  // Self for standalone mode
    'https://reeup.vercel.app',
    'https://reeup-forest.vercel.app',
    'http://localhost:3000',
];

function isInIframe() {
    try {
        return window.self !== window.top;
    } catch (e) {
        return true; // Cross-origin iframe
    }
}

function getParentOrigin() {
    if (!isInIframe()) return null;
    if (document.referrer) {
        try {
            return new URL(document.referrer).origin;
        } catch (e) {}
    }
    return null;
}

function isReeupParent(parentOrigin) {
    if (!parentOrigin) return false;
    return REEUP_PARENT_ORIGINS.some(allowed => parentOrigin.startsWith(allowed));
}

function getConfigUrl() {
    const timestamp = Date.now();

    // Priority 1: Check for reeupConfigUrl query parameter (most reliable)
    // This is passed from the parent REEUP app via iframe src
    if (typeof window !== 'undefined') {
        const urlParams = new URLSearchParams(window.location.search);
        const reeupConfigUrl = urlParams.get('reeupConfigUrl');
        if (reeupConfigUrl && isReeupParent(reeupConfigUrl)) {
            console.log('[REEUP Config] Using reeupConfigUrl param:', reeupConfigUrl);
            return `${reeupConfigUrl}/fleetbase.config.json?_t=${timestamp}`;
        }
    }

    // Priority 2: Fallback to referrer detection (may not work in all browsers)
    if (isInIframe()) {
        const parentOrigin = getParentOrigin();
        if (isReeupParent(parentOrigin)) {
            console.log('[REEUP Config] Using referrer-based parent:', parentOrigin);
            return `${parentOrigin}/fleetbase.config.json?_t=${timestamp}`;
        }
    }

    // Priority 3: Local config (standalone mode)
    return `/fleetbase.config.json?_t=${timestamp}`;
}

/**
 * Maps allowed runtime keys to internal config paths.
 */
const RUNTIME_CONFIG_MAP = {
    API_HOST: 'API.host',
    API_NAMESPACE: 'API.namespace',
    SOCKETCLUSTER_PATH: 'socket.path',
    SOCKETCLUSTER_HOST: 'socket.hostname',
    SOCKETCLUSTER_SECURE: 'socket.secure',
    SOCKETCLUSTER_PORT: 'socket.port',
    OSRM_HOST: 'osrm.host',
    EXTENSIONS: 'APP.extensions',
    reeup: 'reeup',
};

/**
 * Coerce and sanitize runtime config values based on key.
 *
 * @param {String} key
 * @param {*} value
 * @return {*}
 */
function coerceValue(key, value) {
    switch (key) {
        case 'SOCKETCLUSTER_PORT':
            return parseInt(value, 10);

        case 'SOCKETCLUSTER_SECURE':
            return toBoolean(value);

        case 'EXTENSIONS':
            return typeof value === 'string' ? value.split(',') : Array.from(value);

        default:
            return value;
    }
}

/**
 * Apply runtime config overrides based on strict allowlist mapping.
 *
 * @param {Object} rawConfig
 */
export function applyRuntimeConfig(rawConfig = {}) {
    Object.entries(rawConfig).forEach(([key, value]) => {
        const configPath = RUNTIME_CONFIG_MAP[key];

        if (configPath) {
            const coercedValue = coerceValue(key, value);
            set(config, configPath, coercedValue);
        } else {
            debug(`[runtime-config] Ignored unknown key: ${key}`);
        }
    });
}

/**
 * Load and apply runtime config.
 *
 * @export
 * @return {void}
 */
export default async function loadRuntimeConfig() {
    if (config.APP.disableRuntimeConfig) {
        return;
    }

    try {
        const configUrl = getConfigUrl();
        const response = await fetch(configUrl, {
            cache: 'no-cache',
            credentials: 'include'
        });

        if (!response.ok) {
            debug('No fleetbase.config.json found, using built-in config defaults');
            return;
        }

        const runtimeConfig = await response.json();

        // Store iframe context for session service
        // Check URL param first (most reliable), then referrer
        const urlParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
        const reeupConfigUrl = urlParams?.get('reeupConfigUrl');

        if (reeupConfigUrl || isInIframe()) {
            runtimeConfig._isInIframe = isInIframe();
            runtimeConfig._reeupConfigUrl = reeupConfigUrl || null;
            runtimeConfig._parentOrigin = reeupConfigUrl || getParentOrigin();
            runtimeConfig._isReeupEmbedded = isReeupParent(runtimeConfig._parentOrigin);
            console.log('[REEUP Config] Iframe context stored:', {
                _isInIframe: runtimeConfig._isInIframe,
                _reeupConfigUrl: runtimeConfig._reeupConfigUrl,
                _parentOrigin: runtimeConfig._parentOrigin,
                _isReeupEmbedded: runtimeConfig._isReeupEmbedded
            });
        }

        applyRuntimeConfig(runtimeConfig);
    } catch (e) {
        debug(`Failed to load runtime config : ${e.message}`);
        // Fallback to local config if cross-origin fails
        if (isInIframe()) {
            console.log('[REEUP Config] Cross-origin fetch failed, trying local config');
            try {
                const localResponse = await fetch(`/fleetbase.config.json?_t=${Date.now()}`, { cache: 'no-cache' });
                if (localResponse.ok) {
                    applyRuntimeConfig(await localResponse.json());
                }
            } catch (localError) {
                debug(`Failed to load local fallback config: ${localError.message}`);
            }
        }
    }
}
