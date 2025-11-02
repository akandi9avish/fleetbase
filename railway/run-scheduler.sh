#!/bin/bash
set -e

echo "⏰ Starting Fleetbase Scheduler..."
echo "Running Laravel scheduler every minute..."

# Infinite loop running scheduler every 60 seconds
while true; do
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] Running scheduled tasks..."
  php artisan schedule:run --verbose --no-interaction

  # Sleep for 60 seconds
  sleep 60
done
