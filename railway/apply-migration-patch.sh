#!/bin/bash
# Apply migration patch for idempotent permissions table creation
# This script should be run during Docker build

set -e

echo "🔧 Applying migration patch for permissions table..."

MIGRATION_FILE="/fleetbase/api/vendor/fleetbase/core-api/migrations/2023_04_25_094304_create_permissions_table.php"
PATCH_FILE="/tmp/migration-patch-permissions.php"

if [ ! -f "$MIGRATION_FILE" ]; then
    echo "❌ Original migration file not found at: $MIGRATION_FILE"
    echo "📍 Searching for migration file..."
    find /fleetbase -name "2023_04_25_094304_create_permissions_table.php" 2>/dev/null || true
    exit 1
fi

if [ ! -f "$PATCH_FILE" ]; then
    echo "❌ Patch file not found at: $PATCH_FILE"
    exit 1
fi

# Backup original migration
cp "$MIGRATION_FILE" "${MIGRATION_FILE}.original"
echo "📦 Backed up original migration"

# Apply patch (replace with patched version)
cp "$PATCH_FILE" "$MIGRATION_FILE"
echo "✅ Applied idempotent migration patch"

echo "🎉 Migration patch applied successfully!"
