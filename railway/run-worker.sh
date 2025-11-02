#!/bin/bash
set -e

echo "🔧 Starting Fleetbase Queue Worker..."
echo "Queue Connection: ${QUEUE_CONNECTION:-redis}"
echo "Redis Host: ${REDIS_HOST:-unknown}"

# Start Laravel queue worker with production settings
exec php artisan queue:work \
  --verbose \
  --tries=3 \
  --timeout=300 \
  --max-jobs=1000 \
  --max-time=3600 \
  --memory=512
