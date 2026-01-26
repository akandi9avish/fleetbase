#!/bin/bash
set -e

echo "🚀 Starting Fleetbase API deployment for Railway..."
echo "ℹ️  Railway MySQL plugin handles database creation automatically"

# Wait for DNS resolution and services to be fully ready
echo "⏳ Waiting for services to be fully available..."
sleep 5

# Test database connection before proceeding
echo "🔍 Testing database connection..."
max_attempts=30
attempt=1
while [ $attempt -le $max_attempts ]; do
    if php artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null; then
        echo "✅ Database connection successful"
        break
    fi
    echo "⏳ Waiting for database... (attempt $attempt/$max_attempts)"
    sleep 2
    attempt=$((attempt + 1))
done

if [ $attempt -gt $max_attempts ]; then
    echo "❌ Failed to connect to database after $max_attempts attempts"
    exit 1
fi

# Test Redis connection
echo "🔍 Testing Redis connection..."
attempt=1
while [ $attempt -le $max_attempts ]; do
    if php artisan tinker --execute="Cache::store('redis')->get('test');" 2>/dev/null; then
        echo "✅ Redis connection successful"
        break
    fi
    echo "⏳ Waiting for Redis... (attempt $attempt/$max_attempts)"
    sleep 2
    attempt=$((attempt + 1))
done

if [ $attempt -gt $max_attempts ]; then
    echo "❌ Failed to connect to Redis after $max_attempts attempts"
    exit 1
fi

# Run migrations with isolation lock (prevents concurrent migrations in multi-service setup)
echo "📦 Running migrations..."
php artisan migrate --force --isolated

# Run sandbox migrations
echo "📦 Running sandbox migrations..."
php artisan sandbox:migrate --force || echo "⚠️  Sandbox migration failed (may not be initialized)"

# Seed database
echo "🌱 Seeding database..."
php artisan fleetbase:seed || echo "⚠️  Seeding skipped (may already be seeded)"

# Create permissions
echo "🔐 Creating permissions, policies, and roles..."
php artisan fleetbase:create-permissions

# Seed REEUP custom roles
echo "🌱 Seeding REEUP custom IAM roles..."
php artisan reeup:seed-roles || echo "⚠️  REEUP roles seeding failed (may need manual intervention)"

# ============================================
# AUTOMATED REEUP ADMIN ACCOUNT SETUP
# ============================================
# Creates admin user using environment variables:
# - FLEETBASE_ADMIN_EMAIL (required)
# - FLEETBASE_ADMIN_PASSWORD (required)
# - FLEETBASE_MAIN_COMPANY_UUID (optional, for company association)
# ============================================

echo "👤 Setting up REEUP admin account..."

if [ -n "$FLEETBASE_ADMIN_EMAIL" ] && [ -n "$FLEETBASE_ADMIN_PASSWORD" ]; then
    echo "🔍 Checking if admin user exists: $FLEETBASE_ADMIN_EMAIL"

    # Create or update admin user using Fleetbase's ReeupUserController pattern
    php artisan tinker --execute="
        use Fleetbase\Models\User;
        use Fleetbase\Models\Company;
        use Spatie\Permission\Models\Role;
        use Illuminate\Support\Facades\Hash;

        \$email = env('FLEETBASE_ADMIN_EMAIL');
        \$password = env('FLEETBASE_ADMIN_PASSWORD');
        \$companyUuid = env('FLEETBASE_MAIN_COMPANY_UUID');

        // Find or create admin user
        \$user = User::where('email', \$email)->first();

        if (!\$user) {
            echo \"Creating new admin user: \$email\\n\";

            // Create the user
            \$user = User::create([
                'name' => 'REEUP Admin',
                'email' => \$email,
                'password' => Hash::make(\$password),
                'email_verified_at' => now(),
                'timezone' => 'America/Los_Angeles',
                'type' => 'user',
                'status' => 'active',
            ]);

            echo \"✅ Created admin user: \" . \$user->uuid . \"\\n\";
        } else {
            echo \"Admin user already exists: \" . \$user->uuid . \"\\n\";

            // Update password to match environment (for consistency)
            \$user->password = Hash::make(\$password);
            \$user->email_verified_at = \$user->email_verified_at ?? now();
            \$user->save();
            echo \"✅ Updated admin user password\\n\";
        }

        // Associate with main company if UUID is provided
        if (\$companyUuid) {
            \$company = Company::where('uuid', \$companyUuid)->first();
            if (\$company) {
                if (!\$user->company_uuid) {
                    \$user->company_uuid = \$company->uuid;
                    \$user->save();
                    echo \"✅ Associated admin with company: \" . \$company->name . \"\\n\";
                }

                // Also ensure user is a member of the company
                if (!\$company->users->contains(\$user->id)) {
                    \$company->users()->attach(\$user->id, [
                        'status' => 'active',
                        'created_at' => now(),
                    ]);
                    echo \"✅ Added admin as company member\\n\";
                }
            } else {
                echo \"⚠️  Company not found with UUID: \$companyUuid\\n\";
            }
        }

        // Assign Administrator role
        \$adminRole = Role::where('name', 'Administrator')->where('guard_name', 'sanctum')->first();
        if (\$adminRole) {
            if (!\$user->hasRole('Administrator')) {
                \$user->assignRole(\$adminRole);
                echo \"✅ Assigned Administrator role to admin user\\n\";
            } else {
                echo \"Admin user already has Administrator role\\n\";
            }
        } else {
            echo \"⚠️  Administrator role not found - run fleetbase:create-permissions first\\n\";
        }

        echo \"\\n🎉 Admin setup complete for: \" . \$user->email . \"\\n\";
    " && echo "✅ Admin account setup complete" || echo "⚠️  Admin account setup failed (may need manual intervention)"
else
    echo "⚠️  Skipping admin setup: FLEETBASE_ADMIN_EMAIL or FLEETBASE_ADMIN_PASSWORD not set"
    echo "    Set these environment variables to enable automated admin creation"
fi

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
