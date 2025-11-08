#!/bin/bash

# =============================================================================
# LOCAL MIGRATION TEST SCRIPT
# =============================================================================
# This script tests Fleetbase migrations locally using the EXACT same MySQL
# version as Railway, to identify which migrations will fail before deploying.
#
# Usage: ./test-migrations-locally.sh
# =============================================================================

set -e  # Exit on error

echo ""
echo "🧪 FLEETBASE MIGRATION TEST - LOCAL SIMULATION"
echo "=============================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
MYSQL_VERSION="8.0"  # Railway uses MySQL 8
CONTAINER_NAME="fleetbase-test-mysql"
MYSQL_ROOT_PASSWORD="test_password_123"
MYSQL_DATABASE="fleetbase_test"
MYSQL_PORT="3399"  # Non-standard port to avoid conflicts

# =============================================================================
# Step 1: Clean up any existing test container
# =============================================================================
echo "📦 Cleaning up any existing test containers..."
docker rm -f $CONTAINER_NAME 2>/dev/null || true
echo ""

# =============================================================================
# Step 2: Start fresh MySQL container (matching Railway)
# =============================================================================
echo "🚀 Starting MySQL $MYSQL_VERSION container (matching Railway)..."
docker run -d \
    --name $CONTAINER_NAME \
    -e MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD \
    -e MYSQL_DATABASE=$MYSQL_DATABASE \
    -p $MYSQL_PORT:3306 \
    mysql:$MYSQL_VERSION \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_unicode_ci

echo "⏳ Waiting for MySQL to be ready..."
sleep 15

# Test connection
docker exec $CONTAINER_NAME mysql -uroot -p$MYSQL_ROOT_PASSWORD -e "SELECT VERSION();" 2>/dev/null
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ MySQL is ready${NC}"
else
    echo -e "${RED}❌ MySQL failed to start${NC}"
    exit 1
fi
echo ""

# =============================================================================
# Step 3: Update .env to point to test database
# =============================================================================
echo "⚙️  Configuring test environment..."
cd api

# Backup existing .env
cp .env .env.backup 2>/dev/null || true

# Create test .env
cat > .env.test << EOF
APP_NAME=Fleetbase
APP_ENV=local
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=$MYSQL_PORT
DB_DATABASE=$MYSQL_DATABASE
DB_USERNAME=root
DB_PASSWORD=$MYSQL_ROOT_PASSWORD

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120
EOF

# Use test env
cp .env.test .env

echo -e "${GREEN}✅ Test environment configured${NC}"
echo ""

# =============================================================================
# Step 4: Install dependencies if needed
# =============================================================================
if [ ! -d "vendor" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
    echo ""
fi

# =============================================================================
# Step 5: Run migrations and capture ALL output
# =============================================================================
echo "🔄 Running migrations with FULL logging..."
echo "=============================================="
echo ""

# Create log file
LOG_FILE="../migration-test-$(date +%Y%m%d-%H%M%S).log"

# Run migrations with verbose output
php artisan migrate:fresh --force --verbose 2>&1 | tee $LOG_FILE

MIGRATION_EXIT_CODE=${PIPESTATUS[0]}

echo ""
echo "=============================================="

# =============================================================================
# Step 6: Analyze results
# =============================================================================
if [ $MIGRATION_EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✅ ALL MIGRATIONS PASSED!${NC}"
    echo ""
    echo "📊 Analyzing database schema..."

    # Check for tables without UNIQUE on uuid columns that have FKs
    docker exec $CONTAINER_NAME mysql -uroot -p$MYSQL_ROOT_PASSWORD $MYSQL_DATABASE << 'EOSQL'
SELECT
    'Checking FK constraints...' as status;

SELECT
    kcu.TABLE_NAME as child_table,
    kcu.COLUMN_NAME as child_column,
    kcu.REFERENCED_TABLE_NAME as parent_table,
    kcu.REFERENCED_COLUMN_NAME as parent_column,
    CASE
        WHEN tc.CONSTRAINT_TYPE IS NULL THEN '❌ MISSING UNIQUE'
        ELSE '✅ HAS UNIQUE'
    END as status
FROM information_schema.KEY_COLUMN_USAGE kcu
LEFT JOIN (
    SELECT tc2.TABLE_NAME, kcu2.COLUMN_NAME, tc2.CONSTRAINT_TYPE
    FROM information_schema.TABLE_CONSTRAINTS tc2
    JOIN information_schema.KEY_COLUMN_USAGE kcu2
        ON tc2.CONSTRAINT_NAME = kcu2.CONSTRAINT_NAME
        AND tc2.TABLE_SCHEMA = kcu2.TABLE_SCHEMA
    WHERE tc2.CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE')
) tc
    ON kcu.REFERENCED_TABLE_NAME = tc.TABLE_NAME
    AND kcu.REFERENCED_COLUMN_NAME = tc.COLUMN_NAME
WHERE kcu.TABLE_SCHEMA = '$MYSQL_DATABASE'
    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY status, parent_table, parent_column;
EOSQL

else
    echo -e "${RED}❌ MIGRATIONS FAILED!${NC}"
    echo ""
    echo "Failure details saved to: $LOG_FILE"
    echo ""

    # Extract and highlight the specific error
    echo "🔍 Error Analysis:"
    echo "===================="
    grep -A 10 "SQLSTATE\|ERROR\|FAILED" $LOG_FILE | head -30
    echo ""

    # Check if it's the FK constraint error
    if grep -q "6125.*Missing unique key" $LOG_FILE; then
        echo -e "${YELLOW}⚠️  Detected FK constraint error (Error 6125)${NC}"
        echo ""
        echo "Tables with missing UNIQUE constraints on uuid columns:"

        # Extract the specific table/column from error
        grep -oP "constraint '\K[^']+(?=')" $LOG_FILE | while read constraint; do
            echo "  - $constraint"
        done
    fi
fi

echo ""

# =============================================================================
# Step 7: Keep container running or clean up
# =============================================================================
echo ""
echo "Options:"
echo "  1. Keep container running for manual inspection"
echo "  2. Clean up and stop container"
echo ""
read -p "Enter choice (1 or 2): " choice

if [ "$choice" = "2" ]; then
    echo "🧹 Cleaning up..."
    docker rm -f $CONTAINER_NAME
    rm .env.test
    [ -f .env.backup ] && mv .env.backup .env
    echo -e "${GREEN}✅ Cleanup complete${NC}"
else
    echo ""
    echo "📋 Test database is still running:"
    echo "   Container: $CONTAINER_NAME"
    echo "   Port: $MYSQL_PORT"
    echo "   Database: $MYSQL_DATABASE"
    echo "   Root Password: $MYSQL_ROOT_PASSWORD"
    echo ""
    echo "To connect:"
    echo "   mysql -h127.0.0.1 -P$MYSQL_PORT -uroot -p$MYSQL_ROOT_PASSWORD $MYSQL_DATABASE"
    echo ""
    echo "To stop and remove:"
    echo "   docker rm -f $CONTAINER_NAME"
    echo ""
    echo "To restore .env:"
    echo "   mv .env.backup .env"
fi

echo ""
echo "Log file: $LOG_FILE"
echo ""
