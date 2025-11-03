#!/bin/bash

###############################################################################
# Fleetbase Railway Deployment Script
# Deploys Fleetbase as a multi-service application to Railway
###############################################################################

set -e  # Exit on error

echo "=========================================="
echo "  Fleetbase Railway Deployment"
echo "=========================================="
echo

# Check if railway CLI is installed
if ! command -v railway &> /dev/null; then
    echo "❌ Railway CLI not found. Install it first:"
    echo "   npm install -g @railway/cli"
    exit 1
fi

echo "✅ Railway CLI found"
echo

# Check if we're in the right directory
if [ ! -f "docker-compose.yml" ]; then
    echo "❌ Error: docker-compose.yml not found"
    echo "   Please run this script from the fleetbase-official directory"
    exit 1
fi

echo "✅ Found docker-compose.yml"
echo

# Login check
echo "📋 Checking Railway authentication..."
if ! railway whoami &> /dev/null; then
    echo "🔐 Please login to Railway:"
    railway login
else
    echo "✅ Already logged in to Railway"
    railway whoami
fi

echo
echo "=========================================="
echo "  Deployment Options"
echo "=========================================="
echo
echo "1. Create NEW Railway service for Fleetbase"
echo "2. Link to EXISTING Railway project"
echo
read -p "Choose option (1 or 2): " option

case $option in
    1)
        echo
        echo "Creating new Railway service..."
        railway init
        ;;
    2)
        echo
        echo "Available projects:"
        railway list
        echo
        read -p "Enter project name: " project_name
        railway link "$project_name"
        ;;
    *)
        echo "❌ Invalid option"
        exit 1
        ;;
esac

echo
echo "=========================================="
echo "  Setting Environment Variables"
echo "=========================================="
echo

# Set critical environment variables
echo "Setting APP_NAME..."
railway variables set APP_NAME="REEUP Fleetbase"

echo "Setting ENVIRONMENT..."
railway variables set ENVIRONMENT="production"

echo "Setting REGISTRY_PREINSTALLED_EXTENSIONS..."
railway variables set REGISTRY_PREINSTALLED_EXTENSIONS="true"

echo "Setting OSRM_HOST..."
railway variables set OSRM_HOST="https://router.project-osrm.org"

echo
echo "✅ Environment variables set"
echo

echo "=========================================="
echo "  Deploying to Railway"
echo "=========================================="
echo

# Deploy
echo "🚀 Starting deployment..."
railway up

echo
echo "=========================================="
echo "  Deployment Complete!"
echo "=========================================="
echo

# Get deployment info
echo "📊 Deployment Info:"
railway status

echo
echo "=========================================="
echo "  Next Steps"
echo "=========================================="
echo
echo "1. Get Railway URLs:"
echo "   - Run: railway domain"
echo "   - Note the public URLs for API and Console"
echo
echo "2. Configure REEUP Backend (Railway):"
echo "   - Add FLEETBASE_API_URL=<your-api-url>"
echo "   - Add FLEETBASE_CONSOLE_URL=<your-console-url>"
echo
echo "3. Configure REEUP Frontend (Vercel):"
echo "   - Add NEXT_PUBLIC_FLEETBASE_API_URL=<your-api-url>"
echo "   - Add NEXT_PUBLIC_FLEETBASE_CONSOLE_URL=<your-console-url>"
echo "   - Set NEXT_PUBLIC_ENABLE_FLEETBASE=true"
echo
echo "4. Access Fleetbase Console:"
echo "   - Navigate to the console URL"
echo "   - Create admin account"
echo "   - Get API key from Settings → API"
echo
echo "5. Update Backend with API Key:"
echo "   - Add FLEETBASE_API_KEY=<your-api-key>"
echo "   - Add FLEETBASE_COMPANY_UUID=<your-company-uuid>"
echo
echo "=========================================="
