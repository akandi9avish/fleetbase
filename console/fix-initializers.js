#!/usr/bin/env node

/**
 * Fix FleetOps Engine Instance Initializers
 *
 * This script patches instance initializers that are missing the required 'name' property.
 * Ember's instance initializer loader requires all initializers to have a name property.
 */

const fs = require('fs');
const path = require('path');

const FLEETOPS_ENGINE_PATH = path.join(
    __dirname,
    'node_modules/@fleetbase/fleetops-engine/addon/instance-initializers'
);

const fixes = [
    {
        file: 'register-leaflet-tracking-marker.js',
        name: 'register-leaflet-tracking-marker'
    },
    {
        file: 'register-leaflet-draw-control-layer.js',
        name: 'register-leaflet-draw-control-layer'
    }
];

console.log('[fix-initializers] Fixing FleetOps engine instance initializers...');

fixes.forEach(({ file, name }) => {
    const filePath = path.join(FLEETOPS_ENGINE_PATH, file);

    if (!fs.existsSync(filePath)) {
        console.warn(`[fix-initializers] Warning: ${file} not found, skipping...`);
        return;
    }

    let content = fs.readFileSync(filePath, 'utf8');

    // Check if already fixed
    if (content.includes(`name: '${name}'`)) {
        console.log(`[fix-initializers] ✓ ${file} already fixed`);
        return;
    }

    // Fix: Add name property to export default
    const pattern = /export default \{\s*initialize,\s*\};/;
    const replacement = `export default {\n    name: '${name}',\n    initialize,\n};`;

    if (pattern.test(content)) {
        content = content.replace(pattern, replacement);
        fs.writeFileSync(filePath, content, 'utf8');
        console.log(`[fix-initializers] ✓ Fixed ${file}`);
    } else {
        console.warn(`[fix-initializers] Warning: Could not find pattern in ${file}`);
    }
});

console.log('[fix-initializers] Done!');
