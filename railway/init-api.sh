#!/bin/bash
set -e

echo "🚀 Starting Fleetbase API deployment..."

# Wait for database to be ready
echo "⏳ Waiting for database connection..."
max_attempts=30
attempt=0
until php artisan mysql:createdb 2>/dev/null || [ $attempt -eq $max_attempts ]; do
  echo "Database not ready, waiting... (attempt $((attempt+1))/$max_attempts)"
  sleep 2
  attempt=$((attempt+1))
done

if [ $attempt -eq $max_attempts ]; then
  echo "❌ Database connection failed after $max_attempts attempts"
  exit 1
fi

# Run migrations
echo "📦 Running migrations..."
php artisan migrate --force

# Run sandbox migrations
echo "📦 Running sandbox migrations..."
php artisan sandbox:migrate --force || echo "⚠️  Sandbox migration failed (may not be initialized)"

# Seed database
echo "🌱 Seeding database..."
php artisan fleetbase:seed || echo "⚠️  Seeding skipped (may already be seeded)"

# Create permissions
echo "🔐 Creating permissions, policies, and roles..."
php artisan fleetbase:create-permissions

# Restart queue workers
echo "🔄 Restarting queue workers..."
php artisan queue:restart

# Sync scheduler
echo "⏰ Syncing scheduler..."
php artisan schedule-monitor:sync || echo "⚠️  Schedule monitor not configured"

# Clear caches
echo "🧹 Clearing caches..."
php artisan cache:clear
php artisan route:clear

# Optimize
echo "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache

# Initialize registry
echo "📋 Initializing Fleetbase registry..."
php artisan registry:init

echo "✅ Deployment preparation complete!"
