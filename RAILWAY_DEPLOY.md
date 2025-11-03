# Fleetbase Railway Deployment Guide

## Overview
Deploy Fleetbase as a separate Railway service that integrates with REEUP backend.

## Railway Service Structure

Railway will create **ONE service** with multiple containers defined in docker-compose.yml:
- Fleetbase HTTP (port 8000) - Main API endpoint
- Fleetbase Console (port 4200) - Admin UI
- Fleetbase Socket (port 38000) - Real-time WebSocket

Internal services (not publicly exposed):
- MySQL Database (internal)
- Redis Cache (internal)
- Queue Worker (internal)
- Scheduler (internal)

## Deployment Steps

### 1. Navigate to Fleetbase Directory
```bash
cd /Users/avishkandi/Desktop/reeup_main/microservices/fleetbase-official
```

### 2. Deploy to Railway
```bash
# Login to Railway
railway login

# Create new service in existing project
railway link

# Deploy Fleetbase
railway up
```

### 3. Configure Public Domains
After deployment, Railway will provide domain(s). Configure:
- API Domain: `fleetbase-api-production.up.railway.app`
- Console Domain: `fleetbase-console-production.up.railway.app`

### 4. Set Environment Variables in Railway Dashboard

**Critical Variables**:
```bash
APP_NAME=REEUP Fleetbase
ENVIRONMENT=production
APP_URL=https://fleetbase-console-production.up.railway.app
SESSION_DOMAIN=fleetbase-console-production.up.railway.app
REGISTRY_PREINSTALLED_EXTENSIONS=true
OSRM_HOST=https://router.project-osrm.org
```

**Auto-configured by docker-compose**:
- DATABASE_URL (internal mysql connection)
- REDIS_URL (internal cache connection)
- QUEUE_CONNECTION=redis
- CACHE_DRIVER=redis
- BROADCAST_DRIVER=socketcluster

## Integration with REEUP Backend

### Backend Environment Variables (Railway)
Add to backend_api Railway service:

```bash
FLEETBASE_API_URL=https://fleetbase-api-production.up.railway.app
FLEETBASE_CONSOLE_URL=https://fleetbase-console-production.up.railway.app
FLEETBASE_API_KEY=<get from Fleetbase after deployment>
FLEETBASE_COMPANY_UUID=<get from Fleetbase after deployment>
```

### Frontend Configuration (Vercel)
Add to Vercel production environment:

```bash
NEXT_PUBLIC_FLEETBASE_API_URL=https://fleetbase-api-production.up.railway.app
NEXT_PUBLIC_FLEETBASE_CONSOLE_URL=https://fleetbase-console-production.up.railway.app
NEXT_PUBLIC_ENABLE_FLEETBASE=true
```

## Post-Deployment Configuration

### 1. Access Fleetbase Console
Navigate to: `https://fleetbase-console-production.up.railway.app`

### 2. Create Admin Account
First-time setup will prompt you to create an admin account.

### 3. Get API Credentials
- Login to Fleetbase Console
- Navigate to Settings → API
- Create API key for REEUP integration
- Copy the API key and company UUID

### 4. Update Backend with Credentials
Update Railway environment variables with actual API key and UUID.

## Health Checks

Verify deployment:
```bash
# Check API health
curl https://fleetbase-api-production.up.railway.app/health

# Check Console
curl https://fleetbase-console-production.up.railway.app
```

## Next Steps After Deployment

1. Update `frontend/next.config.js` to use environment variables
2. Deploy new frontend build to Vercel
3. Test Fleetbase integration from REEUP frontend
4. Verify user sync from REEUP → Fleetbase
5. Test delivery/logistics features
