import environmentConfig from '@fleetbase/console/config/environment';
import emberGetConfig from 'ember-get-config';
import toBoolean from '@fleetbase/ember-core/utils/to-boolean';
import { set } from '@ember/object';
import { debug } from '@ember/debug';

// CRITICAL: The fetch service imports config from 'ember-get-config', NOT from
// '@fleetbase/console/config/environment'. We must update BOTH configs for
// runtime overrides to take effect properly.
const config = environmentConfig;

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
 * Cache key for localStorage
 */
const CACHE_KEY = 'fleetbase_runtime_config';
const CACHE_VERSION_KEY = 'fleetbase_runtime_config_version';
const CACHE_TTL = 1000 * 60 * 60; // 1 hour

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
 * CRITICAL: Updates BOTH environmentConfig AND emberGetConfig because
 * different parts of the app import config from different sources.
 *
 * @param {Object} rawConfig
 */
export function applyRuntimeConfig(rawConfig = {}) {
    Object.entries(rawConfig).forEach(([key, value]) => {
        const configPath = RUNTIME_CONFIG_MAP[key];

        if (configPath) {
            const coercedValue = coerceValue(key, value);
            // Update environment config (used by session service)
            set(config, configPath, coercedValue);
            // Update ember-get-config (used by fetch service)
            set(emberGetConfig, configPath, coercedValue);
            console.log(`[REEUP Config] Set ${configPath} = ${coercedValue}`);
        } else {
            debug(`[Runtime Config] Ignored unknown key: ${key}`);
        }
    });
}

/**
 * Get cached config from localStorage
 *
 * @returns {Object|null} Cached config or null
 */
function getCachedConfig() {
    try {
        const cached = localStorage.getItem(CACHE_KEY);
        const cachedVersion = localStorage.getItem(CACHE_VERSION_KEY);

        if (!cached || !cachedVersion) {
            return null;
        }

        // Application version has changed
        if (cachedVersion !== config.APP.version) {
            debug(`[Runtime Config] Version mismatch (cached: ${cachedVersion}, current: ${config.APP.version})`);
            return null;
        }

        const cacheData = JSON.parse(cached);
        const cacheAge = Date.now() - cacheData.timestamp;

        // Check if cache is still valid (within TTL)
        if (cacheAge > CACHE_TTL) {
            debug('[Runtime Config] Cache expired');
            return null;
        }

        debug(`[Runtime Config] Using cached config (age: ${Math.round(cacheAge / 1000)}s)`);
        return cacheData.config;
    } catch (e) {
        debug(`[Runtime Config] Failed to read cache: ${e.message}`);
        return null;
    }
}

/**
 * Save config to localStorage cache
 *
 * @param {Object} config Config object
 */
function setCachedConfig(runtimeConfig) {
    try {
        const cacheData = {
            config: runtimeConfig,
            timestamp: Date.now(),
        };
        localStorage.setItem(CACHE_KEY, JSON.stringify(cacheData));
        localStorage.setItem(CACHE_VERSION_KEY, config.APP.version);
        debug('[Runtime Config] Config cached to localStorage');
    } catch (e) {
        debug(`[Runtime Config] Failed to cache config: ${e.message}`);
    }
}

/**
 * Clear cached config
 *
 * @export
 */
export function clearRuntimeConfigCache() {
    try {
        localStorage.removeItem(CACHE_KEY);
        localStorage.removeItem(CACHE_VERSION_KEY);
        debug('[Runtime Config] Cache cleared');
    } catch (e) {
        debug(`[Runtime Config] Failed to clear cache: ${e.message}`);
    }
}

/**
 * Load and apply runtime config with localStorage caching.
 *
 * Strategy:
 * 1. Check localStorage cache first (instant, no HTTP request)
 * 2. If cache hit and valid, use it immediately
 * 3. If cache miss, fetch from server and cache the result
 * 4. Cache is valid for 1 hour
 *
 * @export
 * @return {Promise<void>}
 */
export default async function loadRuntimeConfig() {
    if (config.APP.disableRuntimeConfig) {
        return;
    }

    const isProduction = config?.environment === 'production';
    if (isProduction) {
        // Try cache first
        const cachedConfig = getCachedConfig();
        if (cachedConfig) {
            applyRuntimeConfig(cachedConfig);
            return;
        }
    }

    // Cache miss - fetch from server
    try {
        const startTime = performance.now();
        const configUrl = getConfigUrl();
        const response = await fetch(configUrl, {
            cache: 'no-cache',
            credentials: 'include'
        });

        if (!response.ok) {
            debug('[Runtime Config] No fleetbase.config.json found, using built-in config defaults');
            return;
        }

        const runtimeConfig = await response.json();
        const endTime = performance.now();

        debug(`[Runtime Config] Fetched from server in ${(endTime - startTime).toFixed(2)}ms`);

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
        setCachedConfig(runtimeConfig);
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
