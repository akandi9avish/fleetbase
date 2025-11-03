#!/bin/bash

###############################################################################
# Fleetbase Simplified Railway Deployment Script
# Uses Kubernetes-inspired architecture with 4 services + 2 plugins
###############################################################################

set -e  # Exit on error

echo "==========================================="
echo "  Fleetbase Simplified Railway Deployment"
echo "  (Kubernetes-inspired Architecture)"
echo "==========================================="
echo

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Check if railway CLI is installed
if ! command -v railway &> /dev/null; then
    echo -e "${RED}❌ Railway CLI not found. Install it first:${NC}"
    echo "   npm install -g @railway/cli"
    exit 1
fi

echo -e "${GREEN}✅ Railway CLI found${NC}"
echo

# Check if we're in the right directory
if [ ! -f "Dockerfile.railway-main" ]; then
    echo -e "${RED}❌ Error: Dockerfile.railway-main not found${NC}"
    echo "   Please run this script from the fleetbase-official directory"
    exit 1
fi

echo -e "${GREEN}✅ Found Railway Dockerfiles${NC}"
echo

# Login check
echo "📋 Checking Railway authentication..."
if ! railway whoami &> /dev/null; then
    echo "🔐 Please login to Railway:"
    railway login
else
    echo -e "${GREEN}✅ Already logged in to Railway${NC}"
    railway whoami
fi

echo
echo "==========================================="
echo "  Project Setup"
echo "==========================================="
echo
echo "This deployment requires:"
echo "  - 1 Railway Project"
echo "  - 2 Database Plugins (MySQL, Redis)"
echo "  - 4 Services (Main, Worker, Scheduler, Socket)"
echo
echo -e "${YELLOW}💰 Estimated cost: ~\$20-30/month${NC}"
echo

read -p "Continue with deployment? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Deployment cancelled."
    exit 0
fi

echo
echo "==========================================="
echo "  Linking Railway Project"
echo "==========================================="
echo
echo "1. Use EXISTING REEUP project (recommended)"
echo "2. Create NEW project for Fleetbase"
echo
read -p "Choose option (1 or 2): " option

case $option in
    1)
        echo
        echo "Available projects:"
        railway list
        echo
        read -p "Enter project name (or press Enter for 'REEUP'): " project_name
        project_name=${project_name:-REEUP}
        railway link "$project_name"
        ;;
    2)
        echo
        echo "Creating new Railway project..."
        railway init
        ;;
    *)
        echo -e "${RED}❌ Invalid option${NC}"
        exit 1
        ;;
esac

echo
echo -e "${GREEN}✅ Project linked${NC}"
echo

echo "==========================================="
echo "  Database Plugins Setup"
echo "==========================================="
echo
echo -e "${YELLOW}⚠️  MANUAL STEP REQUIRED:${NC}"
echo
echo "Please add the following plugins to your Railway project:"
echo
echo "1. MySQL Plugin:"
echo "   - Go to Railway dashboard"
echo "   - Click 'New' → 'Database' → 'MySQL'"
echo "   - Wait for provisioning to complete"
echo
echo "2. Redis Plugin:"
echo "   - Click 'New' → 'Database' → 'Redis'"
echo "   - Wait for provisioning to complete"
echo
echo "These plugins will provide DATABASE_URL and REDIS_URL"
echo "environment variables automatically."
echo
read -p "Press Enter when both plugins are added... " -n 1 -r
echo

echo
echo "==========================================="
echo "  Environment Variables"
echo "==========================================="
echo
echo "Setting shared environment variables..."
echo

# Set shared environment variables
railway variables set APP_NAME="REEUP Fleetbase"
railway variables set ENVIRONMENT="production"
railway variables set REGISTRY_PREINSTALLED_EXTENSIONS="true"
railway variables set OSRM_HOST="https://router.project-osrm.org"
railway variables set REGISTRY_HOST="https://registry.fleetbase.io"
railway variables set CACHE_DRIVER="redis"
railway variables set QUEUE_CONNECTION="redis"
railway variables set BROADCAST_DRIVER="socketcluster"

echo -e "${GREEN}✅ Environment variables set${NC}"
echo

echo "==========================================="
echo "  Service Deployment"
echo "==========================================="
echo
echo -e "${YELLOW}⚠️  DEPLOYMENT INSTRUCTIONS:${NC}"
echo
echo "Railway requires manual service creation for each Dockerfile."
echo "Follow these steps in the Railway dashboard:"
echo
echo "For EACH service (Main, Worker, Scheduler, Socket):"
echo "  1. Click 'New' → 'Empty Service'"
echo "  2. Name the service (fleetbase-main, fleetbase-worker, etc.)"
echo "  3. Settings → Source → Connect to GitHub repo"
echo "  4. Settings → Build → Set Dockerfile Path:"
echo "     - Main: Dockerfile.railway-main"
echo "     - Worker: Dockerfile.railway-worker"
echo "     - Scheduler: Dockerfile.railway-scheduler"
echo "     - Socket: Dockerfile.railway-socket"
echo "  5. For Main and Socket services:"
echo "     - Settings → Networking → Generate Domain"
echo
echo "The services will deploy automatically after configuration."
echo
echo "See RAILWAY_SIMPLIFIED_DEPLOYMENT.md for detailed instructions."
echo

read -p "Press Enter when all services are configured... " -n 1 -r
echo

echo
echo "==========================================="
echo "  Deployment Complete!"
echo "==========================================="
echo
echo -e "${GREEN}✅ Fleetbase deployment configured${NC}"
echo
echo "📊 Check deployment status:"
echo "   railway status"
echo
echo "📝 View logs:"
echo "   railway logs --service fleetbase-main"
echo "   railway logs --service fleetbase-worker"
echo "   railway logs --service fleetbase-scheduler"
echo "   railway logs --service fleetbase-socket"
echo
echo "🌐 Get public URLs:"
echo "   railway domain"
echo
echo "==========================================="
echo "  Next Steps"
echo "==========================================="
echo
echo "1. Get Railway URLs:"
echo "   - Run: railway domain"
echo "   - Note the URLs for Main and Socket services"
echo
echo "2. Configure REEUP Backend (Railway):"
echo "   - Add FLEETBASE_API_URL=<main-service-url>"
echo "   - Add FLEETBASE_CONSOLE_URL=<main-service-url>"
echo
echo "3. Configure REEUP Frontend (Vercel):"
echo "   - Add NEXT_PUBLIC_FLEETBASE_API_URL=<main-service-url>"
echo "   - Add NEXT_PUBLIC_FLEETBASE_CONSOLE_URL=<main-service-url>"
echo "   - Set NEXT_PUBLIC_ENABLE_FLEETBASE=true"
echo
echo "4. Setup Fleetbase Admin:"
echo "   - Navigate to the Main service URL"
echo "   - Create admin account"
echo "   - Get API key from Settings → API"
echo
echo "5. Update Backend with API Credentials:"
echo "   - Add FLEETBASE_API_KEY=<your-api-key>"
echo "   - Add FLEETBASE_COMPANY_UUID=<your-company-uuid>"
echo
echo "==========================================="
echo
echo "📖 For detailed instructions, see:"
echo "   RAILWAY_SIMPLIFIED_DEPLOYMENT.md"
echo
